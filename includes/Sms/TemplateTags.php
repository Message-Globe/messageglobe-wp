<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Sms;

defined('ABSPATH') || exit;

/**
 * Renders "{tag}" placeholders in a message template against a value map.
 *
 * Unknown tags are left untouched so a mis-typed placeholder is visible rather
 * than silently blanked. Values are cast to string and trimmed.
 */
final class TemplateTags
{
    /**
     * @param array<string, scalar|null> $vars
     */
    public static function render(string $template, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            $replacements['{' . $key . '}'] = trim((string) $value);
        }

        return strtr($template, $replacements);
    }
}
