<?php
/**
 * PHPUnit bootstrap: a tiny in-memory WordPress stand-in (hooks, options,
 * WP_Error) so the package can be unit-tested without a WordPress install.
 *
 * @package MammothWP\SelfTest
 */

define('ABSPATH', sys_get_temp_dir() . '/');

require dirname(__DIR__) . '/src/Wp_Cli_Shim.php';
require __DIR__ . '/wp-stubs.php';
require dirname(__DIR__) . '/vendor/autoload.php';

// PHPUnit loads vendor/autoload.php before ABSPATH exists, so the guarded
// "files" entries returned early; load them again now.
require dirname(__DIR__) . '/compat.php';
