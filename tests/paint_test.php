<?php
declare(strict_types=1);

/**
 * The paint example's tools, undo stack and rubber-band preview.
 *
 * `example/lib/PaintDocument.php` is a plain class holding a
 * {@see \Cyrnetix\X11\UI\Widget\Canvas} — it takes paint events and writes
 * pixels, and knows nothing about an X server — so every tool can be driven
 * here with no display, the way `tests/calculator_test.php` drives the
 * calculator.
 *
 * The two behaviours worth pinning, because both are invisible in a screenshot:
 *
 *  - **The preview un-draws itself.** A shape tool redraws on every motion
 *    event, so it has to put back what the last one covered. Restore too little
 *    and a trail of old outlines is left behind; restore the whole image and
 *    every motion event repaints the entire canvas.
 *  - **Undo is the snapshot the stroke began from.** One copy does both jobs,
 *    and a stroke that is abandoned has to give its entry back — otherwise Undo
 *    silently does nothing the first time it is pressed.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/example/lib/PaintTool.php';
require dirname(__DIR__) . '/example/lib/PaintDocument.php';

use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Example\PaintDocument;
use Cyrnetix\X11\Example\PaintTool;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Event\CanvasPaintEvent;
use Cyrnetix\X11\UI\Event\CanvasPhase;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Canvas;
use Cyrnetix\X11\UI\WidgetTree;

$fail = 0;

$show = static function ($value) use (&$show): string {
    if ($value instanceof \UnitEnum) return $value->name;
    if (is_array($value)) return '[' . implode(', ', array_map($show, $value)) . ']';

    return var_export($value, true);
};

$check = function (string $what, $got, $want) use (&$fail, $show): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-56s %-24s %s\n", $what, $show($got), $ok ? 'ok' : 'FAIL (want ' . $show($want) . ')');
};

const WHITE = [255, 255, 255];
const BLACK = [0, 0, 0];
const RED   = [255, 0, 0];

$ui     = new SyncEventDispatcher(new ListenerRegistry());
$themes = new ThemeManager(new Win9xTheme());

/** A fresh 40x30 sheet of white paper and a document over it. */
$fresh = static function (int $undoLevels = 12) use ($ui, $themes): array {
    $tree   = new WidgetTree($themes);
    $canvas = new Canvas(0, 0, 44, 34, $ui, imageWidth: 40, imageHeight: 30, paper: WHITE);
    $tree->addRoot($canvas);

    // Wired exactly as example/paint.php wires it, so the test drives the same
    // path the application does.
    $document = new PaintDocument($canvas, colour: BLACK, paper: WHITE, undoLevels: $undoLevels);
    $canvas->setOnPaint($document->apply(...));

    return [$canvas, $document];
};

/** One paint event, as the handler would deliver it. */
$stroke = static function (Canvas $canvas, int $x, int $y, CanvasPhase $phase, ?int $fromX = null, ?int $fromY = null): void {
    $canvas->paintAt($x, $y, $fromX ?? $x, $fromY ?? $y, $phase);
};

// -----------------------------------------------------------------------------
echo "freehand\n";
// -----------------------------------------------------------------------------

[$canvas, $document] = $fresh();

$stroke($canvas, 2, 2, CanvasPhase::Begin);
$check('a press alone leaves a mark', $canvas->pixelAt(2, 2), BLACK);

// Motion arrives in jumps — this one skips eight pixels — and the stroke has to
// bridge them or it draws dots.
$stroke($canvas, 10, 2, CanvasPhase::Draw, 2, 2);
$check('the gap between two events is filled in', $canvas->pixelAt(6, 2), BLACK);
$check('and it stops where the pointer did',      $canvas->pixelAt(11, 2), WHITE);

$document->setBrushSize(3);
$stroke($canvas, 20, 20, CanvasPhase::Begin);
$check('a bigger brush is centred on the cursor',
    [$canvas->pixelAt(19, 19), $canvas->pixelAt(21, 21)], [BLACK, BLACK]);
