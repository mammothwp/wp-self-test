<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Parses a WP-CLI synopsis string and the `## OPTIONS` section of a long
 * description into one list of argument descriptors.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Synopsis
{
    /**
     * Parse a command's arguments.
     *
     * @param string $synopsis Synopsis, e.g. `<slug> [--format=<format>]`.
     * @param string $longdesc Raw long description.
     *
     * @return array<int,array> Descriptors in synopsis order.
     */
    public static function parse($synopsis, $longdesc)
    {
        $docs = self::docs($longdesc);
        $out  = [];

        foreach (preg_split('/\s+/', trim((string) $synopsis), -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $arg = self::token($token);

            if (null === $arg) {
                continue;
            }

            $doc  = $docs[$arg['type'] . ':' . $arg['name']] ?? [];
            $out[] = $arg + [
                'description' => $doc['description'] ?? '',
                'default'     => $doc['default'] ?? null,
                'options'     => $doc['options'] ?? [],
            ];
        }

        return $out;
    }

    /**
     * Parse one synopsis token.
     *
     * @param string $token Token such as `[--format=<format>]` or `<id>...`.
     *
     * @return array{type: string, name: string, optional: bool, repeating: bool}|null
     */
    public static function token($token)
    {
        $optional  = 0 === strpos($token, '[');
        $repeating = (bool) preg_match('/\.\.\.\]?$/', $token);
        $core      = trim(preg_replace('/\.\.\.\]?$/', '', $token), '[]');

        if (preg_match('/^--<([^>]+)>=<[^>]+>$/', $core, $m)) {
            return ['type' => 'generic', 'name' => $m[1], 'optional' => $optional, 'repeating' => $repeating];
        }

        if (preg_match('/^--([A-Za-z0-9_\-]+)(=.*)?$/', $core, $m)) {
            return ['type' => isset($m[2]) ? 'assoc' : 'flag', 'name' => $m[1], 'optional' => $optional, 'repeating' => $repeating];
        }

        if (preg_match('/^<([^>]+)>$/', $core, $m)) {
            return ['type' => 'positional', 'name' => $m[1], 'optional' => $optional, 'repeating' => $repeating];
        }

        return null;
    }

    /**
     * Descriptions, defaults and options from the OPTIONS section.
     *
     * @param string $longdesc Raw long description.
     *
     * @return array<string,array> `type:name` => doc.
     */
    private static function docs($longdesc)
    {
        $lines   = preg_split('/\r\n|\r|\n/', (string) $longdesc);
        $inside  = false;
        $current = null;
        $yaml    = false;
        $docs    = [];
        $last    = '';

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+)$/', $line, $m)) {
                $inside  = 'OPTIONS' === trim($m[1]);
                $current = null;
                continue;
            }

            if (!$inside) {
                continue;
            }

            if ('---' === trim($line)) {
                $yaml = !$yaml;
                continue;
            }

            if ($yaml && null !== $current) {
                $last = self::yaml_line($docs[$current], $line, $last);
                continue;
            }

            $current = self::doc_line($docs, $current, $line);
        }

        return $docs;
    }

    /**
     * Handle a non-YAML line of the OPTIONS section.
     *
     * @param array       $docs    Docs (by reference).
     * @param string|null $current Current doc key.
     * @param string      $line    Line.
     *
     * @return string|null New current doc key.
     */
    private static function doc_line(array &$docs, $current, $line)
    {
        if ('' === trim($line)) {
            return $current;
        }

        if (0 === strpos($line, ':') && null !== $current) {
            $text = trim(substr($line, 1));
            $docs[$current]['description'] = trim($docs[$current]['description'] . ' ' . $text);

            return $current;
        }

        if (preg_match('/^\s/', $line) && null !== $current) {
            $docs[$current]['description'] = trim($docs[$current]['description'] . ' ' . trim($line));

            return $current;
        }

        $arg = self::token(trim($line));

        if (null === $arg) {
            return null;
        }

        $key        = $arg['type'] . ':' . $arg['name'];
        $docs[$key] = ['description' => '', 'default' => null, 'options' => []];

        return $key;
    }

    /**
     * Handle a YAML line (`default:` / `options:` list).
     *
     * @param array  $doc  Doc (by reference).
     * @param string $line Line.
     * @param string $last Last YAML key.
     *
     * @return string Last YAML key.
     */
    private static function yaml_line(array &$doc, $line, $last)
    {
        if (preg_match('/^\s*-\s*(.*)$/', $line, $m) && 'options' === $last) {
            $doc['options'][] = self::scalar($m[1]);

            return $last;
        }

        if (preg_match('/^\s*([A-Za-z_]+):\s*(.*)$/', $line, $m)) {
            if ('default' === $m[1]) {
                $doc['default'] = self::scalar($m[2]);
            }

            return $m[1];
        }

        return $last;
    }

    /**
     * Unquote a YAML scalar.
     *
     * @param string $value Raw value.
     *
     * @return string
     */
    private static function scalar($value)
    {
        return trim(trim($value), '"\'');
    }
}
