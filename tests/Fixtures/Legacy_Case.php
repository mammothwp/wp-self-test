<?php
/**
 * A consumer case written against the pre-1.0 namespace.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests\Fixtures;

use Gnnpls\SelfTest\Case_Base;

final class Legacy_Case extends Case_Base
{
    /** @return string[] */
    public function preview()
    {
        return [];
    }

    /** @return array */
    public function run()
    {
        return [];
    }

    /** @return array */
    public function validate(array $context)
    {
        return [];
    }
}
