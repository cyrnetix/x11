<?php
declare(strict_types=1);

/**
 * A paint program: a framebuffer in a window, drawn on with a pointer.
 *
 * This is the example for {@see \Cyrnetix\X11\UI\Widget\Canvas} — the widget for
 * everything the toolkit has no widget for. It is also, deliberately, the shape
 * pbrush had: an image held in the application's own memory, tools that write
 * into that memory, and a blit of the part that changed.
 *
 * What this example is for:
 *
 *  - **Pixels the theme has no opinion about.** Everything else in `src/UI` asks
 *    the theme what a colour is. A canvas holds *document* content, so its
 *    colours come from here — the sixteen literals below are the picture, not
 *    chrome, which is why they are allowed outside `src/Theme`. The well around
 *    the canvas, the group boxes, the buttons and the status bar are all still
 *    the theme's, and F2 proves it: cycle the era and the furniture changes
 *    while the drawing does not.
 *  - **Damage-driven repainting, at the one place it really matters.** A pencil
 *    stroke touches a handful of pixels. The canvas records what its own
 *    primitives wrote, the handler turns that into a `redrawRegion()`, and the
 *    painter blits just the clip — a few hundred bytes per motion event where a
 *    full repaint of this window is tens of kilobytes.
 *  - **A preview that restores instead of inverting.** Drag the Line or Box tool
 *    and the rubber band follows the cursor. Win32 did that with an XOR pen
 *    because it had no backing store to restore from; with a framebuffer the
 *    honest version is available, and it cannot leave a stray outline behind.
 *  - **A palette that is itself a canvas.** The sixteen swatches are drawn into a
 *    small Canvas, and clicking one reads the pixel back with `pixelAt()`. One
 *    widget, no new chrome, and a demonstration that the framebuffer reads as
 *    well as writes.
 *
 * The tools and the undo stack are in `example/lib/PaintDocument.php` and know
 * nothing about X11, which is what lets `tests/paint_test.php` drive every one of
 * them with no display attached.
 *
 * Keys: F2 cycles the theme, Ctrl+Z undoes, 1-5 set the brush size.
 *
 * Run it with:  php example/paint.php [--theme=platinum]
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/lib/PaintTool.php';
require __DIR__ . '/lib/PaintDocument.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ErrorEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Example\PaintDocument;
use Cyrnetix\X11\Example\PaintTool;
use Cyrnetix\X11\Handler\ConfigureHandler;
use Cyrnetix\X11\Handler\ErrorHandler;
use Cyrnetix\X11\Handler\ExposeHandler;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Fluent\FluentTheme;
use Cyrnetix\X11\Theme\Material\MaterialTheme;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\CanvasPaintEvent;
use Cyrnetix\X11\UI\Event\RadioToggledEvent;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\Handler as H;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter as P;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\Canvas;
use Cyrnetix\X11\UI\Widget\GroupBox;
use Cyrnetix\X11\UI\Widget\RadioButton;
use Cyrnetix\X11\UI\Widget\StatusBar;
use Cyrnetix\X11\UI\Widget\StatusBarPane;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

// ---- wiring ---------------------------------------------------------------

$themeId = 'win9x';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--theme=')) $themeId = substr($arg, 8);
}

$logger = new Logger('paint');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Warning));

$themes = new ThemeManager(
    new Win9xTheme(), new Win31Theme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme(),
    new FluentTheme(), new MaterialTheme(),
);

// "id" or "id:variant", so `--theme=fluent:dark` works.
[$themeId, $themeVariant] = array_pad(explode(':', $themeId, 2), 2, null);
$themes->select($themeId, $themeVariant);

$registry = new ListenerRegistry();
$renderer = new Renderer();
$client   = new X11Client(Loop::get(), new AsyncEventDispatcher($registry, $logger), $logger, $renderer, $themes);
$ui       = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());

$tree  = new WidgetTree($themes);
$frame = new WindowFrame(640, 480, 'Paint', drawsChrome: true);

// ---- the document ---------------------------------------------------------

/**
 * The paper. Colour literals in an example are the *picture*, not the look: a
 * drawing that changed colour with the window furniture would be a bug. These
 * sixteen are the standard VGA set, in the order Paint laid them out.
 *
 * @var list<array{int,int,int}>
 */
