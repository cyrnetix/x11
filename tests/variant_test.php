<?php
declare(strict_types=1);

/**
 * Theme variants: a second set of colours for the same era.
 *
 * The design decision worth pinning is what a variant is *not*. It changes the
 * palette and, through it, the chrome — and it deliberately does not change the
 * metrics. That is what lets a variant switch skip the relayout a theme switch
 * needs: nothing moved, so nothing has to be measured again. If a look needs
 * different sizes it is a different era and belongs in its own theme.
 *
 * Three things follow, and each has checks below:
 *
 *  - **The cache is per variant.** `BaseTheme` builds each part once, and a
 *    palette built for the light variant must not be handed out for the dark
 *    one. The chrome holds a palette, so it has to be built per variant too.
 *  - **Selection is forgiving.** A variant is a saved preference; one that has
 *    been renamed should leave the user looking at the theme rather than at an
 *    exception.
 *  - **The five original themes are untouched.** Each reports exactly one
 *    variant, so every existing caller behaves as it did.
 *
 * Also here: the corner geometry the two modern themes need, because the curve
 * is shared between what draws a rounded control and what cuts a rounded window,
 * and a disagreement of one pixel between those shows as a hairline of desktop
 * inside the window's own border.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Corner;
use Cyrnetix\X11\Theme\Fluent\FluentTheme;
use Cyrnetix\X11\Theme\Material\MaterialTheme;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\Theme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xChrome;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetTree;

$fail = 0;

$show = static function ($value) use (&$show): string {
    if (is_array($value)) return '[' . implode(', ', array_map($show, $value)) . ']';

    return var_export($value, true);
};

$check = function (string $what, $got, $want) use (&$fail, $show): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-56s %-26s %s\n", $what, $show($got), $ok ? 'ok' : 'FAIL (want ' . $show($want) . ')');
};

/** A theme that says nothing about variants, to check what it gets for free. */
final class PlainTestTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'plain'; }
    /** The name. */
    public function name(): string { return 'Plain'; }

    /** Builds the palette. */
    protected function buildPalette(string $variant): Palette { return new Palette(); }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics { return new Metrics(); }

    /**
     * Builds the chrome.
     *
     * A real one, because `BaseChrome` leaves seven methods abstract — the
     * era-defining ones — and this suite is about which palette a chrome is
     * built *with*, not about what it draws.
     */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new Win9xChrome($palette, $metrics);
    }
}

/** A theme with two variants that differ in one visible way. */
final class TwoToneTestTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'twotone'; }
    /** The name. */
    public function name(): string { return 'Two Tone'; }

    /** Its variants. */
    public function variants(): array
    {
        return ['pale' => 'Pale', 'deep' => 'Deep'];
    }

    /** Builds the palette. */
    protected function buildPalette(string $variant): Palette
    {
        return new Palette(face: $variant === 'deep' ? [10, 10, 10] : [240, 240, 240]);
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics { return new Metrics(); }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new Win9xChrome($palette, $metrics);
    }
}

echo "a theme that says nothing\n";

$plain = new PlainTestTheme();

$check('gets exactly one variant',        count($plain->variants()), 1);
$check('named after itself',              $plain->variants(), [BaseTheme::SOLE_VARIANT => 'Plain']);
$check('which is its default',            $plain->defaultVariant(), BaseTheme::SOLE_VARIANT);
$check('and asking for none gets it',     $plain->palette() === $plain->palette(BaseTheme::SOLE_VARIANT), true);

// Forgiving: a variant this theme has never heard of resolves to its default
// rather than throwing, because a variant is a stored preference.
$check('an unknown variant falls back',   $plain->palette('nonsense') === $plain->palette(), true);

echo "caching, per variant\n";

$two = new TwoToneTestTheme();

$check('a palette is built once',          $two->palette('pale') === $two->palette('pale'), true);
$check('but not shared between variants',  $two->palette('pale') === $two->palette('deep'), false);
$check('and they really differ',           [$two->palette('pale')->face, $two->palette('deep')->face],
    [[240, 240, 240], [10, 10, 10]]);

// The chrome holds a palette, so it has to be per variant as well — this is the
// one that would silently draw the light colours in the dark variant.
$check('the chrome is built once per variant', $two->chrome('deep') === $two->chrome('deep'), true);
$check('and not shared either',                $two->chrome('deep') === $two->chrome('pale'), false);

