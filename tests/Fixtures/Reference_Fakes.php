<?php
/**
 * Fakes mimicking the shapes WordPress and WP-CLI hand to the reference walkers.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests\Fixtures;

/** A WP-CLI Subcommand: the callable sits in the static vars of `when_invoked`. */
class Fake_Subcommand
{
    private $name;
    private $when_invoked;
    private $synopsis;
    private $longdesc;
    private $shortdesc;

    public function __construct($name, $callable, $synopsis, $shortdesc, $longdesc)
    {
        $this->name         = $name;
        $this->when_invoked = function ($args, $assoc_args) use ($callable) {
            return $callable;
        };
        $this->synopsis     = $synopsis;
        $this->shortdesc    = $shortdesc;
        $this->longdesc     = $longdesc;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function get_synopsis()
    {
        return $this->synopsis;
    }

    public function get_shortdesc()
    {
        return $this->shortdesc;
    }

    public function get_longdesc()
    {
        return $this->longdesc;
    }
}

/** A WP-CLI composite command / namespace. */
class Fake_Composite
{
    private $name;
    private $children;

    public function __construct($name, array $children)
    {
        $this->name     = $name;
        $this->children = $children;
    }

    public function get_name()
    {
        return $this->name;
    }

    public function get_subcommands()
    {
        return $this->children;
    }
}

/** A WP_Ability look-alike. */
class Fake_Ability
{
    protected $execute_callback;
    protected $permission_callback;
    private $props;

    public function __construct(array $props, $execute, $permission)
    {
        $this->props               = $props;
        $this->execute_callback    = $execute;
        $this->permission_callback = $permission;
    }

    public function get_name()
    {
        return $this->props['name'];
    }

    public function get_label()
    {
        return $this->props['label'] ?? '';
    }

    public function get_description()
    {
        return $this->props['description'] ?? '';
    }

    public function get_category()
    {
        return $this->props['category'] ?? '';
    }

    public function get_input_schema()
    {
        return $this->props['input_schema'] ?? [];
    }

    public function get_output_schema()
    {
        return $this->props['output_schema'] ?? [];
    }

    public function get_meta()
    {
        return $this->props['meta'] ?? [];
    }
}
