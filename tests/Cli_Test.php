<?php
/**
 * `wp mmwp self-test` dispatch and the deprecated `wp mwp` alias.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Registry;
use MammothWP\SelfTest\Runner;
use MammothWP\SelfTest\Surfaces\Cli;
use PHPUnit\Framework\TestCase;
use WP_CLI\StubRecorder;

final class Cli_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();

        $registry = (new Registry())->register('demo-plugin', __DIR__ . '/Fixtures/manifest.json');
        Cli::init(new Runner($registry));
    }

    /**
     * @return string[] Registered command names.
     */
    private function commands()
    {
        return array_column(StubRecorder::$commands, 'command');
    }

    public function test_new_command_and_deprecated_alias_are_registered()
    {
        $commands = $this->commands();

        $this->assertContains('mmwp self-test', $commands);
        foreach (['list', 'preview', 'run', 'cleanup', 'report', 'migrate'] as $action) {
            $this->assertContains('mwp self-test ' . $action, $commands);
        }
    }

    public function test_slug_positional_selects_the_plugin()
    {
        Cli::dispatch(['demo-plugin'], ['format' => 'table']);

        $this->assertStringContainsString('demo-plugin/demo', implode("\n", StubRecorder::$log));
    }

    public function test_unknown_slug_is_reported_as_an_unknown_plugin()
    {
        $this->expectException(\WP_CLI\ExitException::class);
        $this->expectExceptionMessage('No self-test manifest is registered for "nope"');

        Cli::dispatch(['nope'], []);
    }

    public function test_action_with_slug_forwards_the_slug()
    {
        Cli::dispatch(['list', 'demo-plugin'], ['format' => 'json']);

        $this->assertSame('demo', StubRecorder::$printed[0]['items'][0]['ID']);
    }

    public function test_legacy_command_warns_once_and_forwards()
    {
        Cli::legacy_list([], ['plugin' => 'demo-plugin']);

        $this->assertCount(1, StubRecorder::$warnings);
        $this->assertStringContainsString('deprecated', StubRecorder::$warnings[0]);
        $this->assertStringContainsString('wp mmwp self-test list', StubRecorder::$warnings[0]);
        $this->assertSame('demo', StubRecorder::$printed[0]['items'][0]['ID']);
    }

    public function test_migrate_dry_run_does_not_write()
    {
        update_option('mwp_self_test_state', ['a' => 1]);

        Cli::dispatch(['migrate'], ['dry-run' => true]);

        $this->assertNotNull(get_option('mwp_self_test_state', null));
        $this->assertSame('mwp_self_test_state', StubRecorder::$printed[0]['items'][0]['from']);
    }
}
