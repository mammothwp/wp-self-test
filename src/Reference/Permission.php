<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Summarises a permission callback.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Permission
{
    /**
     * Summary of a permission callback.
     *
     * `capability` is the first literal current_user_can() argument found in
     * the callback body, or null when it cannot be determined.
     *
     * @param mixed        $callable Permission callback or null.
     * @param Plugin_Scope $scope    Plugin scope.
     *
     * @return array{callback: string|null, capability: string|null, public: bool}
     */
    public static function summarize($callable, Plugin_Scope $scope)
    {
        if (null === $callable || '' === $callable || false === $callable) {
            return ['callback' => null, 'capability' => null, 'public' => false];
        }

        $operation = Kit_Unwrap::operation($callable);

        if (null !== $operation) {
            return self::summarize_operation($operation, $scope);
        }

        $info = $scope->describe($callable);
        $caps = Callable_Info::capabilities($info['source']);

        return [
            'callback'   => $info['name'],
            'capability' => $caps[0] ?? null,
            'public'     => '__return_true' === $callable,
        ];
    }

    /**
     * Summary of the permission of a wp-kit operation: the capability it declared.
     *
     * @param object       $operation wp-kit operation.
     * @param Plugin_Scope $scope     Plugin scope.
     *
     * @return array{callback: string, capability: string|null, public: bool}
     */
    private static function summarize_operation($operation, Plugin_Scope $scope)
    {
        $declared = Kit_Unwrap::capability($operation);
        $caps     = [];

        if (null !== $declared['resolver']) {
            $caps = Callable_Info::capabilities($scope->describe($declared['resolver'])['source']);
        }

        return [
            'callback'   => get_class($operation) . '::authorize',
            'capability' => $declared['capability'] ?? ($caps[0] ?? null),
            'public'     => $declared['public'],
        ];
    }
}
