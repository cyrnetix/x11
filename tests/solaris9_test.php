<?php
declare(strict_types=1);

/**
 * Solaris 9: the sampled pixels, pinned.
 *
 * The theme's numbers were read off 1:1 screenshots of CDE 1.5 on Solaris 9,
 * and a screenshot at 1:1 hides a one-pixel mistake completely. So this does
 * what the lost `win31_assert.php` did: it replays what the chrome put on the
 * wire into a pixel grid and compares *runs* of colour along the same lines
 * that were scanned in the screenshots, in the same notation — `DCADC2*2`
 * means two pixels of that colour. An expected string here is a line of a
 * real Solaris 9 window, transcribed.
 *
 * The rasteriser understands the five requests a chrome draws with — ChangeGC
 * for the colour, PolyFillRectangle, PolyRectangle, PolySegment, PolyLine —
 * and ignores text, which is what the screenshots were scanned around too.
 *
 * Also here: the toolkit hooks this theme needed, checked to leave every other
 * theme exactly where it was.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Solaris9\Solaris9Theme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\UI\Painter\FormWindowPainter;
use Cyrnetix\X11\UI\Painter\ScrollBarPainter;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\ScrollBar;
use Cyrnetix\X11\UI\Widget\ScrollOrientation;
use Cyrnetix\X11\UI\Painter\WindowFramePainter;
use Cyrnetix\X11\UI\Widget\FormWindow;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetTree;
use React\Stream\WritableStreamInterface;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    $show = static function ($v): string {
        $t = is_array($v) ? (string) json_encode($v) : var_export($v, true);

        return strlen($t) > 70 ? substr($t, 0, 67) . '...' : $t;
    };
    printf("  %-54s %s\n", $what, $ok ? 'ok' : 'FAIL');
    if (!$ok) printf("      got  %s\n      want %s\n", $show($got), $show($want));
};

/** Keeps whatever the renderer writes. */
final class Sink implements WritableStreamInterface
{
    public string $written = '';

    /** Appends what was written. */
    public function write($data): bool { $this->written .= $data; return true; }

    public function isWritable(): bool { return true; }
    public function end($data = null): void {}
    public function close(): void {}
    public function on($event, callable $listener): void {}
    public function once($event, callable $listener): void {}
    public function removeListener($event, callable $listener): void {}
    public function removeAllListeners($event = null): void {}
    public function listeners($event = null): array { return []; }
    public function emit($event, array $arguments = []): void {}
}

/** A pixel grid filled by replaying X11 drawing requests. */
final class Raster
{
    /** @var array<int, array<int, int>> [y][x] => 0xRRGGBB */
    private array $px = [];

