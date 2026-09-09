<?php
declare(strict_types=1);

/**
 * Every theme, every variant, every drawing primitive, at awkward sizes.
 *
 * A smoke test rather than a correctness one: it asserts almost nothing about
 * what comes out, only that each theme can draw each thing without falling over
 * and that it emits *something*. That is worth having because a `Chrome` is
 * thirty-odd methods, a painter calls them with whatever geometry a widget
 * happens to have, and the ones a given demo never exercises are exactly where a
 * new theme has a division by zero waiting.
 *
 * The awkward sizes are the point. A one-pixel rectangle, a zero-width one and a
 * rectangle narrower than its own corner radius are all things that happen in
 * practice — a collapsed pane, a scrollbar thumb at its minimum, a button at the
 * end of a resize — and each has broken a theme here at least once.
 *
 * No X server: the renderer writes into a stream and the bytes are counted.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Edge;
use Cyrnetix\X11\Theme\Fluent\FluentTheme;
use Cyrnetix\X11\Theme\Material\MaterialTheme;
use Cyrnetix\X11\Theme\MessageIcon;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\Theme;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use React\Stream\ThroughStream;

$fail = 0;

$show = static function ($value) use (&$show): string {
    if (is_array($value)) return '[' . implode(', ', array_map($show, $value)) . ']';

    return var_export($value, true);
};

$check = function (string $what, $got, $want) use (&$fail, $show): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-58s %-12s %s\n", $what, $show($got), $ok ? 'ok' : 'FAIL (want ' . $show($want) . ')');
};

/** A renderer whose output can be measured. @return array{Renderer, callable} */
$wire = static function (): array {
    $written = 0;
    $stream  = new ThroughStream();
    $stream->on('data', function (string $chunk) use (&$written): void { $written += strlen($chunk); });

    $renderer = new Renderer();
    $renderer->init($stream, 0x900001, 0x900003);

    return [$renderer, static function () use ($renderer, &$written): int {
        // Nothing is on the wire until the buffer is sent.
        $renderer->flush();
        $count   = $written;
        $written = 0;

        return $count;
    }];
};

/**
 * Ask a theme to draw everything, at every size given.
 *
 * @param list<Rect> $rects
 * @return list<string> The names of anything that threw.
 */
