<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Lists the WP-CLI commands whose callable lives in a plugin, walking the
 * command tree from `WP_CLI::get_root_command()`.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Cli_Walker
{
    /**
     * Walk the tree.
     *
     * @param object       $root  Root command (anything with get_subcommands()).
     * @param Plugin_Scope $scope Plugin scope.
     *
     * @return array<int,array> One entry per command, sorted by path.
     */
    public static function walk($root, Plugin_Scope $scope)
    {
        $out = [];

        self::visit($root, [], $scope, $out);

        return Canonical::sort_entries($out, ['command']);
    }

    /**
     * Callable behind a leaf command.
     *
     * WP-CLI keeps it in the static variables of the private `when_invoked`
     * closure of the Subcommand.
     *
     * @param object $command Leaf command.
     *
     * @return mixed Callable or null.
     */
    public static function command_callable($command)
    {
        try {
            $property = new \ReflectionProperty($command, 'when_invoked');
            $property->setAccessible(true);
            $closure = $property->getValue($command);

            if (!$closure instanceof \Closure) {
                return null;
            }

            $vars = (new \ReflectionFunction($closure))->getStaticVariables();
        } catch (\ReflectionException $e) {
            return null;
        }

        foreach (['callable', 'class'] as $key) {
            if (isset($vars[$key])) {
                return $vars[$key];
            }
        }

        return null;
    }

    /**
     * Recursive visit.
     *
     * @param object       $node  Command node.
     * @param string[]     $path  Path of the parent.
     * @param Plugin_Scope $scope Scope.
     * @param array        $out   Entries (by reference).
     *
     * @return void
     */
    private static function visit($node, array $path, Plugin_Scope $scope, array &$out)
    {
        foreach ((array) $node->get_subcommands() as $child) {
            $child_path = array_merge($path, [(string) $child->get_name()]);

            if (method_exists($child, 'get_subcommands')) {
                self::visit($child, $child_path, $scope, $out);
                continue;
            }

            $entry = self::entry($child, $child_path, $scope);

            if (null !== $entry) {
                $out[] = $entry;
            }
        }
    }

    /**
     * Build the entry of a leaf command.
     *
     * @param object       $command Leaf command.
     * @param string[]     $path    Full path.
     * @param Plugin_Scope $scope   Scope.
     *
     * @return array|null Null when the command is not the plugin's.
     */
    private static function entry($command, array $path, Plugin_Scope $scope)
    {
        $callable = self::command_callable($command);

        if (null === $callable) {
            return null;
        }

        $info = $scope->describe($callable);

        if (!$scope->owns($info['file'])) {
            return null;
        }

        $long = (string) $command->get_longdesc();

        return [
            'command'   => implode(' ', $path),
            'callback'  => $info['name'],
            'shortdesc' => (string) $command->get_shortdesc(),
            'longdesc'  => $long,
            'synopsis'  => Synopsis::parse((string) $command->get_synopsis(), $long),
            'pro'       => $scope->is_pro($info['file']),
        ];
    }
}