    /** Replays the requests in $wire. */
    public function replay(string $wire): void
    {
        $fg  = 0;
        $at  = 0;
        $len = strlen($wire);

        while ($at + 4 <= $len) {
            $opcode = ord($wire[$at]);
            $size   = unpack('v', $wire, $at + 2)[1] * 4;
            if ($size < 4) break;
            $body = substr($wire, $at, $size);

            switch ($opcode) {
                case 56:   // ChangeGC
                    if (unpack('V', $body, 8)[1] & 0x04) {
                        $fg = unpack('V', $body, 12)[1] & 0xFFFFFF;
                    }
                    break;
                case 70:   // PolyFillRectangle
                    for ($o = 12; $o + 8 <= $size; $o += 8) {
                        $q = unpack('sx/sy/vw/vh', $body, $o);
                        $this->fill($q['x'], $q['y'], $q['w'], $q['h'], $fg);
                    }
                    break;
                case 67:   // PolyRectangle: an outline, w+1 by h+1 pixels
                    for ($o = 12; $o + 8 <= $size; $o += 8) {
                        $q = unpack('sx/sy/vw/vh', $body, $o);
                        $this->fill($q['x'], $q['y'], $q['w'] + 1, 1, $fg);
                        $this->fill($q['x'], $q['y'] + $q['h'], $q['w'] + 1, 1, $fg);
                        $this->fill($q['x'], $q['y'], 1, $q['h'] + 1, $fg);
                        $this->fill($q['x'] + $q['w'], $q['y'], 1, $q['h'] + 1, $fg);
                    }
                    break;
                case 66:   // PolySegment
                    for ($o = 12; $o + 8 <= $size; $o += 8) {
                        $s = unpack('sx1/sy1/sx2/sy2', $body, $o);
                        $this->line($s['x1'], $s['y1'], $s['x2'], $s['y2'], $fg);
                    }
                    break;
                case 65:   // PolyLine
                    $prev = null;
                    for ($o = 12; $o + 4 <= $size; $o += 4) {
                        $pt = unpack('sx/sy', $body, $o);
                        if ($prev !== null) $this->line($prev[0], $prev[1], $pt['x'], $pt['y'], $fg);
                        $prev = [$pt['x'], $pt['y']];
                    }
                    break;
                case 64:   // PolyPoint
                    for ($o = 12; $o + 4 <= $size; $o += 4) {
                        $pt = unpack('sx/sy', $body, $o);
                        $this->fill($pt['x'], $pt['y'], 1, 1, $fg);
                    }
                    break;
            }
            $at += $size;
        }
    }

    /**
     * Runs of colour along a horizontal or vertical line, as the screenshots
     * were scanned: "DCADC2*2 B24D7A*2 ...". An untouched pixel is "------".
     */
    public function runs(int $x1, int $y1, int $x2, int $y2): string
    {
        $out  = [];
        $prev = null;
        $n    = 0;
        $pts  = [];
        if ($y1 === $y2) { for ($x = $x1; $x <= $x2; $x++) $pts[] = [$x, $y1]; }
        else             { for ($y = $y1; $y <= $y2; $y++) $pts[] = [$x1, $y]; }

        foreach ($pts as [$x, $y]) {
            $k = isset($this->px[$y][$x]) ? sprintf('%06X', $this->px[$y][$x]) : '------';
            if ($k !== $prev) {
                if ($prev !== null) $out[] = "$prev*$n";
                $prev = $k;
                $n    = 0;
            }
            $n++;
        }
        if ($prev !== null) $out[] = "$prev*$n";

        return implode(' ', $out);
    }

    private function fill(int $x, int $y, int $w, int $h, int $c): void
    {
        for ($j = 0; $j < $h; $j++) {
            for ($i = 0; $i < $w; $i++) $this->px[$y + $j][$x + $i] = $c;
        }
    }

    private function line(int $x1, int $y1, int $x2, int $y2, int $c): void
    {
        $dx = abs($x2 - $x1); $sx = $x1 < $x2 ? 1 : -1;
        $dy = -abs($y2 - $y1); $sy = $y1 < $y2 ? 1 : -1;
        $err = $dx + $dy;
        while (true) {
            $this->px[$y1][$x1] = $c;
            if ($x1 === $x2 && $y1 === $y2) break;
            $e2 = 2 * $err;
            if ($e2 >= $dy) { $err += $dy; $x1 += $sx; }
            if ($e2 <= $dx) { $err += $dx; $y1 += $sy; }
        }
    }
}

/** Bytes a paint produces, from a renderer of its own. */
$bytesOf = static function (callable $paint): string {
    $sink = new Sink();
    $r    = new Renderer();
    $r->init($sink, windowId: 0x100, gcId: 0x200);
    $paint($r);
    $r->flush();

    return $sink->written;
};

/** A raster of what a paint drew. */
$rasterOf = static function (callable $paint) use ($bytesOf): Raster {
    $raster = new Raster();
    $raster->replay($bytesOf($paint));

    return $raster;
};

$themes = new ThemeManager(new Solaris9Theme());
$chrome = $themes->chrome();
$m      = $themes->metrics();

