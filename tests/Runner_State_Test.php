<?php
/**
 * Runner reads its report from the state option.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Registry;
use MammothWP\SelfTest\Runner;
use PHPUnit\Framework\TestCase;

final class Runner_State_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_report_is_read_from_the_state_option()
    {
        update_option('mmwp_self_test_state', ['report' => ['from' => 'new']]);

        $this->assertSame(['from' => 'new'], (new Runner(new Registry()))->report());
    }

    public function test_report_is_null_without_state()
    {
        $this->assertNull((new Runner(new Registry()))->report());
    }
}