// Metrics are shared, which is the whole reason a variant needs no relayout.
$check('metrics are one object for the theme', $two->metrics() === $two->metrics(), true);

echo "selecting\n";

$manager = new ThemeManager($two, $plain);

$check('it starts on the first theme',     $manager->currentId(), 'twotone');
$check('in that theme\'s default variant', $manager->currentVariant(), 'pale');
$check('and can say so in words',          $manager->currentVariantName(), 'Pale');
$check('it knows there is a choice',       $manager->hasVariants(), true);

$check('switching variant reports the change', $manager->selectVariant('deep'), true);
$check('and the palette follows',              $manager->palette()->face, [10, 10, 10]);
$check('switching to the same one does not',   $manager->selectVariant('deep'), false);
$check('nor does one that does not exist',     $manager->selectVariant('mauve'), false);
$check('which leaves the live one alone',      $manager->currentVariant(), 'deep');

// Moving theme resets the variant: carrying "deep" to a theme that has no such
// variant would land on its default anyway, and carrying it to one that does is
// a guess about what was meant.
$manager->select('plain');
$check('changing theme takes its default variant', $manager->currentVariant(), BaseTheme::SOLE_VARIANT);
$check('a theme with one variant says so',         $manager->hasVariants(), false);
$check('and cannot cycle',                         $manager->selectNextVariant(), false);

$check('a theme and variant can be chosen at once',
    [$manager->select('twotone', 'deep'), $manager->currentVariant()], [true, 'deep']);
$check('an unknown variant still selects the theme',
    [$manager->select('plain', 'mauve'), $manager->currentId(), $manager->currentVariant()],
    [true, 'plain', BaseTheme::SOLE_VARIANT]);
$check('an unknown theme selects nothing', $manager->select('nope'), false);

$manager->select('twotone', 'pale');
$check('cycling moves on',   [$manager->selectNextVariant(), $manager->currentVariant()], [true, 'deep']);
$check('and wraps round',    [$manager->selectNextVariant(), $manager->currentVariant()], [true, 'pale']);

echo "what listeners are told\n";

$heard = [];
$manager->onChange(static function (Theme $theme, string $variant, bool $themeChanged) use (&$heard): void {
    $heard[] = sprintf('%s:%s:%s', $theme->id(), $variant, $themeChanged ? 'theme' : 'variant');
});

$manager->selectVariant('deep');
$manager->select('plain');
$manager->select('twotone', 'deep');

// The flag is the point: a variant needs a repaint, a theme needs a relayout
// too, and an application that cannot tell them apart reflows for nothing.
$check('a variant switch is announced as one', $heard,
    ['twotone:deep:variant', 'plain:default:theme', 'twotone:deep:theme']);

echo "the themes that shipped before this\n";

foreach ([new PlatinumTheme(), new CdeTheme(), new BeOsTheme()] as $theme) {
    $check(sprintf('%s still has exactly one variant', $theme->id()), count($theme->variants()), 1);
}

// The two eras that were recoloured from a control panel now offer that.
$check('win9x offers three schemes', array_keys((new Win9xTheme())->variants()),
    ['standard', 'dark', 'contrast']);
$check('win31 offers two',           array_keys((new Win31Theme())->variants()), ['standard', 'dark']);
$check('and they are visibly different',
    (new Win9xTheme())->palette('dark')->face !== (new Win9xTheme())->palette('standard')->face, true);

// A dark variant has to keep the bevel roles in the right relationship or every
// control comes out inside-out: the highlight must stay lighter than the face
// and the shadow darker.
$dark      = (new Win9xTheme())->palette('dark');
$lightness = static fn(array $c): float => ($c[0] + $c[1] + $c[2]) / 3;
$check('a dark scheme keeps its highlight above the face',
    $lightness($dark->faceHighlight) > $lightness($dark->face), true);
$check('and its shadows below it',
    $lightness($dark->faceShadow) < $lightness($dark->face)
    && $lightness($dark->faceDarkShadow) < $lightness($dark->faceShadow), true);
$check('and a focus ring you can see on it',
    $lightness($dark->focus) > $lightness($dark->face), true);