// ---- a dialog: Style Manager - Color, 342 x 321 ----------------------------
// The frame band, the title bar and its menu button, and XmBulletinBoard's ring
// under the caption, each line transcribed from the screenshot.
echo "a dialog's frame and caption\n";
$tree = new WidgetTree($themes);
$form = new FormWindow(342, 321, 'Style Manager - Color');
$form->setVisible(true);
$tree->addRoot($form);
$dialog = $rasterOf(static fn(Renderer $r) => (new FormWindowPainter($themes))->paint($form, $r));

$check('down the middle: frame, title bar, client ring',
    $dialog->runs(170, 0, 170, 24),
    'DCADC2*2 B24D7A*2 57253B*1 DCADC2*1 B24D7A*17 57253B*1 DCDEE5*1');
$check('across the title bar: frame, menu button and its bar, title panel',
    $dialog->runs(0, 14, 30, 14),
    'DCADC2*2 B24D7A*2 57253B*1 DCADC2*1 B24D7A*3 DCADC2*1 B24D7A*9 57253B*1 B24D7A*3 57253B*1 DCADC2*1 B24D7A*6');
$check('the title panel closes against the frame at the right',
    $dialog->runs(330, 14, 341, 14),
    'B24D7A*6 57253B*1 DCADC2*1 B24D7A*2 57253B*2');
$check('the left edge below the caption: frame, then the ring',
    $dialog->runs(0, 160, 9, 160),
    'DCADC2*2 B24D7A*2 57253B*1 DCDEE5*1 AEB2C3*4');
$check('the right edge: the ring, then the frame',
    $dialog->runs(330, 160, 341, 160),
    'AEB2C3*6 5D6069*1 DCADC2*1 B24D7A*2 57253B*2');
$check('the bottom edge: the ring, then the frame',
    $dialog->runs(170, 314, 170, 320),
    'AEB2C3*1 5D6069*1 DCADC2*1 B24D7A*2 57253B*2');
$check('no corner handles break the band',
    $dialog->runs(2, 0, 2, 320),
    'DCADC2*2 B24D7A*317 57253B*2');

// ---- a window: the File Manager, 565 x 429 ---------------------------------
echo "a window's frame and caption\n";
$frame = new WindowFrame(565, 429, 'File Manager', drawsChrome: true);
$tree->addRoot($frame);
$renderer = new Renderer();
$window   = $rasterOf(static fn(Renderer $r) => (new WindowFramePainter($themes))->paint($frame, $r));

$check('minimise and maximise are flush, 19 px, and there is no close box',
    $window->runs(518, 14, 564, 14),
    'B24D7A*3 57253B*1 DCADC2*1 B24D7A*6 DCADC2*1 B24D7A*2 57253B*1 B24D7A*7 57253B*1 DCADC2*1 B24D7A*3 DCADC2*1 B24D7A*9 57253B*1 B24D7A*3 57253B*1 DCADC2*1 B24D7A*2 57253B*2');
$check('down through the minimise glyph, to the client ring',
    $window->runs(531, 4, 531, 24),
    '57253B*1 DCADC2*1 B24D7A*6 DCADC2*1 B24D7A*2 57253B*1 B24D7A*7 57253B*1 DCDEE5*1');
$check('down through the maximise glyph',
    $window->runs(550, 4, 550, 24),
    '57253B*1 DCADC2*1 B24D7A*3 DCADC2*1 B24D7A*9 57253B*1 B24D7A*3 57253B*1 DCDEE5*1');
$check('down through the window-menu bar',
    $window->runs(14, 4, 14, 24),
    '57253B*1 DCADC2*1 B24D7A*6 DCADC2*1 B24D7A*2 57253B*1 B24D7A*7 57253B*1 DCDEE5*1');
