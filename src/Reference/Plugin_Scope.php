<?php

namespace MammothWP\SelfTest\Reference;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * One plugin's directory plus its Pro paths: decides which files belong to
 * the plugin and which of those are Pro-only, and describes callables with
 * plugin-relative locations.
 *
 * @package MammothWP\SelfTest
 * @since   2.3.0
 */
final class Plugin_Scope
{
    /** @var string */
    private $dir;

    /** @var Pro_Paths */
    private $pro;

    /**
     * @param string    $dir Plugin directory.
     * @param Pro_Paths $pro Pro paths.
     */
    public function __construct($dir, Pro_Paths $pro)
    {
        $real      = realpath($dir);
        $this->dir = rtrim(false === $real ? $dir : $real, '/');
        $this->pro = $pro;
    }

    /**
     * Plugin directory (real path).
     *
     * @return string
     */
    public function dir()
    {
        return $this->dir;
    }

    /**
     * Plugin-relative path of a file, or null when it is outside the plugin.
     *
     * @param string|null $file Absolute path.
     *
     * @return string|null
     */
    public function relative($file)
    {
        if (!is_string($file) || '' === $file) {
            return null;
        }

        $real = realpath($file);
        $file = false === $real ? $file : $real;

        return 0 === strpos($file, $this->dir . '/') ? substr($file, strlen($this->dir) + 1) : null;
    }

    /**
     * Whether a file lives in the plugin.
     *
     * @param string|null $file Absolute path.
     *
     * @return bool
     */
    public function owns($file)
    {
        $relative = $this->relative($file);

        return null !== $relative && !self::is_vendor($relative);
    }

    /**
     * Whether a plugin-relative path is inside a bundled package (`vendor/`).
     *
     * @param string $relative Plugin-relative path.
     *
     * @return bool
     */
    public static function is_vendor($relative)
    {
        return 0 === strpos($relative, 'vendor/');
    }

    /**
     * Whether a described callable is Pro-only: it lives in a Pro path, or is a
     * Freemius `__premium_only` function or method.
     *
     * @param array{name: string, file: string|null} $info Result of describe().
     *
     * @return bool
     */
    public function is_pro_callable(array $info)
    {
        $suffix = '__premium_only';

        return $this->is_pro($info['file']) || substr($info['name'], -strlen($suffix)) === $suffix;
    }

    /**
     * Whether a file lives in a Pro-only path.
     *
     * @param string|null $file Absolute path.
     *
     * @return bool
     */
    public function is_pro($file)
    {
        $relative = $this->relative($file);

        return null !== $relative && $this->pro->matches($relative);
    }

    /**
     * Placeholders for environment-specific absolute paths.
     *
     * @return array<string,string>
     */
    public function scrubs()
    {
        $scrubs = [$this->dir => '{plugin}'];

        if (defined('ABSPATH')) {
            $scrubs[rtrim((string) ABSPATH, '/')] = '{abspath}';
        }

        return $scrubs;
    }

    /**
     * Stable name and file of a callable. A wp-kit adapter closure is described
     * as the service method it runs.
     *
     * @param mixed $callable Callable.
     *
     * @return array{name: string, file: string|null, source: string}
     */
    public function describe($callable)
    {
        $info = Callable_Info::inspect(Kit_Unwrap::callable($callable));
        $name = $info['name'];

        if ('{closure}' === $name && null !== $info['file']) {
            $relative = $this->relative($info['file']);
            $name     = '{closure}@' . (null === $relative ? basename($info['file']) : $relative) . ':' . $info['line'];
        }

        return ['name' => $name, 'file' => $info['file'], 'source' => $info['source']];
    }
}