$document->setBrushSize(1);

$document->setTool(PaintTool::Eraser);
$stroke($canvas, 2, 2, CanvasPhase::Begin);
$stroke($canvas, 10, 2, CanvasPhase::Draw, 2, 2);
$check('the eraser paints paper', $canvas->pixelAt(6, 2), WHITE);

// -----------------------------------------------------------------------------
echo "shapes and the preview\n";
// -----------------------------------------------------------------------------

[$canvas, $document] = $fresh();
$document->setTool(PaintTool::Line);

$stroke($canvas, 2, 2, CanvasPhase::Begin);
$stroke($canvas, 2, 20, CanvasPhase::Draw, 2, 2);
$check('a dragged line previews as it goes', $canvas->pixelAt(2, 10), BLACK);

// Drag somewhere else: the first preview has to be gone, not merged with the
// second. This is the check that fails if the restore is too narrow.
$stroke($canvas, 20, 2, CanvasPhase::Draw, 2, 20);
$check('the previous preview is put back, not left behind', $canvas->pixelAt(2, 10), WHITE);
$check('and the new one is drawn',                          $canvas->pixelAt(10, 2), BLACK);

$stroke($canvas, 20, 2, CanvasPhase::End, 20, 2);
$check('release commits what was previewed', $canvas->pixelAt(10, 2), BLACK);
$check('a committed shape is undoable',      $document->undoDepth(), 1);

// A thick preview spills half the brush outside the endpoints' own rectangle,
// so the restored band has to be grown by it.
[$canvas, $document] = $fresh();
$document->setTool(PaintTool::Line);
$document->setBrushSize(5);
$stroke($canvas, 6, 6, CanvasPhase::Begin);
$stroke($canvas, 6, 24, CanvasPhase::Draw, 6, 6);
$stroke($canvas, 30, 24, CanvasPhase::Draw, 6, 24);
$check('a thick preview leaves no edge of itself behind', $canvas->pixelAt(4, 12), WHITE);

// Either drag direction describes the same box.
[$canvas, $document] = $fresh();
$document->setTool(PaintTool::Rectangle);
$stroke($canvas, 20, 16, CanvasPhase::Begin);
$stroke($canvas, 6, 4, CanvasPhase::End, 20, 16);
$check('a box dragged up-left is still a box',
    [$canvas->pixelAt(6, 4), $canvas->pixelAt(20, 16), $canvas->pixelAt(13, 10)],
    [BLACK, BLACK, WHITE]);

[$canvas, $document] = $fresh();
$document->setTool(PaintTool::FilledRectangle);
$document->setColour(RED);
$stroke($canvas, 6, 4, CanvasPhase::Begin);
$stroke($canvas, 20, 16, CanvasPhase::End, 6, 4);
$check('a filled box is filled', $canvas->pixelAt(13, 10), RED);

[$canvas, $document] = $fresh();
$document->setTool(PaintTool::Ellipse);
$stroke($canvas, 4, 4, CanvasPhase::Begin);
$stroke($canvas, 24, 24, CanvasPhase::End, 4, 4);
$check('an ellipse meets the middle of its box',   $canvas->pixelAt(14, 4), BLACK);
$check('and keeps out of the corner',              $canvas->pixelAt(4, 4), WHITE);

// Changing tools mid-drag abandons the stroke rather than committing a shape
// nobody asked for — and gives back the undo entry it reserved.
[$canvas, $document] = $fresh();
$document->setTool(PaintTool::Rectangle);
$stroke($canvas, 4, 4, CanvasPhase::Begin);
$stroke($canvas, 24, 24, CanvasPhase::Draw, 4, 4);
$document->setTool(PaintTool::Pencil);
$check('an abandoned shape leaves no pixels', $canvas->pixelAt(4, 4), WHITE);
$check('and no undo entry',                   $document->undoDepth(), 0);