// The frame painter leaves the client area to the window's background pixel,
// so these stop at the ring.
$check('the client ring on the left',  $window->runs(0, 38, 5, 38),     'DCADC2*2 B24D7A*2 57253B*1 DCDEE5*1');
$check('the client ring on the right', $window->runs(559, 250, 564, 250), '5D6069*1 DCADC2*1 B24D7A*2 57253B*2');
$check('the client ring at the bottom', $window->runs(300, 423, 300, 428), '5D6069*1 DCADC2*1 B24D7A*2 57253B*2');

$buttons = [];
foreach ($frame->captionButtons($renderer) as [$button, $rect]) $buttons[$button->name] = [$rect->x, $rect->y, $rect->width];
$check('the three buttons, where dtwm puts them',
    $buttons, ['Menu' => [5, 5, 19], 'Minimize' => [522, 5, 19], 'Maximize' => [541, 5, 19]]);

$frame->setActive(false);
$inactive = $rasterOf(static fn(Renderer $r) => (new WindowFramePainter($themes))->paint($frame, $r));
$check('an inactive frame is the face with the face\'s shadows',
    $inactive->runs(350, 0, 350, 24),
    'DCDEE5*2 AEB2C3*2 5D6069*1 DCDEE5*1 AEB2C3*17 5D6069*1 DCDEE5*1');

// ---- a scrollbar: the Style Manager's palette list, 13 x 106 at (174, 50) --
// Drawn through the chrome with the rectangles ScrollBarPainter computes.
echo "a scrollbar\n";
$bar = $rasterOf(static function (Renderer $r) use ($chrome): void {
    $outer = Rect::of(174, 50, 13, 106);
    $chrome->scrollTrack($r, $outer->insetEach(0, 13, 0, 13), false, true);
    $chrome->scrollArrow($r, $outer->topSlice(13), Direction::Up, ControlState::Normal);
    $chrome->scrollArrow($r, $outer->bottomSlice(13), Direction::Down, ControlState::Normal);
    $chrome->scrollThumb($r, Rect::of(174, 89, 13, 13), false);
});
$check('the trough\'s ring runs over the arrows', $bar->runs(174, 50, 186, 50), '5D6069*13');
$check('the up arrow: a lit tip',          $bar->runs(174, 51, 186, 51), '5D6069*1 9397A5*5 DCDEE5*1 9397A5*5 DCDEE5*1');
$check('  then edge pixels where it widens', $bar->runs(174, 52, 186, 52), '5D6069*1 9397A5*4 DCDEE5*1 AEB2C3*1 5D6069*1 9397A5*4 DCDEE5*1');
$check('  and none where it does not',      $bar->runs(174, 53, 186, 53), '5D6069*1 9397A5*4 AEB2C3*3 9397A5*4 DCDEE5*1');
$check('  the fourth row',                  $bar->runs(174, 54, 186, 54), '5D6069*1 9397A5*3 DCDEE5*1 AEB2C3*3 5D6069*1 9397A5*3 DCDEE5*1');
$check('  the widest row',                  $bar->runs(174, 60, 186, 60), '5D6069*1 DCDEE5*1 AEB2C3*9 5D6069*1 DCDEE5*1');
$check('  the base: one lit pixel, then shadow', $bar->runs(174, 61, 186, 61), '5D6069*1 DCDEE5*1 5D6069*10 DCDEE5*1');
$check('  trough below it',                 $bar->runs(174, 62, 186, 62), '5D6069*1 9397A5*11 DCDEE5*1');
$check('the down arrow: a lit base along the top', $bar->runs(174, 144, 186, 144), '5D6069*1 DCDEE5*12');
$check('  and the same slants',             $bar->runs(174, 151, 186, 151), '5D6069*1 9397A5*3 DCDEE5*1 AEB2C3*3 5D6069*1 9397A5*3 DCDEE5*1');
$check('the ring\'s bottom row is highlight',  $bar->runs(174, 155, 186, 155), '5D6069*1 DCDEE5*12');
$check('the slider: highlight across its top', $bar->runs(174, 89, 186, 89), '5D6069*1 DCDEE5*12');
$check('  its sides',                        $bar->runs(174, 95, 186, 95), '5D6069*1 DCDEE5*1 AEB2C3*9 5D6069*1 DCDEE5*1');
$check('  its bottom row, lit at the corner', $bar->runs(174, 101, 186, 101), '5D6069*1 DCDEE5*1 5D6069*10 DCDEE5*1');

