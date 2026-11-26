<?php
/**
 * `wp mmwp reference`: walkers (unit) and the fixture plugin (integration).
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Reference\Abilities_Walker;
use MammothWP\SelfTest\Reference\Canonical;
use MammothWP\SelfTest\Reference\Cli_Walker;
use MammothWP\SelfTest\Reference\Plugin_Scope;
use MammothWP\SelfTest\Reference\Pro_Paths;
use MammothWP\SelfTest\Reference\Reference;
use MammothWP\SelfTest\Reference\Rest_Walker;
use MammothWP\SelfTest\Reference\Synopsis;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Ability;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Composite;
use MammothWP\SelfTest\Tests\Fixtures\Fake_Subcommand;
use PHPUnit\Framework\TestCase;
use ReferencePlugin\Free_Api;
use ReferencePlugin\Pro\Pro_Api;

require_once __DIR__ . '/Fixtures/Reference_Fakes.php';
require_once __DIR__ . '/Fixtures/reference-plugin/includes/Free_Api.php';
require_once __DIR__ . '/Fixtures/reference-plugin/pro/Pro_Api.php';

final class Reference_Test extends TestCase
{
    private function dir()
    {
        return __DIR__ . '/Fixtures/reference-plugin';
    }

    private function scope()
    {
        $dir = $this->dir();

        return new Plugin_Scope($dir, Pro_Paths::from_plugin($dir, $dir . '/reference-plugin.php'));
    }

    private function routes()
    {
        $free = new Free_Api();

        return [
            '/ref/v1'         => [['methods' => ['GET' => true], 'callback' => '__return_true']],
            '/ref/v1/items'   => [
                [
                    'methods'             => ['GET' => true, 'HEAD' => true],
                    'callback'            => [$free, 'items'],
                    'permission_callback' => [$free, 'can_read'],
                    'args'                => [
                        'status' => ['type' => 'string', 'enum' => ['a', 'b'], 'default' => 'a', 'sanitize_callback' => 'sanitize_key'],
                        'id'     => ['type' => 'integer', 'required' => true, 'description' => 'Item id.', 'validate_callback' => function () {
                            return true;
                        },],
                    ],
                ],
            ],
            '/ref/v1/export'  => [
                [
                    'methods'             => 'POST',
                    'callback'            => [new Pro_Api(), 'export'],
                    'permission_callback' => [new Pro_Api(), 'can_manage'],
                ],
            ],
            '/other/v1/thing' => [['methods' => ['GET' => true], 'callback' => 'strlen']],
        ];
    }

    private function cli_root()
    {
        $free = new Free_Api();

        return new Fake_Composite('wp', [
            new Fake_Composite('ref', [
                new Fake_Subcommand('sync', [$free, 'sync'], '<id> [--format=<format>] [--dry-run]', 'Sync items.', "## OPTIONS\n\n<id>\n: Item id.\n\n[--format=<format>]\n: Output format.\n---\ndefault: table\noptions:\n  - table\n  - json\n---\n\n[--dry-run]\n: Do not write.\n"),
                new Fake_Subcommand('export-all', [new Pro_Api(), 'export_all'], '', 'Export everything.', ''),
            ]),
            new Fake_Composite('core', [new Fake_Subcommand('version', 'strlen', '', 'Core.', '')]),
        ]);
    }

    private function abilities()
    {
        return [
            new Fake_Ability(['name' => 'ref/list-items', 'label' => 'List', 'category' => 'ref', 'input_schema' => ['type' => 'object'], 'meta' => ['annotations' => ['readonly' => true]]], [new Free_Api(), 'ability'], [new Free_Api(), 'can_read']),
            new Fake_Ability(['name' => 'core/other', 'label' => 'Other'], 'strlen', '__return_true'),
        ];
    }

    private function build()
    {
        return Reference::build('reference-plugin', '3.4.5', $this->scope(), $this->routes(), ['ref/v1', 'other/v1'], $this->cli_root(), $this->abilities());
    }

    public function test_rest_walker_keeps_only_plugin_callbacks_per_method()
    {
        $rows = Rest_Walker::walk($this->routes(), ['ref/v1', 'other/v1'], $this->scope());

        $this->assertSame(['POST', 'GET', 'HEAD'], array_column($rows, 'method'));
        $this->assertSame(['/ref/v1/export', '/ref/v1/items', '/ref/v1/items'], array_column($rows, 'route'));
        $this->assertSame('ref/v1', $rows[0]['namespace']);
        $this->assertNotContains('/other/v1/thing', array_column($rows, 'route'));
    }

    public function test_rest_args_and_permission_summary()
    {
        $rows = Rest_Walker::walk($this->routes(), ['ref/v1'], $this->scope());
        $get  = array_values(array_filter($rows, function ($row) {
            return 'GET' === $row['method'];
        }))[0];

        $this->assertSame(['id', 'status'], array_column($get['args'], 'name'));
        $this->assertTrue($get['args'][0]['required']);
        $this->assertSame(['a', 'b'], $get['args'][1]['enum']);
        $this->assertArrayNotHasKey('sanitize_callback', $get['args'][1]);
        $this->assertSame(['callback' => 'ReferencePlugin\Free_Api::can_read', 'capability' => 'edit_posts', 'public' => false], $get['permission']);
    }

    public function test_namespace_of_prefers_the_longest_match()
    {
        $this->assertSame('a/v1', Rest_Walker::namespace_of('/a/v1/x', ['a', 'a/v1']));
        $this->assertSame('', Rest_Walker::namespace_of('/ab/x', ['a']));
    }

    public function test_cli_walker_builds_paths_and_parses_synopsis()
    {
        $rows = Cli_Walker::walk($this->cli_root(), $this->scope());

        $this->assertSame(['ref export-all', 'ref sync'], array_column($rows, 'command'));
        $this->assertSame([true, false], array_column($rows, 'pro'));

        $sync = $rows[1];

        $this->assertSame('Sync items.', $sync['shortdesc']);
        $this->assertSame(['positional', 'assoc', 'flag'], array_column($sync['synopsis'], 'type'));
        $this->assertSame('table', $sync['synopsis'][1]['default']);
        $this->assertSame(['table', 'json'], $sync['synopsis'][1]['options']);
        $this->assertSame('Item id.', $sync['synopsis'][0]['description']);
        $this->assertTrue($sync['synopsis'][1]['optional']);
    }

    public function test_synopsis_tokens()
    {
        $this->assertSame(['type' => 'positional', 'name' => 'id', 'optional' => true, 'repeating' => true], Synopsis::token('[<id>...]'));
        $this->assertSame(['type' => 'generic', 'name' => 'field', 'optional' => true, 'repeating' => false], Synopsis::token('[--<field>=<value>]'));
        $this->assertNull(Synopsis::token('???'));
    }

    public function test_abilities_walker_matches_by_callback_file_prefix_and_category()
    {
        $rows = Abilities_Walker::walk($this->abilities(), 'ref', $this->scope());

        $this->assertSame(['ref/list-items'], array_column($rows, 'name'));
        $this->assertSame(['annotations' => ['readonly' => true]], $rows[0]['meta']);
        $this->assertNull($rows[0]['output_schema']);
        $this->assertSame('edit_posts', $rows[0]['permission']['capability']);

        $by_prefix = [new Fake_Ability(['name' => 'ref/x'], 'strlen', null)];
        $this->assertCount(1, Abilities_Walker::walk($by_prefix, 'ref', $this->scope()));
    }

    public function test_pro_paths_sources_and_matching()
    {
        $pro = Pro_Paths::from_plugin($this->dir(), $this->dir() . '/reference-plugin.php');

        $this->assertSame(['premium-only.php', 'pro/', 'vendor/pro-lib'], $pro->patterns());
        $this->assertTrue($pro->matches('pro/Pro_Api.php'));
        $this->assertTrue($pro->matches('premium-only.php'));
        $this->assertTrue($pro->matches('vendor/pro-lib/a/b.php'));
        $this->assertFalse($pro->matches('includes/Free_Api.php'));
        $this->assertFalse($pro->matches('professional/x.php'));
        $this->assertTrue((new Pro_Paths(['includes/*.php']))->matches('includes/Free_Api.php'));
        $this->assertFalse((new Pro_Paths())->matches('anything.php'));
    }

    public function test_plugin_without_manifest_has_no_pro_items()
    {
        $this->assertSame([], Pro_Paths::from_plugin(__DIR__, '')->patterns());
    }

    public function test_canonical_sorts_keys_keeps_lists_and_scrubs_paths()
    {
        $out = Canonical::normalize(['b' => 1, 'a' => ['z' => '/srv/p/x.php', 'l' => [3, 1]], 'o' => new \stdClass()], ['/srv/p' => '{plugin}']);

        $this->assertSame(['a', 'b'], array_keys($out));
        $this->assertSame(['l', 'z'], array_keys($out['a']));
        $this->assertSame([3, 1], $out['a']['l']);
        $this->assertSame('{plugin}/x.php', $out['a']['z']);
    }

    public function test_fixture_plugin_reference()
    {
        $doc = $this->build();

        $this->assertSame(1, $doc['schema']);
        $this->assertSame('reference-plugin', $doc['plugin']);
        $this->assertSame('3.4.5', $doc['version']);
        $this->assertSame([true, false, false], array_column($doc['rest'], 'pro'));
        $this->assertSame([true, false], array_column($doc['cli'], 'pro'));
        $this->assertSame([false], array_column($doc['abilities'], 'pro'));
        $this->assertSame('POST', $doc['rest'][0]['method']);
        $this->assertSame('manage_options', $doc['rest'][0]['permission']['capability']);
    }

    public function test_output_is_byte_identical_and_free_of_absolute_paths()
    {
        $first  = Canonical::encode($this->build());
        $second = Canonical::encode($this->build());

        $this->assertSame($first, $second);
        $this->assertStringNotContainsString($this->dir(), $first);
        $this->assertStringNotContainsString(dirname(__DIR__), $first);
    }

    public function test_reference_matches_the_json_schema_shape()
    {
        $schema = json_decode((string) file_get_contents(dirname(__DIR__) . '/schema/reference.v1.json'), true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
        $doc    = json_decode(Canonical::encode($this->build()), true);

        $this->assertSame($this->sorted($schema['required']), array_keys($doc));

        foreach (['rest' => 'rest', 'cli' => 'cli', 'abilities' => 'ability'] as $section => $def) {
            foreach ($doc[$section] as $item) {
                $this->assertSame($this->sorted($schema['$defs'][$def]['required']), array_keys($item));
            }
        }
    }

    public function test_version_and_main_file_are_read_from_headers()
    {
        $main = Reference::main_file($this->dir());

        $this->assertSame($this->dir() . '/reference-plugin.php', $main);
        $this->assertSame('3.4.5', Reference::version($main));
        $this->assertSame('', Reference::main_file(__DIR__ . '/Fixtures/reference-plugin/includes'));
    }

    private function sorted(array $keys)
    {
        sort($keys, SORT_STRING);

        return $keys;
    }
}
