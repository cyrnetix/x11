<?php
declare(strict_types=1);

/**
 * Icon decoding through the driver chain.
 *
 * Decoding used to be one hand-written parser per container, called directly.
 * The parsing is 89% of the cost of loading a PNG and GD does that part in C,
 * but GD cannot be relied on to exist and cannot read .ico at all — so the
 * decoders became *drivers* that report what they can do, and the loader walks
 * them. This pins the three things that arrangement has to get right:
 *
 *  - **The chain picks correctly.** GD for .png when it is installed, the native
 *    parser for .ico always, because GD declines the format rather than failing
 *    on it.
 *  - **The fallback is invisible.** The same file through either driver must
 *    produce the same icon, or icons would look different depending on which
 *    extensions are installed. Scaling and the alpha threshold live outside the
 *    drivers precisely so they cannot drift; this measures whether the pixels
 *    actually agree across every icon the package ships.
 *  - **A driver failing costs one icon, not the application.** A decoder that
 *    throws falls through to the next that claims the format.
 *
 * No X server: this is all file parsing.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Drawing\Decoder\GdDecoder;
use Cyrnetix\X11\Drawing\Decoder\ImageDecoder;
use Cyrnetix\X11\Drawing\Decoder\NativeDecoder;
use Cyrnetix\X11\Drawing\Decoder\RasterImage;
use Cyrnetix\X11\Drawing\DriverIconLoader;
use Cyrnetix\X11\Drawing\IcoLoader;
use Cyrnetix\X11\Drawing\Icon;
use Cyrnetix\X11\Drawing\IconName;
use Cyrnetix\X11\Drawing\IconRegistry;
use Cyrnetix\X11\Drawing\IconScaler;
use Cyrnetix\X11\Drawing\PngLoader;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;

$fail  = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-52s %-14s %s\n", $what,
        is_array($got) ? (string) json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$root = IconRegistry::shippedThemesRoot();
$gd   = new GdDecoder();
$nat  = new NativeDecoder();

// -------------------------------------------------------------------------
echo "what each driver claims\n";

$check('the native driver is always available',   $nat->isAvailable(), true);
$check('it reads png and ico',                    $nat->formats(), ['png', 'ico']);
$check('gd availability matches the extension',   $gd->isAvailable(), extension_loaded('gd'));

if ($gd->isAvailable()) {
    $check('gd claims png',                       in_array('png', $gd->formats(), true), true);
    // The whole reason the chain needs a second driver: GD has no ICO reader,
    // and the Windows 2000 set is 51 .ico files.
    $check('gd does NOT claim ico',               in_array('ico', $gd->formats(), true), false);
}

// -------------------------------------------------------------------------
echo "\nwhich driver the chain picks\n";
$chain = new DriverIconLoader();

$png = $root . '/macos90/system/find-sherlock.png';
$ico = glob($root . '/windows2000/system/*.ico')[0] ?? null;

$check('a png file resolves to a driver',         $chain->driverFor($png)?->id(),
    $gd->isAvailable() ? 'gd' : 'native');
$check('an ico file always resolves to native',   $chain->driverFor($ico)?->id(), 'native');
$check('an unknown extension resolves to none',   $chain->driverFor('/tmp/x.tiff')?->id(), null);

// -------------------------------------------------------------------------
echo "\nthe fallback is invisible\n";

// Not byte equality, and the difference is worth stating rather than
// asserting away. GD stores alpha in 7 bits with 0 meaning opaque, so an 8-bit
// PNG alpha does not survive the round trip: 439 of a 64x64 icon's pixels come
// back one off. RGB is unaffected — it is stored full width — and the scaler
// divides accumulated colour by accumulated alpha, so a divisor one out shifts
// the averaged colour by one too.
//
// These bounds are what actually matters, and they are far stronger than
// "close enough": a real regression — a broken palette conversion, a channel
// swapped, alpha read from the wrong byte — blows past them immediately, while
// a one-LSB rounding difference does not.
$scaler  = new IconScaler(16);
$gdOnly  = new DriverIconLoader([$gd], $scaler);
$natOnly = new DriverIconLoader([$nat], $scaler);

$pixels = new ReflectionProperty(Icon::class, 'pixels');
$pngFiles = glob($root . '/*/system/*.png') ?: [];

$rgbRasterDiff = 0;      // decoded RGB that disagrees at all
$alphaOverOne  = 0;      // decoded alpha out by more than one
$colourOverOne = 0;      // final colour out by more than one
$flips         = 0;      // final pixels that appear or disappear
$compared      = 0;

foreach ($pngFiles as $file) {
    if (!$gd->isAvailable()) break;

    $rasterA = $nat->decode($file, 16);
    $rasterB = $gd->decode($file, 16);

    for ($y = 0; $y < $rasterA->height; $y++) {
        for ($x = 0; $x < $rasterA->width; $x++) {
            [$ar, $ag, $ab, $aa] = $rasterA->pixels[$y][$x];
            [$br, $bg, $bb, $ba] = $rasterB->pixels[$y][$x];

            if ($ar !== $br || $ag !== $bg || $ab !== $bb) $rgbRasterDiff++;
            if (abs($aa - $ba) > 1) $alphaOverOne++;
        }
    }

    $iconA = $pixels->getValue($scaler->toIcon($rasterA));
    $iconB = $pixels->getValue($scaler->toIcon($rasterB));

    foreach ($iconA as $y => $row) {
        foreach ($row as $x => $p) {
            $q = $iconB[$y][$x];
            $compared++;
            if ($p === $q) continue;

            if (($p[3] === 0) !== ($q[3] === 0)) { $flips++; continue; }
            if (max(abs($p[0] - $q[0]), abs($p[1] - $q[1]), abs($p[2] - $q[2])) > 1) $colourOverOne++;
        }
    }
}

if ($gd->isAvailable()) {
    printf("  compared %d icon pixels across %d png files\n", $compared, count($pngFiles));
    $check('decoded RGB is identical across drivers',  $rgbRasterDiff, 0);
    $check('decoded alpha is never out by more than 1', $alphaOverOne, 0);
    $check('no final colour is out by more than 1',     $colourOverOne, 0);
    // Two, at the time of writing. A bound rather than the number, because it
    // depends on how many averaged pixels sit within one of the threshold.
    $check('almost no pixel appears or disappears',     $flips <= intdiv($compared, 1000), true);
    printf("  (%d of %d pixels differ in drawn-ness; the bound is %d)\n",
        $flips, $compared, intdiv($compared, 1000));
} else {
    printf("  gd is not installed, so there is nothing to compare\n");
}

$check('the native driver reads every shipped png',
    count(array_filter($pngFiles, static fn(string $f): bool => $natOnly->load($f)->width > 0)),
    count($pngFiles));

// ICO has one driver, so this only checks it still decodes at all.
$icoFiles = glob($root . '/*/system/*.ico') ?: [];
$icoOk = 0;
foreach ($icoFiles as $file) {
    try { $natOnly->load($file); $icoOk++; } catch (\Throwable) {}
}
$check('every shipped ico still decodes',        $icoOk, count($icoFiles));

// -------------------------------------------------------------------------
echo "\na failing driver costs one icon, not the run\n";

/** A driver that claims png and always throws, to sit in front of a good one. */
$broken = new class implements ImageDecoder {
    public function id(): string { return 'broken'; }
    public function isAvailable(): bool { return true; }
    public function formats(): array { return ['png']; }
    public function decode(string $path, int $preferredSize): RasterImage
    {
        throw new RuntimeException('this driver is deliberately broken');
    }
};

