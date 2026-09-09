<?php
declare(strict_types=1);

/**
 * A calculator: a button grid, a display, and the keyboard.
 *
 * What this example is for:
 *
 *  - **A grid of widgets built in a loop**, rather than laid out by hand. The
 *    buttons come from a table, so adding a row is one line.
 *  - **Keyboard and mouse doing the same thing.** Every key routes into the same
 *    `press()` the buttons call, so there is one implementation of what a key
 *    means and nothing can drift apart.
 *  - **A theme switch at runtime** — press F2. Nothing is re-wired: the painters
 *    read the live theme per call, so the whole window changes era in place.
 *  - **A window that cannot be resized**, which takes two things: the frame drops
 *    the maximise button and the border drag, and WM_NORMAL_HINTS tells the window
 *    manager the same. Either one alone leaves a way to stretch it.
 *  - **Answering the close button.** The toolkit only *asks*, so an application
 *    can prompt about unsaved work; a window whose owner ignores the request
 *    cannot be closed.
 *
 * Run it with:  php example/calculator.php [--theme=platinum]
 */
require dirname(__DIR__) . '/vendor/autoload.php';

// The arithmetic lives in its own file and knows nothing about X11, which is what
// lets tests/calculator_test.php drive it without an X server.
require __DIR__ . '/lib/Calculator.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Example\Calculator;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use Cyrnetix\X11\Handler\ConfigureHandler;
use Cyrnetix\X11\Handler\ExposeHandler;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Fluent\FluentTheme;
use Cyrnetix\X11\Theme\Material\MaterialTheme;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Handler as H;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter as P;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\TextView;
use Cyrnetix\X11\UI\Widget\MessageBox;
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

$logger = new Logger('calc');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Warning));

$themes = new ThemeManager(
    new Win9xTheme(), new Win31Theme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme(),
    new FluentTheme(), new MaterialTheme(),
);

// "id" or "id:variant" — several themes now carry more than one palette, and a
// dark one is a reasonable thing to ask for on the command line.
[$themeId, $themeVariant] = array_pad(explode(':', $themeId, 2), 2, null);
$themes->select($themeId, $themeVariant);

$registry = new ListenerRegistry();
$renderer = new Renderer();
$client   = new X11Client(Loop::get(), new AsyncEventDispatcher($registry, $logger), $logger, $renderer, $themes);
$ui       = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());

$tree  = new WidgetTree($themes);
// Fixed size, in both halves. `resizable: false` removes the maximise button,
// the border drag and the caption's double-click-to-maximise — the gestures the
// *toolkit* owns — and $client->setResizable(false) below writes WM_NORMAL_HINTS
// so the *window manager* refuses too. Either route can resize a window on its
// own, so a calculator that must not be stretched needs both.
$frame = new WindowFrame(240, 300, 'Calculator', drawsChrome: true, resizable: false);
$box   = new MessageBox($ui, $themes);

$calc = new Calculator();

// The display is a TextView: read-only, so it cannot be typed into, and it draws
// the theme's sunken well around itself. It is also selectable, so the answer can
// be copied out with Ctrl+C.
//
// Not a Label - a Label stores no width and paints no background, and giving one
// a background means naming a colour, which belongs in src/Theme and nowhere
// else.
//
// DISPLAY_H is what the display is *asked* for, and not what it gets: TextView
// has a minimum height of its own, which is why the layout below reads the
// height back rather than adding this to PAD.
const DISPLAY_H = 30;

$display = new TextView(0, 0, 200, DISPLAY_H, $ui, lineHeight: 20);
$display->setText($calc->display());
$frame->addChild($display);

/**
 * The keypad, as a table. Row by row, left to right — so the layout below is a
 * loop and adding a row costs one line here.
 */
$keys = [
    ['C', '±', '/', '*'],
    ['7', '8', '9', '-'],
    ['4', '5', '6', '+'],
    ['1', '2', '3', '='],
    ['0', '.'],
];

$repaint = static function () use ($calc, $display, $client): void {
    $display->setText($calc->display());
    $client->redraw();
};

// The keypad's geometry, decided once: a Button's width is readonly, so it is set
// at construction and the layout below only ever *moves* buttons. That is a fair
// trade here because the window is fixed-size, so there is no width to follow.
const PAD  = 8;
const GAP  = 4;
const CELL = 28;

/**
 * Keys whose label is not its own bytes. See the note where a Button is made.
 *
 * @var array<string, string> FACES
 */
const FACES = ['±' => "\xB1"];

$columns = max(array_map('count', $keys));
$cellW   = intdiv(240 - 2 * $frame->contentOffsetX() - 2 * PAD - ($columns - 1) * GAP, $columns);

/** @var array<string, Button> $buttons */
$buttons = [];
foreach ($keys as $row) {
    // A short row shares the width rather than leaving a hole: "0" spans three
    // cells and "." takes one.
    $spans = count($row) === $columns ? array_fill(0, $columns, 1) : [3, 1];

    foreach ($row as $c => $key) {
        $span  = $spans[$c] ?? 1;
        $width = $cellW * $span + GAP * ($span - 1);

        // The face, not the key. The core font is iso8859-1, so the UTF-8 '±'
        // the model compares against drew as the two glyphs "Â±"; the same
        // reason MessageBoxPainter writes " ..." rather than an ellipsis. Only
        // the label is translated — the key stays what Calculator knows.
        $button = new Button(FACES[$key] ?? $key, 0, 0, $width, CELL, $ui);
        // Every button and every key goes through the one press(), so the two
        // input routes cannot mean different things.
        $button->setOnClick(static function () use ($calc, $key, $repaint): void {
            $calc->press($key);
            $repaint();
        });
        $frame->addChild($button);
        $buttons[$key] = $button;
    }
}

