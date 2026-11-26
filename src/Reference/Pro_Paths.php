<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The paths a plugin's free build does not ship: the `forbid`/`strip`
 * lists of its `mmwp-free.json` plus the `@fs_premium_only` header paths
 * of its main file. Patterns are plugin-relative; a plain path matches
 * itself and everything below it, glob characters use fnmatch().
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Pro_Paths
{
    /** Keys of mmwp-free.json whose string lists name paths missing from the free build. */
    const MANIFEST_KEYS = ['forbid', 'strip', 'remove', 'exclude'];

    /** @var string[] */
    private $patterns;

    /**
     * @param string[] $patterns Plugin-relative patterns.
     */
    public function __construct(array $patterns = [])
    {
        $clean = [];

        foreach ($patterns as $pattern) {
            $pattern = trim(ltrim(trim((string) $pattern), './'));

            if ('' !== $pattern) {
                $clean[$pattern] = true;
            }
        }

        $this->patterns = array_keys($clean);
        sort($this->patterns, SORT_STRING);
    }

    /**
     * Read the patterns of a plugin directory.
     *
     * @param string $plugin_dir Plugin directory.
     * @param string $main_file  Main plugin file (absolute) or ''.
     *
     * @return self
     */
    public static function from_plugin($plugin_dir, $main_file = '')
    {
        $patterns = [];
        $manifest = rtrim($plugin_dir, '/') . '/mmwp-free.json';

        if (is_readable($manifest)) {
            $data     = json_decode((string) file_get_contents($manifest), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            $patterns = is_array($data) ? self::manifest_patterns($data) : [];
        }

        if ('' !== $main_file && is_readable($main_file)) {
            $head     = (string) file_get_contents($main_file, false, null, 0, 8192); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
            $patterns = array_merge($patterns, self::header_patterns($head));
        }

        return new self($patterns);
    }

    /**
     * Patterns listed in a decoded mmwp-free.json.
     *
     * @param array $data Decoded manifest.
     *
     * @return string[]
     */
    public static function manifest_patterns(array $data)
    {
        $out = [];

        foreach (self::MANIFEST_KEYS as $key) {
            foreach ((array) ($data[$key] ?? []) as $item) {
                if (is_string($item)) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * Patterns from `@fs_premium_only` lines of a file header.
     *
     * @param string $header Beginning of the main plugin file.
     *
     * @return string[]
     */
    public static function header_patterns($header)
    {
        $out = [];

        if (preg_match_all('/@fs_premium_only[ \t]+([^\r\n]+)/', $header, $matches)) {
            foreach ($matches[1] as $line) {
                $line = trim(preg_replace('/\*\/.*$/', '', $line));

                foreach (explode(',', $line) as $part) {
                    $out[] = trim($part, " \t*");
                }
            }
        }

        return $out;
    }

    /**
     * The normalised patterns.
     *
     * @return string[]
     */
    public function patterns()
    {
        return $this->patterns;
    }

    /**
     * Whether a plugin-relative path is Pro-only.
     *
     * @param string $relative Plugin-relative path.
     *
     * @return bool
     */
    public function matches($relative)
    {
        $relative = ltrim((string) $relative, '/');

        foreach ($this->patterns as $pattern) {
            if (self::match_one($pattern, $relative)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Match a single pattern.
     *
     * @param string $pattern  Pattern.
     * @param string $relative Plugin-relative path.
     *
     * @return bool
     */
    private static function match_one($pattern, $relative)
    {
        $base = rtrim($pattern, '/');

        if (false === strpbrk($pattern, '*?[')) {
            return $relative === $base || 0 === strpos($relative, $base . '/');
        }

        return fnmatch($pattern, $relative) || fnmatch($base . '/*', $relative);
    }
}