// And through the painter, with a real widget, so the hand-off to
// scrollArrow() is exercised and not just the primitives.
$widget = new ScrollBar(ScrollOrientation::Vertical, 174, 50, 13, 106, new SyncEventDispatcher(new ListenerRegistry()));
$tree->addRoot($widget);
$painted = $rasterOf(static fn(Renderer $r) => (new ScrollBarPainter($themes))->paint($widget, $r));
$check('the painter draws the same bar', [$painted->runs(174, 50, 186, 50), $painted->runs(174, 52, 186, 52), $painted->runs(174, 155, 186, 155)],
    ['5D6069*13', '5D6069*1 9397A5*4 DCDEE5*1 AEB2C3*1 5D6069*1 9397A5*4 DCDEE5*1', '5D6069*1 DCDEE5*12']);

// ---- controls ---------------------------------------------------------------
echo "controls\n";
$ok = $rasterOf(static fn(Renderer $r) => $chrome->button($r, Rect::of(18, 280, 94, 27), ControlState::Default));
$check('a default button: sunken ring, two of face, one-pixel bevel',
    $ok->runs(65, 280, 65, 306), '5D6069*1 AEB2C3*2 DCDEE5*1 AEB2C3*19 5D6069*1 AEB2C3*2 DCDEE5*1');
$check('  and across',
    $ok->runs(18, 293, 111, 293), '5D6069*1 AEB2C3*2 DCDEE5*1 AEB2C3*86 5D6069*1 AEB2C3*2 DCDEE5*1');

$plain = $rasterOf(static fn(Renderer $r) => $chrome->button($r, Rect::of(250, 340, 100, 25), ControlState::Normal));
$check('a plain button is 1 + 23 + 1', $plain->runs(300, 340, 300, 364), 'DCDEE5*1 AEB2C3*23 5D6069*1');
$held = $rasterOf(static fn(Renderer $r) => $chrome->button($r, Rect::of(250, 340, 100, 25), ControlState::Pressed));
$check('held, the shadows swap and nothing moves', $held->runs(300, 340, 300, 364), '5D6069*1 AEB2C3*23 DCDEE5*1');

$off = $rasterOf(static fn(Renderer $r) => $chrome->checkBox($r, Rect::of(27, 226, 12, 12), false, ControlState::Normal));
$check('a check box off: raised, 12 px', $off->runs(27, 233, 38, 233), 'DCDEE5*1 AEB2C3*10 5D6069*1');
$on = $rasterOf(static fn(Renderer $r) => $chrome->checkBox($r, Rect::of(27, 180, 12, 12), true, ControlState::Normal));
$check('on: sunken and filled with the select colour', $on->runs(28, 180, 28, 191), '5D6069*1 9397A5*10 DCDEE5*1');
$check('  with a tick in it', str_contains($on->runs(27, 187, 38, 187), '000000'), true);

$radio = $rasterOf(static fn(Renderer $r) => $chrome->radioButton($r, Rect::of(26, 122, 15, 15), false, ControlState::Normal));
$check('a radio button off: a 15 px circle, lit above', $radio->runs(26, 130, 40, 130), 'DCDEE5*1 AEB2C3*13 5D6069*1');
$check('  and down a column near its edge',            $radio->runs(31, 122, 31, 136), 'DCDEE5*1 AEB2C3*13 5D6069*1');
$set = $rasterOf(static fn(Renderer $r) => $chrome->radioButton($r, Rect::of(26, 146, 15, 15), true, ControlState::Normal));
$check('on: the rim sinks and ten pixels of rose start three in', $set->runs(26, 153, 40, 153), '5D6069*1 AEB2C3*2 B24D7A*10 AEB2C3*1 DCDEE5*1');
$check('  ten rows of it',                                       $set->runs(31, 146, 31, 160), '5D6069*1 AEB2C3*2 B24D7A*10 AEB2C3*1 DCDEE5*1');

