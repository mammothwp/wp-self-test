<?php
/**
 * Runner reads the pre-1.0 state option until Migration moves it.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Compat\Legacy;
use MammothWP\SelfTest\Registry;
use MammothWP\SelfTest\Runner;
use PHPUnit\Framework\TestCase;

final class Runner_State_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_legacy_state_option_is_read_as_fallback()
    {
        update_option('mwp_self_test_state', ['report' => ['results' => [], 'summary' => null]]);

        $runner = new Runner(new Registry());

        $this->assertSame(['results' => [], 'summary' => null], $runner->report());
    }

    public function test_new_state_option_wins_over_the_legacy_one()
    {
        update_option('mwp_self_test_state', ['report' => ['from' => 'old']]);
        update_option('mmwp_self_test_state', ['report' => ['from' => 'new']]);

        $this->assertSame(['from' => 'new'], (new Runner(new Registry()))->report());
    }

    public function test_aliased_classes_do_not_count_as_owned_elsewhere()
    {
        class_exists('Gnnpls\\SelfTest\\Config');

        $this->assertFalse(Legacy::owned_elsewhere('Config'));
        $this->assertFalse(Legacy::owned_elsewhere('Not_There'));
    }
}
