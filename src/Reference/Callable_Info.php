<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reflection helpers: where a callable is defined and what it is called.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Callable_Info
{
    /**
     * Inspect a callable.
     *
     * @param mixed $callable Callable (closure, function name, `Class::method`, `[object|class, method]`, invokable).
     *
     * @return array{name: string, file: string|null, line: int, source: string}
     */
    public static function inspect($callable)
    {
        $none = ['name' => '', 'file' => null, 'line' => 0, 'source' => ''];

        try {
            $reflection = self::reflect($callable);
        } catch (\ReflectionException $e) {
            return ['name' => self::name($callable)] + $none;
        }

        if (null === $reflection) {
            return ['name' => self::name($callable)] + $none;
        }

        $file = $reflection->getFileName();

        return [
            'name'   => self::name($callable),
            'file'   => false === $file ? null : $file,
            'line'   => (int) $reflection->getStartLine(),
            'source' => self::source($reflection),
        ];
    }

    /**
     * Capability literals checked with current_user_can() inside a callable.
     *
     * @param string $source Source text of the callable.
     *
     * @return string[] Sorted, unique.
     */
    public static function capabilities($source)
    {
        preg_match_all('/current_user_can\(\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/', $source, $matches);

        $caps = array_unique($matches[1]);
        sort($caps, SORT_STRING);

        return $caps;
    }

    /**
     * Reflect a callable.
     *
     * @param mixed $callable Callable.
     *
     * @return \ReflectionFunctionAbstract|null
     *
     * @throws \ReflectionException When the callable cannot be reflected.
     */
    private static function reflect($callable)
    {
        if ($callable instanceof \Closure) {
            return new \ReflectionFunction($callable);
        }

        if (is_string($callable) && false !== strpos($callable, '::')) {
            $callable = explode('::', $callable, 2);
        }

        if (is_array($callable) && 2 === count($callable)) {
            return new \ReflectionMethod($callable[0], (string) $callable[1]);
        }

        if (is_string($callable) && function_exists($callable)) {
            return new \ReflectionFunction($callable);
        }

        if (is_object($callable) && method_exists($callable, '__invoke')) {
            return new \ReflectionMethod($callable, '__invoke');
        }

        return null;
    }

    /**
     * Readable, environment-free name.
     *
     * @param mixed $callable Callable.
     *
     * @return string
     */
    private static function name($callable)
    {
        if ($callable instanceof \Closure) {
            return '{closure}';
        }

        if (is_string($callable)) {
            return $callable;
        }

        if (is_array($callable) && 2 === count($callable)) {
            $class = is_object($callable[0]) ? get_class($callable[0]) : (string) $callable[0];

            return $class . '::' . (string) $callable[1];
        }

        return is_object($callable) ? get_class($callable) . '::__invoke' : '';
    }

    /**
     * Source text of a function.
     *
     * @param \ReflectionFunctionAbstract $reflection Function.
     *
     * @return string
     */
    private static function source(\ReflectionFunctionAbstract $reflection)
    {
        $file = $reflection->getFileName();

        if (false === $file || !is_readable($file)) {
            return '';
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $start = (int) $reflection->getStartLine();
        $end   = (int) $reflection->getEndLine();

        if (false === $lines || $start < 1) {
            return '';
        }

        return implode("\n", array_slice($lines, $start - 1, $end - $start + 1));
    }
}