$field = $rasterOf(static fn(Renderer $r) => $chrome->well($r, Rect::of(15, 116, 395, 27)));
$check('a text field is shaded from its own cream', $field->runs(300, 116, 300, 142), 'AEB2C3*1 99948B*1 FFF7E9*23 CCC5BA*1 AEB2C3*1');
$check('  on the left too',                          $field->runs(15, 130, 18, 130),   'AEB2C3*1 99948B*1 FFF7E9*2');

$menubar = $rasterOf(static fn(Renderer $r) => $chrome->menuBar($r, Rect::of(6, 25, 553, 26)));
$check('the menu bar has only a bottom shadow', $menubar->runs(300, 25, 300, 50), 'AEB2C3*25 5D6069*1');

$scale = $rasterOf(static function (Renderer $r) use ($chrome): void {
    $chrome->sliderTrack($r, Rect::of(122, 205, 155, 15), true);
    $chrome->sliderThumb($r, Rect::of(236, 206, 30, 13), Direction::Down, false);
});
$check('XmScale: the slider inside the trough\'s ring, grooved', $scale->runs(230, 213, 270, 213),
    '9397A5*6 DCDEE5*1 AEB2C3*13 5D6069*1 DCDEE5*1 AEB2C3*13 5D6069*1 9397A5*5');
$check('  down the groove', $scale->runs(250, 205, 250, 219), '5D6069*1 DCDEE5*1 5D6069*12 DCDEE5*1');

// ---- what this theme asked of the toolkit ----------------------------------
echo "the hooks, and that the other themes did not move\n";
$check('no close box on a window',            in_array(CaptionButton::Close, $m->captionTrailing, true), false);
$check('nor on a dialog',                     $m->dialogCaptionTrailing, []);
$check('a dialog border that is not the bevel', [$m->edge, $m->dialogBorder()], [2, 5]);
$check('the older themes inset dialogs by their edge, as before',
    [(new Win9xTheme())->metrics()->dialogBorder(), (new Win31Theme())->metrics()->dialogBorder()], [2, 5]);
$win9x = new Win9xTheme();
$check('a palette without caption shadows falls back to the face\'s',
    [$win9x->palette()->captionHighlight(), $win9x->palette()->contentShadow()],
    [$win9x->palette()->faceHighlight, $win9x->palette()->faceShadow]);

// The scroll arrow the painter now asks the chrome for is, for every theme
// that did not override it, byte for byte what the painter used to draw.
$cap  = Rect::of(100, 100, 16, 16);
$was  = $bytesOf(static function (Renderer $r) use ($win9x, $cap): void {
    $win9x->chrome()->button($r, $cap, ControlState::Pressed);
    $win9x->chrome()->arrow($r, $cap->shift(1, 1), Direction::Up, $win9x->palette()->text);
});
$now  = $bytesOf(static fn(Renderer $r) => $win9x->chrome()->scrollArrow($r, $cap, Direction::Up, ControlState::Pressed));
$check('scrollArrow() defaults to the button-and-arrow it replaced', $now === $was && $now !== '', true);

$check('one variant, named after itself', $themes->current()->variants(), ['default' => 'Solaris 9']);
$check('no bold face: the title is the interface font', (new Solaris9Theme())->boldFontCandidates(), []);

printf("\n%s\n", $fail === 0 ? 'solaris9: all checks passed' : sprintf('solaris9: %d FAILED', $fail));
exit($fail === 0 ? 0 : 1);
