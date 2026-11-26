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

        $info = $scope->describe($callable);
        $caps = Callable_Info::capabilities($info['source']);

        return [
            'callback'   => $info['name'],
            'capability' => $caps[0] ?? null,
            'public'     => '__return_true' === $callable,
        ];
    }
}
