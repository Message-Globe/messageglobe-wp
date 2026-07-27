<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Sms;

use MessageGlobe\WP\Core\ClientFactory;
use MessageGlobe\WP\Core\Logger;
use MessageGlobe\WP\Core\Module;
use MessageGlobe\WP\Core\Settings;
use MessageGlobe\Sms\SmsMessage;
use MessageGlobe\Sms\SmsResult;
use MessageGlobe\Exception\MessageGlobeExceptionInterface;

defined('ABSPATH') || exit;

/**
 * Sends SMS through the MessageGlobe REST API, with an asynchronous queue so
 * that page requests (checkout, registration, …) never block on the network.
 *
 * Callers should prefer {@see notify()} — it respects the "SMS enabled"
 * setting and queues the message. {@see send()} performs a synchronous send
 * and is used by the queue processor and the admin "send test" action.
 */
final class SmsService implements Module
{
    public const CRON_HOOK = 'messageglobe_process_sms_queue';

    private const MAX_ATTEMPTS = 3;
    private const BATCH_SIZE   = 20;

    private ClientFactory $clients;

    private Settings $settings;

    private Logger $logger;

    public function __construct(ClientFactory $clients, Settings $settings, Logger $logger)
    {
        $this->clients  = $clients;
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    public static function queue_table(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'messageglobe_sms_queue';
    }

    public function register(): void
    {
        add_action(self::CRON_HOOK, [$this, 'process_queue']);
    }

    /**
     * True when SMS is switched on and the API is reachable.
     */
    public function is_available(): bool
    {
        return $this->settings->bool('sms_enabled') && $this->clients->is_ready();
    }

    /**
     * High-level entry point used by feature modules: respects the global
     * toggle and hands the message to the queue. Never throws.
     *
     * @param array<string, mixed> $args See {@see send()} for accepted keys.
     */
    public function notify(array $args): void
    {
        if (! $this->is_available()) {
            return;
        }

        $this->queue($args);
    }

    /**
     * Persist a message for asynchronous delivery and make sure the processor
     * is scheduled to run shortly.
     *
     * @param array<string, mixed> $args
     */
    public function queue(array $args): void
    {
        global $wpdb;

        $wpdb->insert(
            self::queue_table(),
            [
                'created_at' => current_time('mysql'),
                'status'     => 'pending',
                'attempts'   => 0,
                'payload'    => (string) wp_json_encode($args),
            ],
            ['%s', '%s', '%d', '%s']
        );

        $recipient = $this->recipientLabel($args['to'] ?? '');
        $this->logger->sms(Logger::STATUS_QUEUED, $recipient, (string) ($args['message'] ?? ''));

        if (! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK);
        }
    }

    /**
     * Process a batch of pending queued messages. Hooked to the cron event.
     */
    public function process_queue(): void
    {
        global $wpdb;

        $table = self::queue_table();
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d",
                'pending',
                self::BATCH_SIZE
            ),
            ARRAY_A
        );

        if (empty($rows)) {
            return;
        }

        $sent    = 0;
        $errored = 0;

        foreach ($rows as $row) {
            /** @var array<string, mixed> $args */
            $args = json_decode((string) $row['payload'], true) ?: [];

            try {
                $this->send($args);
                $sent++;
                $wpdb->update(
                    $table,
                    ['status' => 'sent', 'attempts' => (int) $row['attempts'] + 1, 'sent_at' => current_time('mysql'), 'error' => null],
                    ['id' => (int) $row['id']],
                    ['%s', '%d', '%s', '%s'],
                    ['%d']
                );
            } catch (\Throwable $e) {
                $errored++;
                $attempts = (int) $row['attempts'] + 1;
                $failed   = $attempts >= self::MAX_ATTEMPTS;

                $wpdb->update(
                    $table,
                    ['status' => $failed ? 'failed' : 'pending', 'attempts' => $attempts, 'error' => $e->getMessage()],
                    ['id' => (int) $row['id']],
                    ['%s', '%d', '%s'],
                    ['%d']
                );

                if ($failed) {
                    $this->logger->sms(
                        Logger::STATUS_FAILED,
                        $this->recipientLabel($args['to'] ?? ''),
                        (string) ($args['message'] ?? ''),
                        ['error' => $e->getMessage()]
                    );
                }
            }
        }

        // More waiting? Come back for the next batch.
        $remaining = (int) $wpdb->get_var(
            $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'pending')
        );

        // Record a run summary for the background-runs log.
        $this->logger->cron(
            Logger::STATUS_RAN,
            sprintf(
                /* translators: 1: processed, 2: sent, 3: failed, 4: remaining. */
                __('SMS queue drained: %1$d processed, %2$d sent, %3$d failed, %4$d remaining.', 'messageglobe'),
                count($rows),
                $sent,
                $errored,
                $remaining
            ),
            ['processed' => count($rows), 'sent' => $sent, 'failed' => $errored, 'remaining' => $remaining]
        );

        if ($remaining > 0 && ! wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_single_event(time() + 30, self::CRON_HOOK);
        }
    }

    /**
     * Send one message synchronously and return the first result.
     *
     * Accepted $args keys:
     *   - to (string|string[])   recipient number(s), required
     *   - message (string)       body, required
     *   - sender_id (string)     overrides the default sender
     *   - gateway ('HQ'|'LQ')    overrides the default gateway
     *   - type ('auto'|'plain')  'auto' (default) picks unicode when needed
     *   - dlr_callback_url (string)
     *
     * @param array<string, mixed> $args
     *
     * @throws MessageGlobeExceptionInterface On API/validation/transport error.
     * @throws \RuntimeException              When the client is not configured.
     */
    public function send(array $args): SmsResult
    {
        $client = $this->clients->sms();
        if ($client === null) {
            throw new \RuntimeException('MessageGlobe SMS client is not configured.');
        }

        $recipients = $this->normaliseRecipients($args['to'] ?? '');
        if ($recipients === '') {
            throw new \RuntimeException('No SMS recipient provided.');
        }

        $body = (string) ($args['message'] ?? '');
        $sender = (string) ($args['sender_id'] ?? $this->settings->text('default_sender_id'));
        $gateway = strtoupper((string) ($args['gateway'] ?? $this->settings->text('default_gateway')));
        $type = (string) ($args['type'] ?? 'auto');

        $message = new SmsMessage();
        $message->to($recipients);

        if ($gateway === 'LQ') {
            $message->lowQuality();
        } else {
            $message->highQuality();
            if ($sender !== '') {
                $message->from($sender);
            }
        }

        if ($type === 'plain') {
            $message->message($body);
        } else {
            $message->messageAutoType($body);
        }

        if (! empty($args['dlr_callback_url'])) {
            $message->dlrCallbackUrl((string) $args['dlr_callback_url']);
        }

        $response = $client->send($message);
        $result   = $response->first();

        $this->logger->sms(
            Logger::STATUS_SENT,
            (string) ($result->recipient() ?? $recipients),
            $body,
            ['message_id' => $result->messageId(), 'status' => $result->status(), 'cost' => $result->cost()]
        );

        return $result;
    }

    /**
     * @param mixed $to
     */
    private function normaliseRecipients($to): string
    {
        if (is_array($to)) {
            $to = implode(',', array_map('strval', $to));
        }

        // Collapse whitespace and drop empty segments.
        $parts = array_filter(array_map('trim', explode(',', (string) $to)), static fn ($n) => $n !== '');

        return implode(',', $parts);
    }

    /**
     * @param mixed $to
     */
    private function recipientLabel($to): string
    {
        return $this->normaliseRecipients($to);
    }
}
