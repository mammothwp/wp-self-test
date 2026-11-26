<?php
/**
 * Fakes mimicking the shapes WordPress and WP-CLI hand to the reference walkers.
 *
 * @package MammothWP\SelfTest
 */

namespace MammothWP\SelfTest\Tests\Fixtures;

/** A WP-CLI composite command / namespace. */
class Fake_Composite
{
    protected $name;
    protected $children;

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

/** A WP-CLI Subcommand (a CompositeCommand with no subcommands): the callable sits in the static vars of `when_invoked`. */
class Fake_Subcommand extends Fake_Composite
{
    private $when_invoked;
    private $synopsis;
    private $longdesc;
    private $shortdesc;

    public function __construct($name, $callable, $synopsis, $shortdesc, $longdesc)
    {
        parent::__construct($name, []);

        $this->when_invoked = function ($args, $assoc_args) use ($callable) {
            return $callable;
        };
        $this->synopsis     = $synopsis;
        $this->shortdesc    = $shortdesc;
        $this->longdesc     = $longdesc;
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

/** A wp-kit Resource: hands out its service instance. */
class Fake_Kit_Resource
{
    private $service;

    public function __construct($service)
    {
        $this->service = $service;
    }

    public function service_instance()
    {
        return $this->service;
    }
}

/** A wp-kit Operation: public accessors plus the private `service_override` of Operation::on(). */
class Fake_Kit_Operation
{
    private $handler;
    private $capability;
    private $resource;
    private $service_override = null;

    public function __construct($handler, $capability, $resource, $override = null)
    {
        $this->handler          = $handler;
        $this->capability       = $capability;
        $this->resource         = $resource;
        $this->service_override = $override;
    }

    public function handler()
    {
        return $this->handler;
    }

    public function capability_resolver()
    {
        return $this->capability;
    }

    public function resource()
    {
        return $this->resource;
    }

    /**
     * The closures wp-kit's adapters register: they capture the operation.
     *
     * @return \Closure
     */
    public function adapter_closure()
    {
        $operation = $this;

        return function ($request = null) use ($operation) {
            return $operation;
        };
    }
}

/** WP_CLI class look-alike with the deferred-addition API. */
class Fake_WP_CLI
{
    public static $deferred = [];
    public static $added    = [];

    public static function get_deferred_additions()
    {
        return self::$deferred;
    }

    public static function remove_deferred_addition($name)
    {
        unset(self::$deferred[$name]);
    }

    public static function add_command($name, $callable, $args = [])
    {
        self::$added[$name] = $args;
    }
}
