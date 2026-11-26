<?php
/**
 * `wp mmwp reference` regressions: leaf commands, deferred commands, `__premium_only`,
 * wp-kit closures, bundled `vendor/` code and wp-kit capabilities.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Reference\Abilities_Walker;
use MammothWP\SelfTest\Reference\Cli_Walker;
use MammothWP\SelfTest\Reference\Kit_Unwrap;
use MammothWP\SelfTest\Reference\Permission;
use MammothWP\SelfTest\Reference\Plugin_Scope;
use MammothWP\SelfTest\Reference\Pro_Paths;
use MammothWP\SelfTest\Reference\Rest_Walker;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Ability;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Composite;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Kit_Operation;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Kit_Resource;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Subcommand;
use MammothWP\SelfTest\Tests\Fixtures\Fake_WP_CLI;
use PHPUnit\Framework\TestCase;

// phpcs:disable WordPress.WP.AlternativeFunctions -- the test builds and removes a throwaway plugin tree.
require_once __DIR__ . '/Fixtures/Reference_Fakes.php';

final class Reference_Fixes_Test extends TestCase
{
    /** @var string */
    private static $dir;

    /** @var object */
    private static $own;

    /** @var object */
    private static $bundled;

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/mmwp-ref-fixes-' . getmypid();

        // vendor/ is git-ignored, so the plugin tree is generated.
        foreach (['src/Pro', 'vendor/acme/pkg'] as $sub) {
            mkdir(self::$dir . '/' . $sub, 0777, true);
        }

        file_put_contents(self::$dir . '/plugin.php', "<?php\n/**\n * Plugin Name: Fixes\n * Version: 1.0.0\n *\n * @fs_premium_only /src/Pro/, /src/Licensing/Pro.php\n */\n");
        file_put_contents(self::$dir . '/src/Own.php', "<?php\nnamespace FixesPlugin;\nclass Own {\n    public function run() {}\n    public function send__premium_only() {}\n    public function can() { return current_user_can('manage_woocommerce'); }\n}\n");
        file_put_contents(self::$dir . '/src/Pro/Sender.php', "<?php\nnamespace FixesPlugin\\Pro;\nclass Sender {\n    public function send() {}\n}\n");
        file_put_contents(self::$dir . '/vendor/acme/pkg/Support.php', "<?php\nnamespace Acme\\Pkg;\nclass Support {\n    public function ping() {}\n}\n");

        require_once self::$dir . '/src/Own.php';
        require_once self::$dir . '/src/Pro/Sender.php';
        require_once self::$dir . '/vendor/acme/pkg/Support.php';

        self::$own     = new \FixesPlugin\Own();
        self::$bundled = new \Acme\Pkg\Support();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (['/vendor/acme/pkg/Support.php', '/src/Pro/Sender.php', '/src/Own.php', '/plugin.php'] as $file) {
            unlink(self::$dir . $file);
        }
        foreach (['/vendor/acme/pkg', '/vendor/acme', '/vendor', '/src/Pro', '/src', ''] as $sub) {
            rmdir(self::$dir . $sub);
        }
    }

    private function scope()
    {
        return new Plugin_Scope(self::$dir, Pro_Paths::from_plugin(self::$dir, self::$dir . '/plugin.php'));
    }

    private function kit($service, $method, $capability = 'manage_woocommerce', $override = null)
    {
        return (new Fake_Kit_Operation($method, $capability, new Fake_Kit_Resource($service), $override))->adapter_closure();
    }

    public function test_cli_leaf_subcommands_are_reported_not_recursed()
    {
        // Real WP-CLI: Subcommand extends CompositeCommand, so leaves answer get_subcommands() with [].
        $leaf = new Fake_Subcommand('list', [self::$own, 'run'], '', 'List.', '');

        $this->assertTrue(method_exists($leaf, 'get_subcommands'));
        $this->assertFalse(Cli_Walker::is_group($leaf));
        $this->assertTrue(Cli_Walker::is_group(new Fake_Composite('g', [$leaf])));
        $this->assertFalse(Cli_Walker::is_group(new Fake_Composite('empty', [])));

        $root = new Fake_Composite('wp', [new Fake_Composite('mmwp-sc', [$leaf, new Fake_Subcommand('send', [new \FixesPlugin\Pro\Sender(), 'send'], '', 'Send.', '')])]);
        $rows = Cli_Walker::walk($root, $this->scope());

        $this->assertSame(['mmwp-sc list', 'mmwp-sc send'], array_column($rows, 'command'));
        $this->assertSame([false, true], array_column($rows, 'pro'));
    }

    public function test_deferred_commands_are_resolved_with_their_stored_args()
    {
        Fake_WP_CLI::$added    = [];
        Fake_WP_CLI::$deferred = [
            'mmwp-sc list'    => ['parent' => 'mmwp-sc', 'callable' => 'a', 'args' => ['is_deferred' => true]],
            'mmwp-sc migrate' => ['parent' => 'mmwp-sc', 'callable' => 'b', 'args' => ['is_deferred' => true, 'shortdesc' => 'Migrate.']],
        ];

        $this->assertSame(2, Cli_Walker::resolve_deferred(Fake_WP_CLI::class));
        $this->assertSame(['mmwp-sc list', 'mmwp-sc migrate'], array_keys(Fake_WP_CLI::$added));
        $this->assertTrue(Fake_WP_CLI::$added['mmwp-sc list']['is_deferred']);
        $this->assertSame([], Fake_WP_CLI::$deferred);
        $this->assertSame(0, Cli_Walker::resolve_deferred(Fake_WP_CLI::class));
        $this->assertSame(0, Cli_Walker::resolve_deferred('No_Such_Class'));
    }

    public function test_premium_only_method_is_pro_and_header_paths_are_pro()
    {
        $scope = $this->scope();

        $this->assertTrue($scope->is_pro_callable($scope->describe([self::$own, 'send__premium_only'])));
        $this->assertFalse($scope->is_pro_callable($scope->describe([self::$own, 'run'])));
        $this->assertTrue($scope->is_pro_callable($scope->describe([new \FixesPlugin\Pro\Sender(), 'send'])));
    }

    public function test_wp_kit_closure_is_attributed_to_the_service_method()
    {
        $scope = $this->scope();
        $info  = $scope->describe($this->kit(self::$own, 'send__premium_only'));

        $this->assertSame('FixesPlugin\Own::send__premium_only', $info['name']);
        $this->assertTrue($scope->owns($info['file']));
        $this->assertTrue($scope->is_pro_callable($info));
        $this->assertTrue($scope->is_pro_callable($scope->describe($this->kit(new \FixesPlugin\Pro\Sender(), 'send'))));
        $this->assertFalse($scope->is_pro_callable($scope->describe($this->kit(self::$own, 'run'))));
    }

    public function test_wp_kit_service_override_wins_over_the_resource_service()
    {
        $closure = $this->kit(self::$bundled, 'run', 'x', self::$own);

        $this->assertSame([self::$own, 'run'], Kit_Unwrap::callable($closure));
        $this->assertSame('plain', Kit_Unwrap::callable('plain'));
    }

    public function test_bundled_vendor_items_are_excluded_from_every_surface()
    {
        $scope  = $this->scope();
        $routes = [
            '/p/v1/own'            => [['methods' => 'GET', 'callback' => $this->kit(self::$own, 'run'), 'permission_callback' => $this->kit(self::$own, 'run')]],
            '/mmwp/v1/support/ping' => [['methods' => 'GET', 'callback' => $this->kit(self::$bundled, 'ping'), 'permission_callback' => $this->kit(self::$bundled, 'ping')]],
            '/mmwp/v1/support/raw' => [['methods' => 'GET', 'callback' => [self::$bundled, 'ping']]],
        ];

        $this->assertFalse($scope->owns((new \ReflectionMethod(self::$bundled, 'ping'))->getFileName()));
        $this->assertSame(['/p/v1/own'], array_column(Rest_Walker::walk($routes, ['p/v1', 'mmwp/v1'], $scope), 'route'));

        $abilities = [
            new Fake_Ability(['name' => 'p/own', 'category' => 'p'], $this->kit(self::$own, 'run'), $this->kit(self::$own, 'run')),
            new Fake_Ability(['name' => 'p/support-ping', 'category' => 'p'], $this->kit(self::$bundled, 'ping'), $this->kit(self::$bundled, 'ping')),
            new Fake_Ability(['name' => 'mmwp/support-raw', 'category' => 'mmwp'], [self::$bundled, 'ping'], '__return_true'),
        ];

        $this->assertSame(['p/own'], array_column(Abilities_Walker::walk($abilities, 'p', $scope), 'name'));
    }

    public function test_wp_kit_permission_reads_the_declared_capability()
    {
        $scope = $this->scope();
        $read  = function ($resolver) use ($scope) {
            return Permission::summarize($this->kit(self::$own, 'run', $resolver), $scope);
        };

        $this->assertSame(['callback' => 'Fixtures\Fake_Kit_Operation::authorize', 'capability' => 'manage_woocommerce', 'public' => false], $this->trim_class($read('manage_woocommerce')));
        $this->assertTrue($read(true)['public']);
        $this->assertNull($read(true)['capability']);
        $this->assertNull($read(false)['capability']);
        $this->assertNull($read(null)['capability']);
    }

    public function test_wp_kit_computed_capability_is_read_from_the_resolver_source()
    {
        $resolver = [self::$own, 'can'];
        $summary  = Permission::summarize($this->kit(self::$own, 'run', $resolver), $this->scope());

        $this->assertSame('manage_woocommerce', $summary['capability']);
    }

    public function test_wp_kit_permission_in_rest_and_abilities_entries()
    {
        $scope = $this->scope();
        $rest  = Rest_Walker::walk(['/p/v1/own' => [['methods' => 'GET', 'callback' => $this->kit(self::$own, 'run'), 'permission_callback' => $this->kit(self::$own, 'run', 'edit_posts')]]], ['p/v1'], $scope);
        $abil  = Abilities_Walker::walk([new Fake_Ability(['name' => 'p/own'], $this->kit(self::$own, 'run'), $this->kit(self::$own, 'run', 'edit_posts'))], 'p', $scope);

        $this->assertSame('edit_posts', $rest[0]['permission']['capability']);
        $this->assertSame('edit_posts', $abil[0]['permission']['capability']);
    }

    /**
     * Drop the namespace of the fake operation class from a permission summary.
     *
     * @param array $summary Summary.
     *
     * @return array
     */
    private function trim_class(array $summary)
    {
        $summary['callback'] = str_replace('MammothWP\SelfTest\Tests\\', '', $summary['callback']);

        return $summary;
    }
}