const PALETTE = [
    [  0,   0,   0], [128, 128, 128], [128,   0,   0], [128, 128,   0],
    [  0, 128,   0], [  0, 128, 128], [  0,   0, 128], [128,   0, 128],
    [255, 255, 255], [192, 192, 192], [255,   0,   0], [255, 255,   0],
    [  0, 255,   0], [  0, 255, 255], [  0,   0, 255], [255,   0, 255],
];

const PAPER  = [255, 255, 255];
const PAD    = 8;
const PANEL  = 112;      // left column width
const SWATCH = 18;       // one palette cell

// The paper is a fixed size, like Paint's. A canvas given no image size tracks
// its widget instead — right for a plot or a preview, wrong here: shrinking the
// window would crop the picture and there would be no getting it back.
$canvas = new Canvas(
    PAD + PANEL + PAD, PAD, 400, 300, $ui,
    imageWidth: 470, imageHeight: 330, paper: PAPER,
);

$document = new PaintDocument($canvas, colour: PALETTE[0], paper: PAPER);

// One closure, one owner: this is the widget's paint slot, and the document is
// what a stroke means. The handler supplies the coordinates and the phase.
$canvas->setOnPaint($document->apply(...));

// ---- the tool list --------------------------------------------------------

$toolsGroup = new GroupBox('Tools', PAD, PAD, PANEL, 0);
$frame->addChild($toolsGroup);

/** @var array<string, PaintTool> $radioTools  Radio label → the tool it selects. */
$radioTools = [];
$toolRow    = 0;
foreach (PaintTool::cases() as $tool) {
    // Radios group by their parent container, so dropping them all in this
    // GroupBox is what makes them mutually exclusive — there is no group id.
    $radio = new RadioButton($tool->label(), 0, $toolRow * 20, $ui, $tool === PaintTool::Pencil);
    $toolsGroup->addChild($radio);

    $radioTools[$tool->label()] = $tool;
    $toolRow++;
}

// ---- the palette, which is a canvas ---------------------------------------

$coloursGroup = new GroupBox('Colours', PAD, PAD, PANEL, 0);
$frame->addChild($coloursGroup);

$swatches = new Canvas(0, 0, 0, 0, $ui, imageWidth: SWATCH * 4, imageHeight: SWATCH * 4, paper: PAPER);
$current  = new Canvas(0, 0, 0, 0, $ui, imageWidth: SWATCH * 4, imageHeight: SWATCH, paper: PALETTE[0]);
$coloursGroup->addChild($swatches);
$coloursGroup->addChild($current);

foreach (PALETTE as $i => $rgb) {
    $swatches->fillRect(($i % 4) * SWATCH, intdiv($i, 4) * SWATCH, SWATCH, SWATCH, $rgb);
}

$undoButton  = new Button('Undo',  PAD, 0, PANEL, $themes->metrics()->dialogButtonHeight, $ui);
$clearButton = new Button('Clear', PAD, 0, PANEL, $themes->metrics()->dialogButtonHeight, $ui);
$frame->addChild($undoButton);
$frame->addChild($clearButton);

$status = new StatusBar(0, 0, 640);
$status->addPane(new StatusBarPane('Pencil', 92));
$status->addPane(new StatusBarPane('', 0));
$status->addPane(new StatusBarPane('470 x 330', 92));
$status->addPane(new StatusBarPane('Brush 1', 72));
$frame->addChild($status);

// Added last so it paints over nothing and nothing paints over it; $applyLayout
// below is what actually places and sizes it.
$frame->addChild($canvas);

// ---- repainting -----------------------------------------------------------

/**
 * Repaint whatever was drawn into a canvas, and nothing else.
 *
 * The same thing {@see H\CanvasHandler} does after a stroke, needed here because
 * these changes come from a button or a colour pick rather than from the pointer.
 * A canvas fills its own rectangle, so clearing the damaged region and painting
 * that one widget is sound.
 */
$repaintCanvas = static function (Canvas $target) use ($client): void {
    $damage = $target->takeDamage();
    if ($damage->isEmpty()) return;

    $client->redrawRegion($target->toWindow($damage), $target);
};

/**
 * Refresh the status bar, and repaint only it.
 *
 * The narrowed repaints in the toolkit are the handler's own; a change an
 * application makes from a canvas callback is the application's to repaint. This
 * is that: the pointer readout follows the cursor, so it has to be cheap, and
 * repainting nothing when the text is unchanged is cheaper still.
 */
