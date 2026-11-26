<?php

namespace MammothWP\SelfTest\Surfaces;

use MammothWP\SelfTest\Reference\Canonical;
use MammothWP\SelfTest\Reference\Plugin_Scope;
use MammothWP\SelfTest\Reference\Pro_Paths;
use MammothWP\SelfTest\Reference\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `wp mmwp reference` — a deterministic JSON developer reference of what a
 * plugin registered at runtime: REST routes, WP-CLI commands and abilities.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Reference_Command
{
    /**
     * Register the command.
     *
     * @return void
     */
    public static function init()
    {
        if (!class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('mmwp reference', [__CLASS__, 'run']);
    }

    /**
     * Print the developer reference of an active plugin as JSON (schema v1).
     *
     * Reads what the plugin registered at runtime (REST routes, WP-CLI
     * commands, abilities); attribution is by the file of each callback.
     * Items living under a path stripped from the free build (the
     * `forbid`/`strip` lists of mmwp-free.json, `@fs_premium_only` in the
     * main file header) carry `"pro": true`. The output has sorted keys and
     * lists, no timestamps and no absolute paths, so CI can diff it.
     *
     * ## OPTIONS
     *
     * --plugin=<slug>
     * : Slug (directory name) of an active plugin.
     *
     * [--output=<file>]
     * : Write the JSON to this file instead of stdout.
     *
     * [--format=<format>]
     * : Output format. Only json is supported.
     * ---
     * default: json
     * options:
     *   - json
     * ---
     *
     * ## EXAMPLES
     *
     *     wp mmwp reference --plugin=my-plugin
     *     wp mmwp reference --plugin=my-plugin --output=docs/reference.json
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Named arguments.
     *
     * @return void
     */
    public static function run($args, $assoc_args)
    {
        $slug = isset($assoc_args['plugin']) ? (string) $assoc_args['plugin'] : '';

        if ('json' !== ($assoc_args['format'] ?? 'json')) {
            \WP_CLI::error('Only --format=json is supported.');
        }

        $dir = self::plugin_dir($slug);
        $main = Reference::main_file($dir);

        if ('' === $main) {
            \WP_CLI::error(sprintf('Plugin "%s" not found.', $slug));
        }

        if (!self::is_active($slug, $main)) {
            \WP_CLI::error(sprintf('Plugin "%s" is not active; the reference needs its runtime registrations.', $slug));
        }

        $scope = new Plugin_Scope($dir, Pro_Paths::from_plugin($dir, $main));
        $json  = Canonical::encode(self::collect($slug, Reference::version($main), $scope));
        $file  = isset($assoc_args['output']) ? (string) $assoc_args['output'] : '';

        if ('' === $file) {
            \WP_CLI::line(rtrim($json, "\n"));
            return;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI writes a user-chosen path.
        if (false === file_put_contents($file, $json)) {
            \WP_CLI::error(sprintf('Cannot write %s.', $file));
        }

        \WP_CLI::success(sprintf('Wrote %s.', $file));
    }

    /**
     * Gather the live registrations and build the document.
     *
     * @param string       $slug    Plugin slug.
     * @param string       $version Plugin version.
     * @param Plugin_Scope $scope   Scope.
     *
     * @return array
     */
    private static function collect($slug, $version, Plugin_Scope $scope)
    {
        $server    = rest_get_server();
        $abilities = function_exists('wp_get_abilities') ? array_values((array) wp_get_abilities()) : [];

        return Reference::build($slug, $version, $scope, (array) $server->get_routes(), (array) $server->get_namespaces(), \WP_CLI::get_root_command(), $abilities);
    }

    /**
     * Directory of a plugin slug ('' when the slug is malformed).
     *
     * @param string $slug Slug.
     *
     * @return string
     */
    private static function plugin_dir($slug)
    {
        if (!preg_match('/^[A-Za-z0-9._-]+$/', $slug) || '.' === $slug[0]) {
            \WP_CLI::error('--plugin must be a plugin directory name.');
        }

        return rtrim((string) WP_PLUGIN_DIR, '/') . '/' . $slug;
    }

    /**
     * Whether the plugin is active (site or network).
     *
     * @param string $slug Slug.
     * @param string $main Main file.
     *
     * @return bool
     */
    private static function is_active($slug, $main)
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $basename = $slug . '/' . basename($main);

        return is_plugin_active($basename) || is_plugin_active_for_network($basename);
    }
}
