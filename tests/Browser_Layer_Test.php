<?php
/**
 * Manifest parsing of `browser` checks and the runner's skip/fold behaviour.
 *
 * @package MammothWP\SelfTest
 */

// phpcs:disable WordPress.WP.AlternativeFunctions,WordPress.PHP.DiscouragedPHPFunctions -- the tests write fixture files in a temp dir.

namespace MammothWP\SelfTest\Tests;

use MammothWP\SelfTest\Browser\Browser_Runner;
use MammothWP\SelfTest\Manifest;
use MammothWP\SelfTest\Registry;
use MammothWP\SelfTest\Runner;
use PHPUnit\Framework\TestCase;

final class Browser_Layer_Test extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        \Wp_Stub_State::reset();
        $this->dir = sys_get_temp_dir() . '/mmwp-browser-' . uniqid();
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    private function manifest(array $case)
    {
        $case += ['id' => 'smoke', 'label' => 'Smoke', 'class' => \MammothWP\SelfTest\Browser_Case::class];
        file_put_contents($this->dir . '/manifest.json', json_encode(['version' => 1, 'cases' => [$case]]));

        return new Manifest($this->dir . '/manifest.json', 'demo');
    }

    private function install_package()
    {
        $package = $this->dir . '/node_modules/' . Browser_Runner::PACKAGE;
        mkdir($package, 0777, true);
        file_put_contents($package . '/package.json', '{}');
    }

    private function registry()
    {
        return (new Registry())->register('demo', $this->dir . '/manifest.json');
    }

    public function test_browser_entry_is_normalised_and_adds_the_layer()
    {
        $definitions = $this->manifest(['layers' => ['rest'], 'browser' => ['crawl' => true]])->definitions();

        $this->assertSame(['rest', 'browser'], $definitions['smoke']['layers']);
        $this->assertTrue($definitions['smoke']['browser']['crawl']);
        $this->assertSame([], $definitions['smoke']['browser']['specs']);
        $this->assertSame(['console' => [], 'requests' => [], 'pages' => []], $definitions['smoke']['browser']['allow']);
        $this->assertSame($this->dir, $definitions['smoke']['manifest_dir']);
    }

    public function test_manifests_without_browser_stay_valid_and_unchanged()
    {
        $definitions = $this->manifest(['layers' => ['rest']])->definitions();

        $this->assertSame(['rest'], $definitions['smoke']['layers']);
        $this->assertArrayNotHasKey('browser', $definitions['smoke']);
    }

    /** @dataProvider invalid_browser_entries */
    public function test_invalid_browser_entries_are_rejected($browser)
    {
        $this->assertInstanceOf(\WP_Error::class, $this->manifest(['browser' => $browser])->load());
    }

    public function invalid_browser_entries()
    {
        return [
            'empty object'    => [new \stdClass()],
            'list'            => [['crawl']],
            'no checks'       => [['allow' => ['console' => ['x']]]],
            'unknown key'     => [['crawl' => true, 'login' => 'admin']],
            'crawl not bool'  => [['crawl' => 'yes']],
            'absolute spec'   => [['specs' => ['/etc/passwd']]],
            'parent spec'     => [['specs' => ['../x.spec.js']]],
            'specs not list'  => [['specs' => 'a.spec.js']],
            'bad timeout'     => [['crawl' => true, 'timeout' => 0]],
            'bad allow key'   => [['crawl' => true, 'allow' => ['cookies' => ['x']]]],
            'bad allow value' => [['crawl' => true, 'allow' => ['console' => 'x']]],
        ];
    }

    public function test_cases_list_the_browser_layer()
    {
        $this->manifest(['browser' => ['specs' => ['e2e/a.spec.js']]]);
        $cases = (new Runner($this->registry()))->cases();

        $this->assertSame(['browser'], $cases[0]['layers']);
    }

    public function test_missing_package_skips_the_case_instead_of_failing()
    {
        $this->manifest(['browser' => ['crawl' => true]]);
        $report = (new Runner($this->registry(), new Browser_Runner($this->executor(0))))->run();

        $this->assertSame('skipped', $report['results'][0]['status']);
        $this->assertSame('browser', $report['results'][0]['checks'][0]['layer']);
        $this->assertStringContainsString('not installed', $report['results'][0]['checks'][0]['detail']);
    }

    public function test_missing_node_skips_the_case()
    {
        $this->install_package();
        $this->manifest(['browser' => ['crawl' => true]]);
        $report = (new Runner($this->registry(), new Browser_Runner($this->executor(127))))->run();

        $this->assertSame('skipped', $report['results'][0]['status']);
        $this->assertStringContainsString('Node.js was not found', $report['results'][0]['checks'][0]['detail']);
    }

    public function test_production_skips_and_never_issues_a_token()
    {
        $this->install_package();
        $this->manifest(['browser' => ['crawl' => true]]);
        \Wp_Stub_State::$environment = 'production';
        $report = (new Runner($this->registry(), new Browser_Runner($this->executor(0))))->run();

        $this->assertSame('skipped', $report['results'][0]['status']);
        $this->assertArrayNotHasKey('mmwp_self_test_login_tokens', \Wp_Stub_State::$options);
    }

    public function test_results_are_folded_into_the_report_and_the_session_is_revoked()
    {
        $this->install_package();
        $this->manifest(['browser' => ['crawl' => true]]);
        $seen     = [];
        $executor = function (array $command, array $env) use (&$seen) {
            if ($command[1] === '--version') {
                return ['exit' => 0, 'stdout' => 'v22', 'stderr' => ''];
            }
            $seen = $env;
            file_put_contents($env['MMWP_E2E_RESULTS'], json_encode(['checks' => [
                ['label' => 'Crawl: 12 pages', 'status' => 'pass', 'detail' => ''],
                ['label' => 'Crawl: /wp-admin/x.php', 'status' => 'fail', 'detail' => 'Fatal error'],
            ],]));
            return ['exit' => 1, 'stdout' => '', 'stderr' => ''];
        };

        $report = (new Runner($this->registry(), new Browser_Runner($executor)))->run([], false, '', 'browser');
        $result = $report['results'][0];

        $this->assertSame('fail', $result['status']);
        $this->assertSame(['browser', 'browser'], array_column($result['checks'], 'layer'));
        $this->assertSame('browser', $report['layer']);
        $this->assertSame(64, strlen($seen['MMWP_E2E_LOGIN_TOKEN']));
        $this->assertArrayNotHasKey('mmwp_self_test_login_tokens', \Wp_Stub_State::$options, 'tokens are revoked after the run');
        $this->assertFileDoesNotExist($seen['MMWP_E2E_CONFIG']);
    }

    public function test_a_crash_without_results_is_a_failure()
    {
        $checks = Browser_Runner::checks_from_results('', ['exit' => 2, 'stdout' => '', 'stderr' => 'boom']);

        $this->assertSame('fail', $checks[0]['status']);
        $this->assertStringContainsString('boom', $checks[0]['detail']);
    }

    public function test_layer_filter_skips_browser_for_other_layers()
    {
        $this->install_package();
        $this->manifest(['layers' => ['core'], 'browser' => ['crawl' => true]]);
        $called   = false;
        $executor = function () use (&$called) {
            $called = true;
            return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
        };
        $report = (new Runner($this->registry(), new Browser_Runner($executor)))->run([], false, '', 'core');

        $this->assertFalse($called);
        $this->assertSame(1, $report['summary']['total']);
        $this->assertSame([], $report['results'][0]['checks']);
    }

    private function executor($exit)
    {
        return function () use ($exit) {
            return ['exit' => $exit, 'stdout' => '', 'stderr' => ''];
        };
    }
}
