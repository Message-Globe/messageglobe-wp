<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Users;

use MessageGlobe\WP\Core\ClientFactory;
use MessageGlobe\WP\Core\Logger;
use MessageGlobe\WP\Core\Module;
use MessageGlobe\WP\Core\Settings;
use MessageGlobe\Contacts\ContactRequest;

defined('ABSPATH') || exit;

/**
 * Syncs WordPress users into a MessageGlobe list (group).
 *
 * The admin picks a target list and the user roles to sync. When a user with a
 * matching role registers, has that role added, or updates their profile, their
 * email + phone are added to the list as a contact. The work is queued via
 * WP-Cron so registration/profile saves never block on the API, and a per-user
 * meta flag makes the sync idempotent (a user is added to a given list once).
 */
final class ContactSync implements Module
{
    public const CRON_HOOK  = 'messageglobe_sync_contact';
    private const SYNCED_META = '_messageglobe_synced_list';
    private const CONTACT_META = '_messageglobe_contact_uid';

    private ClientFactory $clients;

    private Settings $settings;

    private Logger $logger;

    public function __construct(ClientFactory $clients, Settings $settings, Logger $logger)
    {
        $this->clients  = $clients;
        $this->settings = $settings;
        $this->logger   = $logger;
    }

    public function register(): void
    {
        // The cron worker must always be listening so already-queued jobs drain
        // even if the feature is toggled off between scheduling and running.
        add_action(self::CRON_HOOK, [$this, 'do_sync']);

        if (! $this->settings->bool('sync_enabled')) {
            return;
        }

        add_action('user_register', [$this, 'maybe_sync']);
        add_action('profile_update', [$this, 'maybe_sync']);
        // set_user_role fires on role changes; args (user_id, role, old_roles).
        add_action('set_user_role', [$this, 'maybe_sync']);
    }

    /**
     * Decide whether a user should be queued for syncing. Cheap checks only.
     *
     * @param int $user_id
     */
    public function maybe_sync($user_id): void
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) {
            return;
        }

        if (! $this->is_ready()) {
            return;
        }

        if (! $this->user_in_scope($user_id)) {
            return;
        }

        // Already in this list? Nothing to do (idempotent per list).
        if ((string) get_user_meta($user_id, self::SYNCED_META, true) === $this->settings->text('sync_list_uid')) {
            return;
        }

        // Avoid scheduling duplicate jobs for the same user.
        if (! wp_next_scheduled(self::CRON_HOOK, [$user_id])) {
            wp_schedule_single_event(time() + 5, self::CRON_HOOK, [$user_id]);
        }
    }

    /**
     * Cron worker: create the contact in the target list.
     *
     * @param int $user_id
     */
    public function do_sync($user_id): void
    {
        $user_id = (int) $user_id;

        if (! $this->is_ready() || ! $this->user_in_scope($user_id)) {
            return;
        }

        $list = $this->settings->text('sync_list_uid');

        // Re-check the idempotency flag inside the worker (state may have moved).
        if ((string) get_user_meta($user_id, self::SYNCED_META, true) === $list) {
            return;
        }

        $user = get_userdata($user_id);
        if (! $user) {
            return;
        }

        $email = sanitize_email((string) $user->user_email);
        $phone = $this->user_phone($user_id);

        // The API needs at least one identifier.
        if ($email === '' && $phone === '') {
            return;
        }

        $client = $this->clients->contacts();
        if ($client === null) {
            return;
        }

        $request = new ContactRequest();
        if ($phone !== '') {
            $request->phone($phone);
        }
        if ($email !== '') {
            $request->email($email);
        }

        $first = (string) get_user_meta($user_id, 'first_name', true);
        $last  = (string) get_user_meta($user_id, 'last_name', true);
        if ($first !== '') {
            $request->firstName($first);
        }
        if ($last !== '') {
            $request->lastName($last);
        }

        try {
            $contact = $client->create($list, $request);

            update_user_meta($user_id, self::SYNCED_META, $list);
            update_user_meta($user_id, self::CONTACT_META, $contact->uid());

            $this->logger->log('contact', Logger::STATUS_SENT, $email !== '' ? $email : $phone, sprintf(
                /* translators: %s: list uid. */
                __('Synced to list %s', 'messageglobe'),
                $list
            ), ['contact_uid' => $contact->uid(), 'user_id' => $user_id]);
        } catch (\Throwable $e) {
            $this->logger->log('contact', Logger::STATUS_FAILED, $email !== '' ? $email : $phone, $e->getMessage(), ['user_id' => $user_id]);
        }
    }

    private function is_ready(): bool
    {
        return $this->settings->bool('sync_enabled')
            && $this->clients->is_ready()
            && $this->settings->text('sync_list_uid') !== '';
    }

    /**
     * True when the user holds at least one of the configured sync roles.
     */
    private function user_in_scope(int $user_id): bool
    {
        $roles = (array) $this->settings->get('sync_roles', []);
        if (empty($roles)) {
            return false;
        }

        $user = get_userdata($user_id);
        if (! $user || empty($user->roles)) {
            return false;
        }

        return (bool) array_intersect(array_map('strval', $user->roles), array_map('strval', $roles));
    }

    /**
     * Read and normalise the user's phone from the configured meta key.
     */
    private function user_phone(int $user_id): string
    {
        $key   = $this->settings->text('sync_phone_meta');
        $key   = $key !== '' ? $key : 'billing_phone';
        $phone = (string) get_user_meta($user_id, $key, true);

        if ($phone === '') {
            return '';
        }

        $plus   = strpos($phone, '+') === 0 ? '+' : '';
        $digits = (string) preg_replace('/\D/', '', $phone);

        return $digits === '' ? '' : $plus . $digits;
    }
}
