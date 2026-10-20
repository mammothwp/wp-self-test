<?php
/**
 * Deprecated hook bridge.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Compat\Hooks;
use PHPUnit\Framework\TestCase;

final class Hooks_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_legacy_name_swaps_the_prefix()
    {
        $this->assertSame('mwp_self_test_register', Hooks::legacy_name('mmwp_self_test_register'));
    }

    public function test_action_reaches_new_and_deprecated_listeners()
    {
        $seen = [];
        add_action('mmwp_self_test_completed', function ($r) use (&$seen) {
            $seen[] = 'new:' . $r;
        });
        add_action('mwp_self_test_completed', function ($r) use (&$seen) {
            $seen[] = 'old:' . $r;
        });

        Hooks::action('mmwp_self_test_completed', ['x']);

        $this->assertSame(['new:x', 'old:x'], $seen);
        $this->assertSame([['mwp_self_test_completed', 'mmwp_self_test_completed']], \Wp_Stub_State::$deprecated);
    }

    public function test_filter_chains_new_then_deprecated_and_extra_args()
    {
        add_filter('mmwp_self_test_capability', function ($v, $extra) {
            return $v . '+new' . $extra;
        });
        add_filter('mwp_self_test_capability', function ($v, $extra) {
            return $v . '+old' . $extra;
        });

        $this->assertSame('base+new1+old1', Hooks::filter('mmwp_self_test_capability', 'base', [1]));
    }

    public function test_no_deprecation_logged_without_old_listeners()
    {
        $this->assertSame('v', Hooks::filter('mmwp_self_test_capability', 'v'));
        $this->assertSame([], \Wp_Stub_State::$deprecated);
    }
}
