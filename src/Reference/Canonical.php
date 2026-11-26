<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Turns registration data into deterministic, environment-free JSON values:
 * associative arrays get sorted keys, objects and closures are dropped,
 * and known absolute paths inside strings are replaced by placeholders.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Canonical
{
    /**
     * Normalise a value tree.
     *
     * @param mixed                $value  Any value.
     * @param array<string,string> $scrubs Absolute path => placeholder.
     *
     * @return mixed
     */
    public static function normalize($value, array $scrubs = [])
    {
        if (is_string($value)) {
            return self::scrub($value, $scrubs);
        }

        if (!is_array($value)) {
            return is_object($value) || is_resource($value) ? null : $value;
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_object($item) || is_resource($item)) {
                continue;
            }

            $out[$key] = self::normalize($item, $scrubs);
        }

        if (self::is_list($out)) {
            return array_values($out);
        }

        ksort($out, SORT_STRING);

        return $out;
    }

    /**
     * Encode as stable, pretty-printed JSON with a trailing newline.
     *
     * @param mixed $value Normalised value.
     *
     * @return string
     */
    public static function encode($value)
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- must work without WordPress loaded.
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return (false === $json ? 'null' : $json) . "\n";
    }

    /**
     * Sort a list of entries by a string key, ties broken by their JSON.
     *
     * @param array<int,array> $items Entries.
     * @param string[]         $keys  Entry keys forming the sort key.
     *
     * @return array<int,array>
     */
    public static function sort_entries(array $items, array $keys)
    {
        usort($items, function ($a, $b) use ($keys) {
            foreach ($keys as $key) {
                $cmp = strcmp((string) ($a[$key] ?? ''), (string) ($b[$key] ?? ''));

                if (0 !== $cmp) {
                    return $cmp;
                }
            }

            return strcmp(self::encode($a), self::encode($b));
        });

        return $items;
    }

    /**
     * Whether an array is a zero-based list.
     *
     * @param array $value Array.
     *
     * @return bool
     */
    public static function is_list(array $value)
    {
        return array_keys($value) === range(0, count($value) - 1) || [] === $value;
    }

    /**
     * Replace absolute paths inside a string.
     *
     * @param string               $value  String.
     * @param array<string,string> $scrubs Absolute path => placeholder.
     *
     * @return string
     */
    private static function scrub($value, array $scrubs)
    {
        if ([] === $scrubs) {
            return $value;
        }

        uksort($scrubs, function ($a, $b) {
            return strlen($b) <=> strlen($a);
        });

        foreach ($scrubs as $path => $placeholder) {
            if (strlen($path) > 1) {
                $value = str_replace(rtrim($path, '/'), $placeholder, $value);
            }
        }

        return $value;
    }
}