$drawEverything = static function (Chrome $chrome, Renderer $r, array $rects): array {
    $broke = [];

    /** @var array<string, callable(Rect): void> $calls */
    $calls = [
        'fill'              => fn(Rect $b) => $chrome->fill($r, $b, Surface::Face),
        'panel'             => fn(Rect $b) => $chrome->panel($r, $b, Surface::Panel, Edge::Raised),
        'well'              => fn(Rect $b) => $chrome->well($r, $b),
        'headerCell'        => fn(Rect $b) => $chrome->headerCell($r, $b, ControlState::Normal),
        'tabPanel'          => fn(Rect $b) => $chrome->tabPanel($r, $b),
        'treeToggle'        => fn(Rect $b) => $chrome->treeToggle($r, $b, true, false),
        'scrollTrack'       => fn(Rect $b) => $chrome->scrollTrack($r, $b, true, true),
        'scrollTrackIdle'   => fn(Rect $b) => $chrome->scrollTrack($r, $b, false, false),
        'scrollThumb'       => fn(Rect $b) => $chrome->scrollThumb($r, $b, false),
        'sliderTrack'       => fn(Rect $b) => $chrome->sliderTrack($r, $b, true),
        'tick'              => fn(Rect $b) => $chrome->tick($r, $b),
        'progressFill'      => fn(Rect $b) => $chrome->progressFill($r, $b, true),
        'menuPopup'         => fn(Rect $b) => $chrome->menuPopup($r, $b),
        'menuItemHighlight' => fn(Rect $b) => $chrome->menuItemHighlight($r, $b),
        'menuBar'           => fn(Rect $b) => $chrome->menuBar($r, $b),
        'popupFrame'        => fn(Rect $b) => $chrome->popupFrame($r, $b),
        'separator'         => fn(Rect $b) => $chrome->separator($r, $b, true),
        'dialogFrame'       => fn(Rect $b) => $chrome->dialogFrame($r, $b),
        'captionGrab'       => fn(Rect $b) => $chrome->captionGrab($r, $b, true),
        'captionSurround'   => fn(Rect $b) => $chrome->captionSurround($r, $b),
        'captionTitle'      => fn(Rect $b) => $chrome->captionTitle($r, $b, 'Title', true),
        'windowBorder'      => fn(Rect $b) => $chrome->windowBorder($r, $b),
        'clientEdge'        => fn(Rect $b) => $chrome->clientEdge($r, $b),
        'grip'              => fn(Rect $b) => $chrome->grip($r, $b),
        'bandGrip'          => fn(Rect $b) => $chrome->bandGrip($r, $b),
        'tooltip'           => fn(Rect $b) => $chrome->tooltip($r, $b),
        'checkGlyph'        => fn(Rect $b) => $chrome->checkGlyph($r, $b, [0, 0, 0]),
        'sortIndicator'     => fn(Rect $b) => $chrome->sortIndicator($r, $b, true),
        'focusRect'         => fn(Rect $b) => $chrome->focusRect($r, $b),
        'messageIcon'       => fn(Rect $b) => $chrome->messageIcon($r, $b, MessageIcon::Warning),
        'dropFieldPlain'    => fn(Rect $b) => $chrome->dropField($r, $b, false, false),
        'dropFieldOpen'     => fn(Rect $b) => $chrome->dropField($r, $b, true, true),
    ];

    // The ones that take a state or a direction get every one of them.
    foreach (ControlState::cases() as $state) {
        $calls['button:' . $state->name]        = fn(Rect $b) => $chrome->button($r, $b, $state);
        $calls['toolbarButton:' . $state->name] = fn(Rect $b) => $chrome->toolbarButton($r, $b, $state, false);
        $calls['checkBox:' . $state->name]      = fn(Rect $b) => $chrome->checkBox($r, $b, true, $state);
        $calls['radioButton:' . $state->name]   = fn(Rect $b) => $chrome->radioButton($r, $b, false, $state);
    }
    foreach (Edge::cases() as $edge) {
        $calls['edge:' . $edge->name] = fn(Rect $b) => $chrome->edge($r, $b, $edge);
    }
    foreach (Direction::cases() as $direction) {
        $calls['arrow:' . $direction->name]       = fn(Rect $b) => $chrome->arrow($r, $b, $direction, [0, 0, 0]);
        $calls['sliderThumb:' . $direction->name] = fn(Rect $b) => $chrome->sliderThumb($r, $b, $direction, true);
    }
    foreach (CaptionButton::cases() as $button) {
        $calls['captionButton:' . $button->name] =
            fn(Rect $b) => $chrome->captionButton($r, $b, $button, ControlState::Hovered, true);
    }
    foreach ([true, false] as $active) {
        $calls['caption:' . ($active ? 'active' : 'inactive')] = fn(Rect $b) => $chrome->caption($r, $b, $active);
        $calls['tab:' . ($active ? 'active' : 'inactive')]     = fn(Rect $b) => $chrome->tab($r, $b, $active);
    }
    foreach (TextStyle::cases() as $style) {
        $calls['text:' . $style->name] = fn(Rect $b) => $chrome->text($r, 'Wg', $b->x, $b->y + 8, $style);
    }

    foreach ($calls as $name => $call) {
        foreach ($rects as $box) {
            try {
                $call($box);
            } catch (\Throwable $e) {
                $broke[] = sprintf('%s at %dx%d: %s', $name, $box->width, $box->height, $e->getMessage());
            }
        }
    }

    return $broke;
};

/**
 * The sizes worth trying. Ordinary, then the degenerate ones that turn up during
 * a resize or at a scrollbar's minimum.
 *
 * @return list<Rect>
 */
$sizes = [
    Rect::of(10, 10, 120, 28),   // a button
    Rect::of(10, 10, 300, 200),  // a panel
    Rect::of(10, 10, 14, 14),    // a checkbox
    Rect::of(10, 10, 16, 40),    // a scrollbar thumb
    Rect::of(10, 10, 3, 3),      // smaller than any corner radius
    Rect::of(10, 10, 1, 1),      // one pixel
    Rect::of(10, 10, 1, 60),     // a hairline
    Rect::of(10, 10, 0, 0),      // nothing at all
];

/** @var list<Theme> $themes */
$themes = [
    new Win9xTheme(), new Win31Theme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme(),
    new FluentTheme(), new MaterialTheme(),
];

[$renderer, $take] = $wire();

foreach ($themes as $theme) {
    foreach (array_keys($theme->variants()) as $variant) {
        $label = $theme->id() . ':' . $variant;
        $broke = $drawEverything($theme->chrome($variant), $renderer, $sizes);
        $bytes = $take();

        $check(sprintf('%s draws everything without throwing', $label), $broke, []);
        $check(sprintf('%s put bytes on the wire', $label), $bytes > 0, true);

        if ($broke !== []) {
            foreach (array_slice($broke, 0, 5) as $problem) printf("      %s\n", $problem);
        }
    }
}

printf("\n%s\n", $fail === 0 ? 'chrome smoke: all checks passed' : sprintf('chrome smoke: %d FAILED', $fail));
exit($fail === 0 ? 0 : 1);
