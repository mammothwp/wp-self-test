<?php
/**
 * One-time option migration.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Migration;
use PHPUnit\Framework\TestCase;

final class Migration_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_dry_run_reports_without_writing()
    {
        update_option('mwp_self_test_state', ['report' => ['a' => 1]]);

        $rows = Migration::run(true);

        $this->assertCount(1, $rows);
        $this->assertSame('mmwp_self_test_state', $rows[0]['to']);
        $this->assertNotNull(get_option('mwp_self_test_state', null));
        $this->assertNull(get_option('mmwp_self_test_state', null));
    }

    public function test_run_moves_the_option_and_is_idempotent()
    {
        update_option('mwp_self_test_state', ['pending' => ['x' => []]]);

        $this->assertCount(1, Migration::run());
        $this->assertSame(['pending' => ['x' => []]], get_option('mmwp_self_test_state'));
        $this->assertNull(get_option('mwp_self_test_state', null));
        $this->assertSame([], Migration::run());
    }

    public function test_existing_new_value_is_never_overwritten()
    {
        update_option('mwp_self_test_state', ['old' => true]);
        update_option('mmwp_self_test_state', ['new' => true]);

        Migration::run();

        $this->assertSame(['new' => true], get_option('mmwp_self_test_state'));
        $this->assertNull(get_option('mwp_self_test_state', null));
    }

    public function test_maybe_run_executes_once()
    {
        update_option('mwp_self_test_state', ['a' => 1]);
        Migration::maybe_run();
        $this->assertNotNull(get_option(Migration::DONE_OPTION, null));

        update_option('mwp_self_test_state', ['b' => 2]);
        Migration::maybe_run();
        $this->assertSame(['b' => 2], get_option('mwp_self_test_state'));
    }
}
