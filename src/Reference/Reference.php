<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Assembles the schema v1 developer reference of one plugin.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Reference
{
    /** Output schema version. */
    const SCHEMA = 1;

    /**
     * Read the Version header of a plugin file.
     *
     * @param string $file Plugin file.
     *
     * @return string
     */
    public static function version($file)
    {
        $head = is_readable($file) ? (string) file_get_contents($file, false, null, 0, 8192) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

        return preg_match('/^[ \t\/*#@]*Version:[ \t]*(.+)$/mi', $head, $m) ? trim($m[1]) : '';
    }

    /**
     * Find the main file of a plugin directory (first top-level PHP file
     * with a `Plugin Name:` header).
     *
     * @param string $dir Plugin directory.
     *
     * @return string Absolute path or ''.
     */
    public static function main_file($dir)
    {
        $files = glob(rtrim($dir, '/') . '/*.php');
        $files = false === $files ? [] : $files;
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $head = (string) file_get_contents($file, false, null, 0, 8192); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.

            if (preg_match('/^[ \t\/*#@]*Plugin Name:/mi', $head)) {
                return $file;
            }
        }

        return '';
    }

    /**
     * Build the reference document.
     *
     * @param string       $slug       Plugin slug.
     * @param string       $version    Plugin version.
     * @param Plugin_Scope $scope      Plugin scope.
     * @param array        $routes     REST routes (get_routes()).
     * @param string[]     $namespaces REST namespaces.
     * @param object|null  $cli_root   WP-CLI root command.
     * @param array        $abilities  Ability objects.
     *
     * @return array Normalised document, ready for Canonical::encode().
     */
    public static function build($slug, $version, Plugin_Scope $scope, array $routes, array $namespaces, $cli_root, array $abilities)
    {
        $doc = [
            'schema'    => self::SCHEMA,
            'plugin'    => $slug,
            'version'   => $version,
            'rest'      => Rest_Walker::walk($routes, $namespaces, $scope),
            'cli'       => null === $cli_root ? [] : Cli_Walker::walk($cli_root, $scope),
            'abilities' => Abilities_Walker::walk($abilities, $slug, $scope),
        ];

        return Canonical::normalize($doc, $scope->scrubs());
    }
}
