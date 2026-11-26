<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lists the REST routes whose callbacks live in a plugin, from the data of
 * `rest_get_server()->get_routes()`.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Rest_Walker
{
    /** Argument keys kept in the reference. */
    const ARG_KEYS = ['type', 'description', 'enum', 'default', 'format', 'items', 'properties', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems', 'pattern', 'oneOf', 'anyOf'];

    /**
     * Walk the routes.
     *
     * @param array        $routes     Route => handlers, as returned by get_routes().
     * @param string[]     $namespaces Registered REST namespaces.
     * @param Plugin_Scope $scope      Plugin scope.
     *
     * @return array<int,array> One entry per route and method, sorted.
     */
    public static function walk(array $routes, array $namespaces, Plugin_Scope $scope)
    {
        $out = [];

        foreach ($routes as $route => $handlers) {
            foreach (self::handlers($handlers) as $handler) {
                $info = isset($handler['callback']) ? $scope->describe($handler['callback']) : null;

                if (null === $info || !$scope->owns($info['file'])) {
                    continue;
                }

                foreach (self::methods($handler) as $method) {
                    $out[] = self::entry((string) $route, $method, $handler, $info, $namespaces, $scope);
                }
            }
        }

        return Canonical::sort_entries($out, ['route', 'method']);
    }

    /**
     * Normalise the arguments of a handler to a sorted list.
     *
     * @param mixed $args Handler `args`.
     *
     * @return array<int,array>
     */
    public static function args($args)
    {
        $out = [];

        foreach ((array) $args as $name => $arg) {
            $arg   = is_array($arg) ? $arg : [];
            $entry = ['name' => (string) $name, 'required' => !empty($arg['required'])];

            foreach (self::ARG_KEYS as $key) {
                if (array_key_exists($key, $arg)) {
                    $entry[$key] = $arg[$key];
                }
            }

            $out[] = $entry;
        }

        return Canonical::sort_entries($out, ['name']);
    }

    /**
     * Longest registered namespace a route belongs to.
     *
     * @param string   $route      Route.
     * @param string[] $namespaces Namespaces.
     *
     * @return string
     */
    public static function namespace_of($route, array $namespaces)
    {
        $best = '';

        foreach ($namespaces as $namespace) {
            $namespace = (string) $namespace;
            $prefix    = '/' . $namespace;
            $inside    = $route === $prefix || 0 === strpos($route, $prefix . '/');

            if ($inside && strlen($namespace) > strlen($best)) {
                $best = $namespace;
            }
        }

        return $best;
    }

    /**
     * Build one entry.
     *
     * @param string       $route      Route.
     * @param string       $method     HTTP method.
     * @param array        $handler    Handler.
     * @param array        $info       Callback description.
     * @param string[]     $namespaces Namespaces.
     * @param Plugin_Scope $scope      Scope.
     *
     * @return array
     */
    private static function entry($route, $method, array $handler, array $info, array $namespaces, Plugin_Scope $scope)
    {
        return [
            'route'       => $route,
            'method'      => $method,
            'methods'     => self::methods($handler),
            'namespace'   => self::namespace_of($route, $namespaces),
            'callback'    => $info['name'],
            'description' => isset($handler['description']) ? (string) $handler['description'] : '',
            'args'        => self::args($handler['args'] ?? []),
            'permission'  => Permission::summarize($handler['permission_callback'] ?? null, $scope),
            'pro'         => $scope->is_pro($info['file']),
        ];
    }

    /**
     * The handler list of a route (some routes hold a single handler).
     *
     * @param mixed $handlers Route value.
     *
     * @return array<int,array>
     */
    private static function handlers($handlers)
    {
        if (!is_array($handlers)) {
            return [];
        }

        return isset($handlers['callback']) ? [$handlers] : array_values(array_filter($handlers, 'is_array'));
    }

    /**
     * HTTP methods of a handler, upper-cased and sorted.
     *
     * @param array $handler Handler.
     *
     * @return string[]
     */
    private static function methods(array $handler)
    {
        $raw     = $handler['methods'] ?? [];
        $methods = [];

        if (is_string($raw)) {
            $raw = array_fill_keys(explode(',', $raw), true);
        }

        foreach ((array) $raw as $key => $value) {
            $name = is_string($key) ? $key : (string) $value;

            if (is_string($key) && !$value) {
                continue;
            }

            $name = strtoupper(trim($name));

            if ('' !== $name) {
                $methods[$name] = true;
            }
        }

        $methods = array_keys($methods);
        sort($methods, SORT_STRING);

        return $methods;
    }
}