$withBroken = new DriverIconLoader([$broken, $nat], $scaler);
$check('it falls through to the working driver',  $withBroken->load($png)->width, 16);

$brokenOnly = new DriverIconLoader([$broken], $scaler);
$threw = false;
try { $brokenOnly->load($png); } catch (\Throwable $e) { $threw = str_contains($e->getMessage(), 'deliberately broken'); }
$check('an exhausted chain throws, saying why',   $threw, true);

// A chain of GD alone cannot read an .ico either way: with GD present it
// declines the format, and without it there is no driver at all. Both are the
// same message, which is the one an application would have to act on.
$noneClaim = new DriverIconLoader([$gd], $scaler);
$threw = false;
try { $noneClaim->load($ico); } catch (\Throwable $e) { $threw = str_contains($e->getMessage(), 'No image decoder'); }
$check('a chain that cannot read the format says so', $threw, true);

// -------------------------------------------------------------------------
echo "\nthe old direct loaders still work\n";

$check('PngLoader::load still returns an Icon',   (new PngLoader(16))->load($png)->width, 16);
$check('IcoLoader::load still returns an Icon',   (new IcoLoader(16))->load($ico)->width, 16);
$check('PngLoader::raster is the native size',    (new PngLoader(16))->raster($png)->width, 64);

// -------------------------------------------------------------------------
echo "\nthe registry drives it end to end\n";

foreach (['win9x' => new Win9xTheme(), 'platinum' => new PlatinumTheme()] as $id => $theme) {
    $registry = new IconRegistry(new ThemeManager($theme));
    $icon     = $registry->get(IconName::Folder);

    $check("  $id: Folder decodes",               $icon !== null, true);
    $check("  $id: at the drawn size",            $icon?->width, 16);
}

$registry = new IconRegistry(new ThemeManager(new Win9xTheme()));
$formats  = array_keys($registry->loaders());
sort($formats);
printf("  registry answers to: %s\n", implode(', ', $formats));
$check('ico is registered',                       in_array('ico', $formats, true), true);
$check('png is registered',                       in_array('png', $formats, true), true);

printf("\nicon drivers: %s\n", $fail === 0 ? 'all checks passed' : "$fail check(s) FAILED");
exit($fail === 0 ? 0 : 1);