$refreshStatus = static function (?int $x = null, ?int $y = null)
    use ($status, $document, $themes, $client, $canvas): void
{
    $wanted = [
        $document->tool()->label(),
        $x === null ? '' : sprintf('%d, %d', $x, $y),
        sprintf('%d x %d', $canvas->imageWidth(), $canvas->imageHeight()),
        sprintf('Brush %d', $document->brushSize()),
    ];

    $changed = false;
    foreach ($wanted as $i => $text) {
        if ($status->getPane($i)?->text === $text) continue;

        $status->setPaneText($i, $text);
        $changed = true;
    }
    if (!$changed) return;

    $client->redrawRegion(
        Rect::of($status->x, $status->y, $status->width, $themes->metrics()->statusBarHeight),
        $status,
    );
};

// ---- layout ---------------------------------------------------------------

/**
 * Everything the app positions itself, derived from the frame's *content* box.
 *
 * Runs on every resize and on every theme switch, because a theme moves the
 * caption, the border, the status bar's height and the canvas's own frame — the
 * numbers here are metrics lookups for exactly that reason.
 */
$applyLayout = static function (int $width, int $height) use (
    $frame, $themes, $toolsGroup, $coloursGroup, $swatches, $current,
    $undoButton, $clearButton, $status, $canvas
): void {
    $frame->setSize($width, $height);

    $m                      = $themes->metrics();
    [$contentW, $contentH]  = $frame->contentSize();
    $swatchBox              = SWATCH * 4 + 2 * $m->canvasBorder;

    $toolsGroup->setBounds(PAD, PAD, PANEL, $m->groupBoxPaddingTop + count(PaintTool::cases()) * 20 + 4);

    $coloursGroup->setBounds(
        PAD,
        $toolsGroup->relY + $toolsGroup->height + PAD,
        PANEL,
        $m->groupBoxPaddingTop + $swatchBox + 6 + $m->canvasBorder * 2 + SWATCH + 4,
    );
    $swatches->setSize($swatchBox, $swatchBox);
    $current->relY = $swatchBox + 6;
    $current->setSize($swatchBox, SWATCH + 2 * $m->canvasBorder);

    $undoButton->relY  = $coloursGroup->relY + $coloursGroup->height + PAD;
    $clearButton->relY = $undoButton->relY + $undoButton->height + 4;

    $status->relY = $contentH - $m->statusBarHeight;
    $status->setSize($contentW);

    $canvas->relX = PAD + PANEL + PAD;
    $canvas->relY = PAD;
    $canvas->setSize(
        max(0, $contentW - $canvas->relX - PAD),
        max(0, $status->relY - PAD - PAD),
    );

    // Re-resolve every child against the (possibly moved) content origin.
    $frame->relayout();
};

// ---- handlers -------------------------------------------------------------

$handlers = [
    new H\WindowFrameHandler($tree, $client, $ui, new DoubleClickDetector(), $renderer, new P\WindowFramePainter($themes)),
    new H\CanvasHandler($tree, $client, new P\CanvasPainter($themes)),
    new H\ButtonHandler($tree, $client, $logger, new P\ButtonPainter($themes)),
    new H\RadioButtonHandler($tree, $client, $renderer, $logger, new P\RadioButtonPainter($themes)),
    new H\GroupBoxHandler(new P\GroupBoxPainter($themes)),
    new H\StatusBarHandler(new P\StatusBarPainter($themes)),
    new H\LabelHandler(new P\LabelPainter($themes)),
];

$manager = new WidgetManager(
    $client, $renderer, $tree, new P\MessageBoxPainter($themes), new KeyTranslator(), $logger,
    $handlers,
    new H\TreeViewHandler($tree, $client, new DoubleClickDetector(), new P\TreeViewPainter($themes)),
);
$client->setWidgetManager($manager);
$manager->addChild($frame);
$manager->register($registry);

// ---- behaviour ------------------------------------------------------------

// Picking a colour: the swatch canvas is read, not consulted — pixelAt() gives
// back exactly what was drawn into it, so the palette needs no parallel table of
// where each colour went.
$swatches->setOnPaint(static function (CanvasPaintEvent $event) use ($document, $current, $repaintCanvas): void {
    $picked = $event->canvas->pixelAt($event->x, $event->y);
    if ($picked === null || $picked === $document->colour()) return;

    $document->setColour($picked);
    $current->clear($picked);
    $repaintCanvas($current);
});

$undoButton->setOnClick(static function () use ($document, $canvas, $repaintCanvas): void {
    $document->undo();
    $repaintCanvas($canvas);
});

$clearButton->setOnClick(static function () use ($document, $canvas, $repaintCanvas): void {
    $document->clear();
    $repaintCanvas($canvas);
});

