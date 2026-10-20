<?php
/**
 * Minimal case used by the CLI tests.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests\Fixtures;

use MammothWP\SelfTest\Case_Base;

final class Demo_Case extends Case_Base
{
    /** @return string[] */
    public function preview()
    {
        return ['Do nothing.'];
    }

    /** @return array */
    public function run()
    {
        return [];
    }

    /** @return array */
    public function validate(array $context)
    {
        return [$this->check('core', 'Always passes', true)];
    }
}
