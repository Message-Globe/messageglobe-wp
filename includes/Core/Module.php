<?php

declare(strict_types=1);

namespace MessageGlobe\WP\Core;

defined('ABSPATH') || exit;

/**
 * A self-contained feature that wires itself into WordPress via hooks.
 *
 * Modules are instantiated once by {@see Plugin::run()} and asked to
 * register(); they must not do any work in their constructor beyond storing
 * their collaborators.
 */
interface Module
{
    /**
     * Attach this module's WordPress hooks (actions/filters).
     */
    public function register(): void;
}