// A radio has no callback slot of its own — it dispatches, and every radio in
// the group dispatches when the selection moves — so the listener reads the
// state rather than trusting the event to be the one that got checked.
$uiRegistry->addListener(
    RadioToggledEvent::class,
    static function (RadioToggledEvent $event) use ($radioTools, $document, $refreshStatus): void {
        if (!$event->radio->isChecked()) return;

        $tool = $radioTools[$event->radio->label] ?? null;
        if ($tool === null) return;

        $document->setTool($tool);
        $refreshStatus();
    },
);

// The pointer readout tracks the cursor whether or not a button is down, which
// is why it is here and not in the canvas's paint callback: that one only fires
// while drawing. Priority 0 runs before the widget manager's own motion
// handling at -10, and returning nothing consumes nothing.
$registry->addListener(
    X11MotionEvent::class,
    static function (X11MotionEvent $event) use ($canvas, $refreshStatus): PromiseInterface {
        if ($canvas->hitTest($event->x, $event->y)) {
            [$x, $y] = $canvas->toImage($event->x, $event->y);
            $refreshStatus($x, $y);
        } else {
            $refreshStatus();
        }

        return resolve(null);
    },
);

$keys     = new KeyTranslator();
$themeIds = ['win9x', 'win31', 'platinum', 'cde', 'beos', 'fluent', 'material'];

$registry->addListener(
    X11KeyPressEvent::class,
    static function (X11KeyPressEvent $event) use (
        $keys, $themes, $themeIds, $tree, $client, $applyLayout, $renderer, $frame,
        $document, $canvas, $repaintCanvas, $refreshStatus
    ): PromiseInterface {
        // The names come out of KeyTranslator::SPECIAL — 'Esc', not 'Escape'.
        $name = $keys->translate($event->keycode, $event->state);

        // F3 cycles the current theme's colour variants, where it has any.
        // Separate from F2 because they are separate choices: the era, and how
        // it is coloured.
        if ($name === 'F3' && $themes->selectNextVariant()) {
            $tree->refreshTheme();
            $client->applyTheme();
            $client->redraw();

            return resolve(null);
        }

        if ($name === 'F2') {
            $at = array_search($themes->currentId(), $themeIds, true);
            $themes->select($themeIds[(((int) $at) + 1) % count($themeIds)]);

            // A switch is: re-push the theme through the widgets, re-derive the
            // app's layout because the caption and the borders moved, re-shape
            // the window, then let the client update what the *server* holds.
            // The picture is untouched by all of it.
            $tree->refreshTheme();
            $applyLayout($client->getWindowWidth(), $client->getWindowHeight());
            $client->setWindowShape($frame->shapeRects($renderer));
            $client->applyTheme();
            $client->redraw();

            return resolve(null);
        }

        if ($name === 'Ctrl+Z') {
            $document->undo();
            $repaintCanvas($canvas);

            return resolve(null);
        }

        if ($name !== null && preg_match('/^[1-5]$/', $name) === 1) {
            $document->setBrushSize((int) $name);
            $refreshStatus();
        }

        return resolve(null);
    },
);

// Closing is the application's decision: the caption's close button only asks.
$uiRegistry->addListener(
    WindowCloseRequestedEvent::class,
    static function (WindowCloseRequestedEvent $event) use ($client): void {
        $client->closeWindow();
    },
);

// Worth having in *this* example in particular: a PutImage is the one request
// here that can be malformed in a way nothing on screen explains — a bad depth
// or a short row comes back as a protocol error and an empty canvas, not as a
// crash. Without a listener the error is parsed and dropped.
$registry->addListener(X11ErrorEvent::class,     new ErrorHandler($logger));
$registry->addListener(X11ExposeEvent::class,    new ExposeHandler($client, $logger));
$registry->addListener(X11ConfigureEvent::class, new ConfigureHandler($client, $logger));
$registry->addListener(
    X11ConfigureEvent::class,
    static function (X11ConfigureEvent $event) use ($client, $frame, $applyLayout, $renderer): PromiseInterface {
        if ($event->windowId === $client->getWindowId()) {
            $applyLayout($event->width, $event->height);
            $client->setWindowShape($frame->shapeRects($renderer));
            $client->redraw();
        }

        return resolve(null);
    },
);

$applyLayout(640, 480);

$client->setWindowTitle('Paint');
$client->setWindowSize(640, 480);
$client->setDecorated(false);
$client->connect();

Loop::run();