echo "the modern themes\n";

foreach ([new FluentTheme(), new MaterialTheme()] as $theme) {
    $id = $theme->id();

    $check(sprintf('%s offers light and dark', $id), array_keys($theme->variants()), ['light', 'dark']);
    $check(sprintf('%s rounds its controls', $id),   $theme->metrics()->cornerRadius > 0, true);
    $check(sprintf('%s rounds its window', $id),     $theme->metrics()->windowCornerRadius > 0, true);
    $check(sprintf('%s reacts to the pointer', $id), $theme->chrome()->rendersButtonHover(), true);

    // Dark is not a tint of light: the ground and the ink swap ends.
    $light = $theme->palette('light');
    $night = $theme->palette('dark');
    $check(sprintf('%s inverts ground and ink', $id),
        $lightness($light->face) > $lightness($light->text) && $lightness($night->face) < $lightness($night->text),
        true);
    $check(sprintf('%s shares its metrics across variants', $id),
        $theme->metrics() === $theme->metrics(), true);
}

$check('the older themes stay square', [
    (new Win9xTheme())->metrics()->cornerRadius,
    (new Win9xTheme())->metrics()->windowCornerRadius,
    (new CdeTheme())->metrics()->windowCornerRadius,
], [0, 0, 0]);

echo "the corner curve\n";

$check('a radius below one is no curve at all', Corner::insets(0), []);
$check('one pixel of radius insets nothing',    Corner::insets(1), [0]);
$check('and the curve reaches the edge',        Corner::insets(8)[7], 0);

// Monotonic: each row towards the middle is inset less than the one above it.
$climbs = true;
foreach ([4, 6, 8, 12, 20] as $radius) {
    $insets = Corner::insets($radius);
    for ($i = 1; $i < count($insets); $i++) {
        if ($insets[$i] > $insets[$i - 1]) $climbs = false;
    }
}
$check('the inset only ever decreases inwards', $climbs, true);

// A real circle, not a chamfer: a square rounded by half its side is a disc, and
// its area should land within a percent or two of pi r squared.
$area = 0;
foreach (Corner::rects(0, 0, 64, 64, 32) as [, , $w, $h]) $area += $w * $h;
$check('a full round is a circle to within 1%', abs($area - M_PI * 32 * 32) / (M_PI * 32 * 32) < 0.01, true);

$check('a zero radius is one rectangle',      Corner::rects(3, 4, 10, 5, 0), [[3, 4, 10, 5]]);
$check('an empty rectangle has no rows',      Corner::rects(0, 0, 0, 10, 4), []);
$check('a radius too large for it is clamped',
    count(Corner::rects(0, 0, 12, 12, 40)) > 0 && count(Corner::rects(0, 0, 12, 12, 40)) <= 13, true);

echo "the window's own corners\n";

$renderer = new Renderer();

/** The shape a theme cuts for an untouched 400x300 window. */
$shapeOf = static function (Theme $theme) use ($renderer): array {
    $manager = new ThemeManager($theme);
    $tree    = new WidgetTree($manager);
    $frame   = new WindowFrame(400, 300, 'T', drawsChrome: true);
    $tree->addRoot($frame);

    return $frame->shapeRects($renderer);
};

// No shape at all for a square era: an empty list means "leave the window a
// rectangle", which is not the same as a list covering all of it.
$check('a square theme cuts nothing', $shapeOf(new Win9xTheme()), []);

$rounded = $shapeOf(new FluentTheme());
$covered = 0;
foreach ($rounded as [, , $w, $h]) $covered += $w * $h;

$check('a rounded theme cuts a staircase', count($rounded) > 8, true);
$check('taking only the corners off',      $covered > 0 && $covered < 400 * 300, true);
$check('and not much: under a per cent',   (400 * 300 - $covered) / (400 * 300) < 0.01, true);

// BeOS's partial caption still gets its two rectangles, which is a different
// mechanism entirely and must not have been disturbed.
$check('a tab caption still cuts two rectangles', count($shapeOf(new BeOsTheme())), 2);

printf("\n%s\n", $fail === 0 ? 'variant: all checks passed' : sprintf('variant: %d FAILED', $fail));
exit($fail === 0 ? 0 : 1);
