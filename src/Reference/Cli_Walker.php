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
     * Register the commands WP-CLI deferred because their parent was never registered.
     *
     * WP-CLI re-adds a deferred command when its parent appears; a plugin that registers
     * `mmwp-sc list` without `mmwp-sc` leaves it in the deferred list for good. Adding it
     * with its stored `is_deferred` flag makes WP-CLI create the empty parent container
     * itself, exactly as it does for an unknown parent at the end of a run.
     *
     * @param string $wp_cli WP-CLI class name (a parameter so tests can inject a fake).
     *
     * @return int Number of commands resolved.
     */
    public static function resolve_deferred($wp_cli = 'WP_CLI')
    {
        if (!class_exists($wp_cli) || !method_exists($wp_cli, 'get_deferred_additions')) {
            return 0;
        }

        $count = 0;

        foreach ((array) $wp_cli::get_deferred_additions() as $name => $addition) {
            $wp_cli::remove_deferred_addition($name);
            $wp_cli::add_command((string) $name, $addition['callable'], (array) $addition['args']);
            ++$count;
        }

        return $count;
    }

    /**
     * Whether a node is a command group: a composite with subcommands.
     *
     * WP-CLI's `Subcommand` extends `CompositeCommand`, so a leaf has get_subcommands()
     * too (it returns an empty list); only a non-empty, non-leaf node is a group.
     *
     * @param object $node Command node.
     *
     * @return bool
     */
    public static function is_group($node)
    {
        if (!method_exists($node, 'get_subcommands') || is_a($node, 'WP_CLI\\Dispatcher\\Subcommand')) {
            return false;
        }

        return [] !== (array) $node->get_subcommands();
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

            if (self::is_group($child)) {
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
            'pro'       => $scope->is_pro_callable($info),
        ];
    }
}
