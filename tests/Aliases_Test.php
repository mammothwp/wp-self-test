<?php
/**
 * class_alias() shims for the pre-1.0 Gnnpls\SelfTest namespace.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests;

use PHPUnit\Framework\TestCase;

final class Aliases_Test extends TestCase
{
    /**
     * @return array<string,array{0:string}> Every class below src/ except the shim file.
     */
    public function public_classes()
    {
        $root = dirname(__DIR__) . '/src/';
        $out  = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $relative = substr(str_replace('/', '\\', substr($file->getPathname(), strlen($root))), 0, -4);
            if ($relative !== 'Wp_Cli_Shim' && $relative !== 'Loader') {
                $out[$relative] = [$relative];
            }
        }

        return $out;
    }

    /**
     * @dataProvider public_classes
     *
     * @param string $relative Class name below the namespace root.
     */
    public function test_old_class_name_resolves_to_the_new_class($relative)
    {
        $old = 'Gnnpls\\SelfTest\\' . $relative;
        $new = 'MammothWP\\SelfTest\\' . $relative;

        $this->assertTrue(class_exists($old), $old);
        $this->assertSame($new, (new \ReflectionClass($old))->getName());
    }

    public function test_old_case_base_can_still_be_extended()
    {
        $case = new Fixtures\Legacy_Case();

        $this->assertInstanceOf(\MammothWP\SelfTest\Case_Base::class, $case);
    }

    public function test_each_old_name_is_noticed_once_per_site()
    {
        \Wp_Stub_State::reset();
        \mmwp_self_test_legacy_notice('Gnnpls\\SelfTest\\Config', 'MammothWP\\SelfTest\\Config');
        $first = get_option('mmwp_self_test_legacy_notices');
        \mmwp_self_test_legacy_notice('Gnnpls\\SelfTest\\Config', 'MammothWP\\SelfTest\\Config');

        $this->assertSame(['Gnnpls\\SelfTest\\Config'], $first);
        $this->assertSame($first, get_option('mmwp_self_test_legacy_notices'));
    }

    public function test_unrelated_classes_are_ignored()
    {
        $this->assertFalse(class_exists('Gnnpls\\SelfTest\\Does_Not_Exist'));
        $this->assertFalse(class_exists('Other\\Vendor\\Thing'));
    }
}