/** Position the display and the keypad from the frame's content box. */
$layout = static function (int $width, int $height) use ($frame, $display, $buttons, $keys): void {
    $frame->setSize($width, $height);

    $inner = $width - 2 * $frame->contentOffsetX() - 2 * PAD;

    $display->relX = PAD;
    $display->relY = PAD;
    $display->setSize(max(60, $inner), DISPLAY_H);

    // Under the display, by the same margin as everything else — reading the
    // height the display *took* rather than the one it was given. TextView
    // clamps to a minimum of 40, so a hardcoded `PAD + 38` here put the first
    // row of buttons two pixels inside the display's own border, on every
    // theme that draws one.
    $top = $display->relY + $display->height + PAD;
    foreach ($keys as $r => $row) {
        $x = PAD;
        foreach ($row as $key) {
            $button = $buttons[$key];
            $button->relX = $x;
            $button->relY = $top + $r * (CELL + GAP);
            $x += $button->width + GAP;
        }
    }

    $frame->relayout();
};

$handlers = [
    new H\WindowFrameHandler($tree, $client, $ui, new DoubleClickDetector(), $renderer, new P\WindowFramePainter($themes)),
    new H\ButtonHandler($tree, $client, $logger, new P\ButtonPainter($themes)),
    new H\ScrollBarHandler($tree, $client, new P\ScrollBarPainter($themes)),
    new H\TextViewHandler($tree, $client, new P\TextViewPainter($themes)),
    new H\LabelHandler(new P\LabelPainter($themes)),
];

$manager = new WidgetManager(
    $client, $renderer, $tree, new P\MessageBoxPainter($themes), new KeyTranslator(), $logger,
    $handlers,
    new H\TreeViewHandler($tree, $client, new DoubleClickDetector(), new P\TreeViewPainter($themes)),
);
$client->setWidgetManager($manager);
$manager->addChild($frame);
$manager->setDialog($box);
$manager->register($registry);

// ---- the keyboard --------------------------------------------------------
// There is no app-level "key pressed" widget event: keys arrive as raw
// X11KeyPressEvents, and a keycode is a physical key rather than a character, so
// KeyTranslator turns it into a name. The default priority (0) runs before the
// widget manager's own key handling, which sits at -10.
//
// `Enter` is deliberately *not* mapped to `=`: a Button is focusable, so Enter
// already activates whichever one has focus, and mapping it here would press two
// keys at once. The `=` key does equals; Escape clears.
$keys = new KeyTranslator();

// The theme order F2 cycles through. Registration order, which is also the order
// a View menu would offer them in.
$themeIds = ['win9x', 'win31', 'platinum', 'cde', 'beos', 'fluent', 'material'];

$registry->addListener(
    X11KeyPressEvent::class,
    static function (X11KeyPressEvent $e) use (
        $calc, $repaint, $keys, $themes, $themeIds, $tree, $client, $layout, $renderer, $frame,
        $display
    ): PromiseInterface {
        // The names are `Esc` and `Del`, not `Escape` and `Delete` — see
        // KeyTranslator::SPECIAL. Guessing gives a key that silently does nothing.
        $name = $keys->translate($e->keycode, $e->state);

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

            // A theme switch is three steps and no re-wiring: re-push the theme
            // through the widgets, re-derive the app's own layout because a theme
            // may have changed the border and caption sizes, then let the client
            // update what the *server* holds (background pixel, font).
            $tree->refreshTheme();
            // The view cached its line widths against the old font.
            $display->relayoutForFont();
            $layout($client->getWindowWidth(), $client->getWindowHeight());
            $client->setWindowShape($frame->shapeRects($renderer));
            $client->applyTheme();
            $client->redraw();

            return resolve(null);
        }

        $key = match ($name) {
            'Esc', 'Del' => 'C',
            null         => '',
            default      => $name,
        };

        if (preg_match('/^[0-9.+\-*\/=C]$/', $key) === 1) {
            $calc->press($key);
            $repaint();
        }

        return resolve(null);
    },
);

// Closing is the application's decision, not the toolkit's: the caption's close
// button only *asks*, by dispatching WindowCloseRequestedEvent, so that an app
// can prompt about unsaved work first. Answer it or the button does nothing —
// which is exactly what every example here did until it was pointed out.
$uiRegistry->addListener(
    WindowCloseRequestedEvent::class,
    static function (WindowCloseRequestedEvent $e) use ($client): void {
        $client->closeWindow();
    },
);

$registry->addListener(X11ExposeEvent::class,    new ExposeHandler($client, $logger));
$registry->addListener(X11ConfigureEvent::class, new ConfigureHandler($client, $logger));
$registry->addListener(
    X11ConfigureEvent::class,
    static function (X11ConfigureEvent $e) use ($client, $frame, $layout, $renderer): PromiseInterface {
        if ($e->windowId === $client->getWindowId()) {
            $layout($e->width, $e->height);
            $client->setWindowShape($frame->shapeRects($renderer));
            $client->redraw();
        }

        return resolve(null);
    },
);

$layout(240, 300);

$client->setWindowTitle('Calculator');
$client->setWindowSize(240, 300);
$client->setResizable(false);
$client->setDecorated(false);
$client->connect();

Loop::run();
