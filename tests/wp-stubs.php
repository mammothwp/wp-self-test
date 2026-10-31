<?php
/**
 * Minimal WordPress function stubs for the unit tests.
 *
 * @package MammothWP\SelfTest
 */

namespace {

    class WP_Error
    {
        public $code;
        public $message;

        public function __construct($code = '', $message = '', $data = '')
        {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_message()
        {
            return $this->message;
        }

        public function get_error_code()
        {
            return $this->code;
        }
    }

    /** In-memory hooks, options and deprecation log. */
    class Wp_Stub_State
    {
        public static $hooks      = [];
        public static $options    = [];
        public static $deprecated = [];
        public static $environment = 'local';

        public static function reset()
        {
            self::$environment = 'local';
            self::$hooks      = [];
            self::$options    = [];
            self::$deprecated = [];
            \WP_Session_Tokens::$destroyed = [];
            Wp_Stub_Registry::reset();
            \WP_CLI\StubRecorder::reset();
            \WP_CLI\StubRecorder::$commands = [];
        }
    }

    class WP_REST_Server
    {
        const READABLE  = 'GET';
        const CREATABLE = 'POST';
    }

    /** Records REST routes and abilities registered during a test. */
    class Wp_Stub_Registry
    {
        public static $routes    = [];
        public static $abilities = [];

        public static function reset()
        {
            self::$routes    = [];
            self::$abilities = [];
        }
    }

    function register_rest_route($namespace, $route, $args = [])
    {
        Wp_Stub_Registry::$routes[] = $namespace . $route;
    }

    function wp_register_ability($name, $args)
    {
        Wp_Stub_Registry::$abilities[$name] = $args;
    }

    function wp_register_ability_category($name, $args)
    {
    }

    function wp_has_ability($name)
    {
        return isset(Wp_Stub_Registry::$abilities[$name]);
    }

    function current_user_can($capability)
    {
        return true;
    }

    function get_current_user_id()
    {
        return 1;
    }

    function home_url()
    {
        return "https://example.test";
    }

    function is_wp_error($thing)
    {
        return $thing instanceof WP_Error;
    }

    function __($text, $domain = 'default')
    {
        return $text;
    }

    function sanitize_key($key)
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
    }

    function add_filter($name, $callback, $priority = 10, $accepted_args = 1)
    {
        Wp_Stub_State::$hooks[$name][] = $callback;
        return true;
    }

    function add_action($name, $callback, $priority = 10, $accepted_args = 1)
    {
        return add_filter($name, $callback, $priority, $accepted_args);
    }

    function has_filter($name)
    {
        return !empty(Wp_Stub_State::$hooks[$name]);
    }

    function has_action($name)
    {
        return has_filter($name);
    }

    function apply_filters_ref_array($name, $args)
    {
        $value = $args[0];
        foreach (Wp_Stub_State::$hooks[$name] ?? [] as $callback) {
            $args[0] = $value;
            $value   = call_user_func_array($callback, $args);
        }
        return $value;
    }

    function apply_filters($name, $value, ...$args)
    {
        return apply_filters_ref_array($name, array_merge([$value], $args));
    }

    function do_action_ref_array($name, $args)
    {
        foreach (Wp_Stub_State::$hooks[$name] ?? [] as $callback) {
            call_user_func_array($callback, $args);
        }
    }

    function do_action($name, ...$args)
    {
        do_action_ref_array($name, $args);
    }

    function _deprecated_hook($hook, $version, $replacement = '')
    {
        Wp_Stub_State::$deprecated[] = [$hook, $replacement];
    }

    function do_action_deprecated($name, $args, $version, $replacement = '')
    {
        if (!has_action($name)) {
            return;
        }
        _deprecated_hook($name, $version, $replacement);
        do_action_ref_array($name, $args);
    }

    function apply_filters_deprecated($name, $args, $version, $replacement = '')
    {
        if (!has_filter($name)) {
            return $args[0];
        }
        _deprecated_hook($name, $version, $replacement);
        return apply_filters_ref_array($name, $args);
    }

    function get_option($name, $default = false)
    {
        return array_key_exists($name, Wp_Stub_State::$options) ? Wp_Stub_State::$options[$name] : $default;
    }

    function update_option($name, $value, $autoload = null)
    {
        Wp_Stub_State::$options[$name] = $value;
        return true;
    }

    function wp_get_environment_type()
    {
        return Wp_Stub_State::$environment;
    }

    function user_can($user_id, $capability)
    {
        return true;
    }

    function trailingslashit($value)
    {
        return rtrim((string) $value, '/\\') . '/';
    }

    function admin_url($path = '')
    {
        return 'https://example.test/wp-admin/' . ltrim($path, '/');
    }

    function rest_url($path = '')
    {
        return 'https://example.test/wp-json/' . ltrim($path, '/');
    }

    // phpcs:disable WordPress.WP.AlternativeFunctions
    function wp_strip_all_tags($text)
    {
        return trim(strip_tags(preg_replace('@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $text)));
    }

    function wp_json_encode($data)
    {
        return json_encode($data);
    }
    // phpcs:enable WordPress.WP.AlternativeFunctions

    function get_transient($name)
    {
        return Wp_Stub_State::$options['_transient_' . $name] ?? false;
    }

    function set_transient($name, $value, $ttl = 0)
    {
        Wp_Stub_State::$options['_transient_' . $name] = $value;
        return true;
    }

    /** Records destroyed sessions. */
    class WP_Session_Tokens
    {
        public static $destroyed = [];

        public static function get_instance($user_id)
        {
            return new self();
        }

        public function destroy($token)
        {
            self::$destroyed[] = $token;
        }
    }

    function delete_option($name)
    {
        unset(Wp_Stub_State::$options[$name]);
        return true;
    }
}
