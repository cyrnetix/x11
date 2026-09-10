<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Cyrnetix\X11\Theme\ThemeManager;
use RuntimeException;

/**
 * Theme-driven icon registry.
 *
 * Icon sets live side by side under a `themes/` root — `windows2000/` with .ico
 * files, `macos90/` with .png — each carrying an `icons.php` that maps
 * {@see IconName} values to filenames inside its own `system/` directory. The
 * registry reads whichever set the *live* theme names through
 * {@see \Cyrnetix\X11\Theme\Theme::iconSet()}, falling back to $defaultSet for
 * themes that don't ship icons of their own.
 *
 * Decoded icons are cached per set + name, so switching themes back and forth
 * only pays the decode once each. The format is chosen by file extension, which
 * is what lets one theme use .ico and another .png.
 *
 * **Decoding goes through a driver chain.** By default that is
 * {@see DriverIconLoader}, which prefers the GD extension and falls back to this
 * package's own parsers when it is absent — so a PNG costs ~3.5 ms with GD
 * installed and ~27 ms without, and nothing in an application has to know which.
 * The extensions the registry answers to are derived from what the installed
 * backends actually support rather than listed here, so a build with WebP gets
 * WebP icons for free and one without PNG in GD still gets PNG through the
 * native parser.
 *
 * {@see iconDrawer()} resolves the icon *at draw time* rather than when the
 * closure is made — widgets hold those closures for their whole life
 * (`TreeNode::$iconDrawer`), and they have to follow a theme switch.
 *
 * Both constructor arguments that point at shipped things default to what the
 * package ships, so an application consuming this toolkit as a dependency can
 * say `new IconRegistry($themes)` and get icons — rather than having to work
 * out where inside its own vendor directory the sets ended up, or discovering
 * silently that it registered no loaders and every icon is missing.
 */
final class IconRegistry
{
    /** Mapping files are cached process-wide: `require` only returns its value once. */
    private static array $mappingFiles = [];

    /** @var array<string, array<string, string|null>> set => [IconName value => absolute path|null] */
    private array $mappings = [];

    /** @var array<string, Icon|false> "set|name" => decoded icon, false when unavailable */
    private array $cache = [];

    /** @var array<string, IconLoader> */
    private readonly array $loaders;

    private readonly string $themesRoot;

    /**
     * @param array<string, IconLoader>|null $loaders Keyed by lowercase file
     *        extension; null registers the formats the shipped sets use.
     * @param string|null $themesRoot Directory holding the icon sets; null uses
     *        the one bundled with this package.
     */
    public function __construct(
        private readonly ThemeManager    $themes,
        ?array                           $loaders    = null,
        ?string                          $themesRoot = null,
        private readonly string          $defaultSet = 'windows2000',
        private readonly LoggerInterface $logger     = new NullLogger(),
        int                              $iconSize   = 16,
    ) {
        $this->themesRoot = $themesRoot ?? self::shippedThemesRoot();

        if ($loaders !== null) {
            $this->loaders = $loaders;

            return;
        }

        // One loader, registered under every extension the installed drivers
        // can read. Asking the chain rather than naming formats here is what
        // keeps the two from disagreeing: a format listed but unreadable is an
        // icon that silently fails instead of falling through.
        $chain = new DriverIconLoader(
            scaler: new IconScaler($iconSize),
            logger: $this->logger,
        );

        $map = [];
        foreach ($chain->available() as $formats) {
            foreach ($formats as $extension) $map[$extension] = $chain;
        }

        $this->loaders = $map;
    }

    /**
     * Which loader handles each extension, for diagnostics.
     *
     * "Why are my icons slow" is otherwise invisible, and the answer is usually
     * that GD is not installed — {@see DriverIconLoader::available()} says so.
     *
     * @return array<string, IconLoader>
     */
    public function loaders(): array { return $this->loaders; }

    /**
     * Where this package's own icon sets live, wherever it has been installed.
     *
     * Derived from this file's location rather than a constant, so it is right
     * both in the repository and under a consumer's `vendor/`.
     */
    public static function shippedThemesRoot(): string
    {
        return dirname(__DIR__, 2) . '/resource/themes';
    }

    /** Icon set the live theme wants, or the default for themes without one. */
    public function currentSet(): string
    {
        return $this->themes->current()->iconSet() ?? $this->defaultSet;
    }

    /**
     * Decoded icon for the live theme, or null when this set doesn't map the
     * name, the file is missing, or it failed to parse. Callers fall back to
     * their own drawer in that case.
     */
    public function get(IconName $name): ?Icon
    {
        $set = $this->currentSet();
        $key = $set . '|' . $name->value;

        if (array_key_exists($key, $this->cache)) {
            $cached = $this->cache[$key];
            return $cached === false ? null : $cached;
        }

        $path = $this->mapping($set)[$name->value] ?? null;
        if ($path === null) {
            $this->cache[$key] = false;
            return null;
        }

        $loader = $this->loaders[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;
        if ($loader === null) {
            $this->logger->warning('No icon loader for this format', ['path' => $path]);
            $this->cache[$key] = false;
            return null;
        }

        try {
            return $this->cache[$key] = $loader->load($path);
        } catch (\Throwable $e) {
            $this->logger->warning('Icon failed to load', [
                'name'  => $name->value,
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
            $this->cache[$key] = false;
            return null;
        }
    }

    /**
     * Closure for widgets that take an icon drawer
     * (`TreeNode::$iconDrawer`, `ListViewItem::$iconDrawer`).
     * Signature: function (Renderer, x, y, size): void — a no-op when the live
     * theme has no icon for the name.
     */
    public function iconDrawer(IconName $name): Closure
    {
        return function (Renderer $r, int $x, int $y, int $size) use ($name): void {
            $this->get($name)?->drawAt($r, $x, $y, $size);
        };
    }

    /**
     * Resolve a set's mapping to absolute paths, once per set.
     *
     * @return array<string, string|null>
     */
    private function mapping(string $set): array
    {
        if (isset($this->mappings[$set])) {
            return $this->mappings[$set];
        }

        $dir  = $this->themesRoot . '/' . $set;
        $file = $dir . '/icons.php';

        if (!is_file($file)) {
            $this->logger->warning('Icon set has no icons.php', ['set' => $set, 'path' => $file]);
            return $this->mappings[$set] = [];
        }

        $mapping = self::$mappingFiles[$file] ??= require $file;
        if (!is_array($mapping)) {
            throw new RuntimeException("icons.php must return an array: $file");
        }

        $resolved = [];
        foreach ($mapping as $key => $filename) {
            $path = $dir . '/system/' . $filename;
            if (!is_file($path)) {
                $this->logger->warning('Icon file missing', ['set' => $set, 'name' => $key, 'path' => $path]);
                $resolved[$key] = null;
                continue;
            }
            $resolved[$key] = $path;
        }

        return $this->mappings[$set] = $resolved;
    }
}
