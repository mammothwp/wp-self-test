<?php
/**
 * Admin menu inventory: URLs, exclusions, permission and gating.
 *
 * @package MammothWP\SelfTest
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited -- the tests stand in for wp-admin's menu globals.

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Browser\Menu_Inventory;
use MammothWP\SelfTest\Registry;
use MammothWP\SelfTest\Runner;
use MammothWP\SelfTest\Surfaces\Rest;
use PHPUnit\Framework\TestCase;

final class Menu_Inventory_Test extends TestCase
{
    /** @var array */
    private $menu = [
        ['Dashboard', 'read', 'index.php', '', 'menu-top', 'menu-dashboard'],
        ['', 'read', 'separator1', '', 'wp-menu-separator'],
        ['Posts <span class="count">3</span>', 'edit_posts', 'edit.php', '', 'menu-top'],
        ['Coupons', 'manage_options', 'ewp-coupons', '', 'menu-top'],
        ['External', 'read', 'https://example.org/docs', '', 'menu-top'],
        ['Appearance', 'switch_themes', 'themes.php', '', 'menu-top'],
    ];

    /** @var array */
    private $submenu = [
        'index.php'    => [['Home', 'read', 'index.php'], ['Updates', 'update_core', 'update-core.php']],
        'edit.php'     => [['All Posts', 'edit_posts', 'edit.php'], ['Add New', 'edit_posts', 'post-new.php'], ['Categories', 'manage_categories', 'edit-tags.php?taxonomy=category']],
        'ewp-coupons'  => [['All', 'manage_options', 'ewp-coupons'], ['Settings', 'manage_options', 'ewp-coupon-settings'], ['Trash', 'manage_options', 'admin.php?page=x&action=empty&_wpnonce=abc']],
        'themes.php'   => [['Themes', 'switch_themes', 'themes.php'], ['Customize', 'customize', 'customize.php?return=%2Fwp-admin%2F']],
    ];

    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
    }

    public function test_builds_urls_and_strips_markup()
    {
        $result = Menu_Inventory::pages($this->menu, $this->submenu, 'https://example.test/wp-admin', Menu_Inventory::DEFAULT_EXCLUSIONS);
        $paths  = array_column($result['pages'], 'path', 'title');

        $this->assertSame('index.php', $paths['Dashboard']);
        $this->assertSame('edit.php', $paths['Posts 3']);
        $this->assertSame('admin.php?page=ewp-coupons', $paths['Coupons']);
        $this->assertSame('admin.php?page=ewp-coupon-settings', $paths['Settings']);
        $this->assertSame('edit-tags.php?taxonomy=category', $paths['Categories']);
        $this->assertSame('https://example.test/wp-admin/admin.php?page=ewp-coupons', $result['pages'][3]['url']);
    }

    public function test_default_exclusions_and_dedupe()
    {
        $result = Menu_Inventory::pages($this->menu, $this->submenu, 'https://example.test/wp-admin/', Menu_Inventory::DEFAULT_EXCLUSIONS);
        $paths  = array_column($result['pages'], 'path');

        $this->assertSame(count($paths), count(array_unique($paths)));
        foreach (['update-core.php', 'post-new.php', 'customize.php?return=%2Fwp-admin%2F'] as $unsafe) {
            $this->assertNotContains($unsafe, $paths);
            $this->assertContains($unsafe, $result['excluded']);
        }
        foreach ($paths as $path) {
            $this->assertStringNotContainsString('action=', $path);
            $this->assertStringNotContainsString('nonce', $path);
            $this->assertStringNotContainsString('example.org', $path);
        }
    }

    public function test_filter_extends_the_exclusions()
    {
        add_filter('mmwp_self_test_admin_menu_exclusions', function ($needles) {
            $needles[] = 'ewp-coupon-settings';
            return $needles;
        });

        $result = Menu_Inventory::pages($this->menu, $this->submenu, 'https://example.test/wp-admin/', Menu_Inventory::exclusions());

        $this->assertNotContains('admin.php?page=ewp-coupon-settings', array_column($result['pages'], 'path'));
        $this->assertContains('admin.php?page=ewp-coupon-settings', $result['excluded']);
    }

    public function test_current_prefers_the_admin_request_snapshot()
    {
        $GLOBALS['menu']    = $this->menu;
        $GLOBALS['submenu'] = $this->submenu;
        Menu_Inventory::snapshot();
        $GLOBALS['menu']    = [];
        $GLOBALS['submenu'] = [];

        $current = Menu_Inventory::current();

        $this->assertSame('snapshot', $current['source']);
        $this->assertNotEmpty($current['pages']);
    }

    public function test_current_falls_back_to_the_live_menu()
    {
        $GLOBALS['menu']    = [['Tools', 'read', 'tools.php', '']];
        $GLOBALS['submenu'] = [];

        $current = Menu_Inventory::current();

        $this->assertSame('live', $current['source']);
        $this->assertSame(['tools.php'], array_column($current['pages'], 'path'));
    }

    public function test_route_is_registered_and_gated_like_the_dashboard_routes()
    {
        $rest = new Rest(new Runner(new Registry()));
        $rest->register_routes();
        $this->assertContains('mmwp/v1/self-test/admin-menu', \Wp_Stub_Registry::$routes);

        $this->assertTrue($rest->check_permission());

        \Wp_Stub_State::$environment = 'production';
        $denied = $rest->check_permission();
        $this->assertInstanceOf(\WP_Error::class, $denied);
        $this->assertSame('mmwp_self_test_disabled', $denied->get_error_code());
    }
}