// -----------------------------------------------------------------------------
echo "fill\n";
// -----------------------------------------------------------------------------

[$canvas, $document] = $fresh();
$canvas->strokeRect(4, 4, 16, 12, BLACK);

$document->setTool(PaintTool::Fill);
$document->setColour(RED);
$stroke($canvas, 10, 10, CanvasPhase::Begin);

$check('the fill reaches the far side of the region', $canvas->pixelAt(18, 14), RED);
$check('it stops at the boundary',                    $canvas->pixelAt(4, 4), BLACK);
$check('and does not leak outside it',                $canvas->pixelAt(2, 2), WHITE);

$canvas->takeDamage();
$stroke($canvas, 10, 10, CanvasPhase::Begin);
$check('filling with the colour already there does nothing', $canvas->damage()->isEmpty(), true);

// A region open at one corner drains into the rest of the sheet — the honest
// behaviour of a flood fill, and the thing people expect a "leak" test to prove.
[$canvas, $document] = $fresh();
$canvas->strokeRect(4, 4, 16, 12, BLACK);
$canvas->fillRect(10, 4, 2, 1, WHITE);       // punch a hole in the top edge
$document->setTool(PaintTool::Fill);
$document->setColour(RED);
$stroke($canvas, 10, 10, CanvasPhase::Begin);
$check('an open region really does leak', $canvas->pixelAt(2, 2), RED);

// -----------------------------------------------------------------------------
echo "undo\n";
// -----------------------------------------------------------------------------

[$canvas, $document] = $fresh();

$check('nothing to undo at the start', $document->canUndo(), false);
$check('and undoing says so',          $document->undo(), false);

$stroke($canvas, 2, 2, CanvasPhase::Begin);
$stroke($canvas, 30, 2, CanvasPhase::Draw, 2, 2);
$stroke($canvas, 30, 2, CanvasPhase::End, 30, 2);
$check('a stroke can be taken back', $document->undo(), true);
$check('and the paper is paper again', $canvas->pixelAt(16, 2), WHITE);

// Each stroke is one entry, so undo walks back one stroke at a time rather than
// one segment at a time.
[$canvas, $document] = $fresh();
foreach ([4, 10, 16] as $row) {
    $stroke($canvas, 2, $row, CanvasPhase::Begin);
    $stroke($canvas, 30, $row, CanvasPhase::Draw, 2, $row);
    $stroke($canvas, 30, $row, CanvasPhase::End, 30, $row);
}
$check('three strokes, three entries', $document->undoDepth(), 3);
$document->undo();
$check('one undo takes back one stroke',
    [$canvas->pixelAt(16, 16), $canvas->pixelAt(16, 10)], [WHITE, BLACK]);

$document->clear();
$check('a clear clears',              $canvas->pixelAt(16, 10), WHITE);
$check('and is itself undoable',      $document->undo() && $canvas->pixelAt(16, 10) === BLACK, true);

// The stack has a floor, so a long session cannot grow without limit — the
// oldest strokes stop being undoable rather than the newest.
[$canvas, $document] = $fresh(undoLevels: 2);
foreach ([4, 10, 16] as $row) {
    $stroke($canvas, 2, $row, CanvasPhase::Begin);
    $stroke($canvas, 30, $row, CanvasPhase::End, 2, $row);
}
$check('the stack is capped',        $document->undoDepth(), 2);
$check('two undos are available',    [$document->undo(), $document->undo()], [true, true]);
$check('and then no more',           $document->undo(), false);
$check('the oldest stroke is what survives being forgotten', $canvas->pixelAt(16, 4), BLACK);

// -----------------------------------------------------------------------------
printf("\n%s\n", $fail === 0 ? 'paint: all checks passed' : sprintf('paint: %d FAILED', $fail));
exit($fail === 0 ? 0 : 1);
