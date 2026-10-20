<?php
/**
 * REST and Abilities names.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Registry;
use MammothWP\SelfTest\Runner;
use MammothWP\SelfTest\Surfaces\Abilities;
use MammothWP\SelfTest\Surfaces\Rest;
use PHPUnit\Framework\TestCase;

final class Surfaces_Test extends TestCase
{
    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_rest_routes_live_under_mmwp_v1()
    {
        (new Rest(new Runner(new Registry())))->register_routes();

        $routes = \Wp_Stub_Registry::$routes;
        foreach (['cases', 'preview', 'run', 'cleanup', 'report'] as $route) {
            $this->assertContains('mmwp/v1/self-test/' . $route, $routes);
        }
    }

    public function test_abilities_use_the_mmwp_names()
    {
        Abilities::init(new Runner(new Registry()));
        Abilities::register_abilities();

        $names = array_keys(\Wp_Stub_Registry::$abilities);
        foreach (['list-cases', 'preview', 'run', 'cleanup', 'get-report'] as $verb) {
            $this->assertContains('mmwp/self-test-' . $verb, $names);
        }
        $this->assertSame('mmwp-self-test', \Wp_Stub_Registry::$abilities['mmwp/self-test-run']['category']);
    }
}
