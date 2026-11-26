<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lists the Abilities API abilities belonging to a plugin: by execute
 * callback file, or by a name prefix / category equal to the plugin slug.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Abilities_Walker
{
    /**
     * Walk the abilities.
     *
     * @param array        $abilities WP_Ability-like objects.
     * @param string       $slug      Plugin slug.
     * @param Plugin_Scope $scope     Plugin scope.
     *
     * @return array<int,array> Entries sorted by name.
     */
    public static function walk(array $abilities, $slug, Plugin_Scope $scope)
    {
        $out = [];

        foreach ($abilities as $ability) {
            $execute = self::property($ability, 'execute_callback');
            $info    = null === $execute ? ['name' => '', 'file' => null] : $scope->describe($execute);

            if (!self::belongs($ability, $slug, $scope, $info['file'])) {
                continue;
            }

            $out[] = [
                'name'          => (string) $ability->get_name(),
                'label'         => (string) $ability->get_label(),
                'description'   => (string) $ability->get_description(),
                'category'      => (string) $ability->get_category(),
                'input_schema'  => self::schema($ability->get_input_schema()),
                'output_schema' => self::schema($ability->get_output_schema()),
                'callback'      => $info['name'],
                'permission'    => Permission::summarize(self::property($ability, 'permission_callback'), $scope),
                'meta'          => self::schema($ability->get_meta()),
                'pro'           => $scope->is_pro_callable($info),
            ];
        }

        return Canonical::sort_entries($out, ['name']);
    }

    /**
     * Whether an ability is the plugin's.
     *
     * @param object       $ability Ability.
     * @param string       $slug    Plugin slug.
     * @param Plugin_Scope $scope   Scope.
     * @param string|null  $file    Execute callback file.
     *
     * @return bool
     */
    private static function belongs($ability, $slug, Plugin_Scope $scope, $file)
    {
        if ($scope->owns($file)) {
            return true;
        }

        if (null !== $file && null !== $scope->relative($file)) {
            return false;
        }

        $name = (string) $ability->get_name();

        return 0 === strpos($name, $slug . '/') || (string) $ability->get_category() === $slug;
    }

    /**
     * Empty schemas/meta become null so `{}` and `[]` never get confused.
     *
     * @param mixed $value Value.
     *
     * @return mixed
     */
    private static function schema($value)
    {
        return empty($value) ? null : $value;
    }

    /**
     * Read a protected property (WP_Ability keeps its callbacks private).
     *
     * @param object $object   Object.
     * @param string $property Property name.
     *
     * @return mixed
     */
    private static function property($object, $property)
    {
        try {
            $reflection = new \ReflectionProperty($object, $property);
            $reflection->setAccessible(true);

            return $reflection->getValue($object);
        } catch (\ReflectionException $e) {
            return null;
        }
    }
}
