<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sees through the closures mammothwp/wp-kit's REST and Ability adapters
 * register, so a route, ability or permission is attributed to the plugin
 * method the operation really runs and to the capability it declared.
 *
 * Each adapter closure captures its `Operation` (`use ($operation)`); the
 * operation's `handler()` names the service method, `resource()` yields the
 * service instance and `capability_resolver()` the declared capability.
 * Only public accessors are used, except the private `service_override`
 * set by `Operation::on()`.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.1
 */
final class Kit_Unwrap
{
    /**
     * The wp-kit operation a closure captured.
     *
     * @param mixed $callable Any callable.
     *
     * @return object|null
     */
    public static function operation($callable)
    {
        if (!$callable instanceof \Closure) {
            return null;
        }

        try {
            $vars = (new \ReflectionFunction($callable))->getStaticVariables();
        } catch (\ReflectionException $e) {
            return null;
        }

        $operation = $vars['operation'] ?? null;

        if (is_object($operation) && method_exists($operation, 'handler') && method_exists($operation, 'capability_resolver')) {
            return $operation;
        }

        return null;
    }

    /**
     * The callable a callback really runs: `[service, method]` for a wp-kit
     * adapter closure, the callable itself otherwise.
     *
     * @param mixed $callable Any callable.
     *
     * @return mixed
     */
    public static function callable($callable)
    {
        $operation = self::operation($callable);

        if (null === $operation) {
            return $callable;
        }

        $service = self::service($operation);

        return null === $service ? $callable : [$service, (string) $operation->handler()];
    }

    /**
     * Capability declared by a wp-kit operation.
     *
     * @param object $operation wp-kit operation.
     *
     * @return array{capability: string|null, public: bool, resolver: mixed} `resolver` is the
     *         declared callable when the capability is computed, otherwise null.
     */
    public static function capability($operation)
    {
        $resolver = $operation->capability_resolver();

        if (true === $resolver) {
            return ['capability' => null, 'public' => true, 'resolver' => null];
        }

        if (is_string($resolver) && '' !== $resolver && !is_callable($resolver)) {
            return ['capability' => $resolver, 'public' => false, 'resolver' => null];
        }

        $computed = is_callable($resolver) ? $resolver : null;

        return ['capability' => null, 'public' => false, 'resolver' => $computed];
    }

    /**
     * Service object an operation runs on.
     *
     * @param object $operation wp-kit operation.
     *
     * @return object|null
     */
    private static function service($operation)
    {
        try {
            $property = new \ReflectionProperty($operation, 'service_override');
            $property->setAccessible(true);
            $override = $property->getValue($operation);
        } catch (\ReflectionException $e) {
            $override = null;
        }

        if (is_object($override)) {
            return $override;
        }

        $resource = method_exists($operation, 'resource') ? $operation->resource() : null;

        if (is_object($resource) && method_exists($resource, 'service_instance')) {
            $service = $resource->service_instance();

            return is_object($service) ? $service : null;
        }

        return null;
    }
}
