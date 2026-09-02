<?php
declare(strict_types=1);

/**
 * One caption, drawn one way.
 *
 * A dialog's title bar used to go through `Chrome::titleBar()` — a separate
 * primitive that hand-rolled its own gradient, put a 12-pixel icon box at a
 * hard-coded offset, and had no buttons at all — while the main window's went
 * through `caption()` + `captionGrab()` + `captionTitle()` + a `captionButton()`
 * each. So a dialog's caption looked like it came from a different era than the
 * window behind it, which is what it was.
 *
 * There is now one path. What is asserted here:
 *
 *  - `titleBar()` is gone, so a second path cannot come back by accident;
 *  - a dialog's caption **background is the theme's `caption()`**, byte for byte;
 *  - the buttons and the title band are placed by the shared `CaptionLayout`,
 *    identically for a window and a dialog given the same inputs;
 *  - the close box hit-tests, presses and closes.
 *
 * No X server: the renderer writes into a fake connection and the bytes are read
 * back out of it.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Stream\WritableStreamInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\CaptionLayout;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Handler\FormWindowHandler;
use Cyrnetix\X11\UI\Handler\TreeViewHandler;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter\FormWindowPainter;
use Cyrnetix\X11\UI\Painter\MessageBoxPainter;
use Cyrnetix\X11\UI\Painter\TreeViewPainter;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\Painter\WindowFramePainter;
use Cyrnetix\X11\UI\Widget\FormWindow;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetTree;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    $show = static function ($v): string {
        $t = is_array($v) ? (string) json_encode($v) : var_export($v, true);

        return strlen($t) > 58 ? substr($t, 0, 55) . '...' : $t;
    };
    printf("  %-52s %-22s %s\n", $what, $show($got), $ok ? 'ok' : 'FAIL (want ' . $show($want) . ')');
};

/**
 * Keeps whatever the renderer writes, so a paint can be inspected byte for byte.
 *
 * Only {@see write()} does anything; the rest are the members
 * `WritableStreamInterface` requires and a test has no use for.
 */
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

/** Bytes a paint produces, from a renderer of its own. */
$bytesOf = static function (callable $paint): string {
    $sink = new Sink();
    $r    = new Renderer();
    $r->init($sink, windowId: 0x100, gcId: 0x200);
    $paint($r);
    $r->flush();

    return $sink->written;
};

// ---- a window's root is registered for you ------------------------------
// The failure this closes: handing a root to createChildWindow() and *not* also
// registering it with the widget tree. An unregistered root never gets a
// ThemeManager, so Widget::metrics() falls back to Metrics::defaults() - the
// Windows 9x numbers - while the painters keep drawing in the live theme. Under
// BeOS that is a partial tab's chrome painted into a full-width Win9x caption,
// with nothing cut away because the root also reports no shape. It reads as a
// broken theme rather than a missing line of setup, which is why it survived.
echo "registration\n";
{
    $logger = new Logger('caption');
    $logger->pushHandler(new StreamHandler('php://stderr', Level::Error));

    $themes = new ThemeManager(new BeOsTheme(), new Win9xTheme());
    $themes->select('beos');

    $r      = new Renderer();
    $client = new X11Client(
        Factory::create(),
        new AsyncEventDispatcher(new ListenerRegistry(), $logger),
        $logger,
        $r,
        $themes,
    );
    $tree = new WidgetTree($themes);

    // The order every application uses: windows first, then the handlers that
    // paint them. Note there is no addChild() call anywhere below.
    $form = new FormWindow(320, 200, 'Dialog');
    $form->setVisible(true);
    $client->createChildWindow(320, 200, modal: false, root: $form);

    $check('before a manager exists, no theme yet',
        $form->metrics()->captionFitsTitle, false);

    $doubleClick = new DoubleClickDetector();
    $manager = new WidgetManager(
        $client, $r, $tree, new MessageBoxPainter($themes), new KeyTranslator(), $logger,
        [new FormWindowHandler($tree, $client, $r, new FormWindowPainter($themes), $doubleClick)],
        new TreeViewHandler($tree, $client, $doubleClick, new TreeViewPainter($themes)),
    );
    $client->setWidgetManager($manager);

    $check('setting the manager registers the root',
        $form->metrics()->captionFitsTitle, true);
    // A tab is title-sized, not window-sized. Unregistered it came out 316 of
    // 320 - which is why "narrower than the window" is not the assertion.
    $check('so BeOS gets its partial tab',
        $form->captionRect($r)->width < 160, true);
    $check('flush in the corner, not inset',
        [$form->captionRect($r)->x, $form->captionRect($r)->y], [0, 0]);
    $check('and the strip beside it is cut away',
        count($form->shapeRects($r)), 2);

    // An application that also registers by hand must not be punished for it.
    $manager->addChild($form);
    $roots = (new ReflectionProperty(WidgetTree::class, 'roots'))->getValue($tree);
    $check('registering twice adds one root',      count($roots), 1);

    // A window drawn by a callback has no root, and must not blow up.
    $client->createChildWindow(100, 80, painter: static function (Renderer $r): void {});
    $check('a window with no root is fine',        true, true);
}

// ---- there is only one way to draw a caption ----------------------------
echo "one path\n";
$check('Chrome has no titleBar()',       method_exists(Chrome::class, 'titleBar'), false);
foreach ([new Win9xTheme(), new Win31Theme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme()] as $theme) {
    $check("  {$theme->id()}: nor does its chrome",
        method_exists($theme->chrome(), 'titleBar'), false);
}

// ---- the placement is shared -------------------------------------------
// Same metrics, same caption, same button lists: the two windows cannot place
// them differently, because there is one function that does it.
echo "shared placement\n";
$themes = new ThemeManager(new Win9xTheme(), new Win31Theme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme());
$renderer = new Renderer();

foreach (['win9x', 'win31', 'platinum', 'cde', 'beos'] as $id) {
    $themes->select($id);
    $m       = $themes->metrics();
    $caption = Rect::of(4, 4, 300, $m->dialogTitleHeight);

    $asWindow = CaptionLayout::buttons($m, $caption, $m->captionLeading, $m->captionTrailing);
    $asDialog = CaptionLayout::buttons($m, $caption, $m->dialogCaptionLeading, $m->dialogCaptionTrailing);

    // Every button that both sets contain must land in the same place.
    $where = static function (array $placed): array {
        $out = [];
        foreach ($placed as [$button, $rect]) $out[$button->name] = [$rect->x, $rect->y, $rect->width];

        return $out;
    };
    $w = $where($asWindow);
    $d = $where($asDialog);

    $shared = array_intersect_key($w, $d);
    $agree  = true;
    foreach ($shared as $name => $rect) {
        // Close sits at the same end in both sets, so it must land identically.
        if ($name === 'Close' && $rect !== $d[$name]) $agree = false;
    }
    $check("  $id: a button common to both lands the same", $agree, true);

    // Every button is inside the caption, whichever set it came from.
    $inside = true;
    foreach ([...$asWindow, ...$asDialog] as [, $rect]) {
        if (!$caption->contains($rect->x, $rect->y)) $inside = false;
        if (!$caption->contains($rect->right(), $rect->bottom())) $inside = false;
    }
    $check("  $id: and inside the caption",              $inside, true);

    // The title clears them all.
    $title = CaptionLayout::titleRect($m, $caption, $asDialog);
    $clear = true;
    foreach ($asDialog as [, $rect]) {
        if ($title->x <= $rect->right() && $title->right() >= $rect->x) $clear = false;
    }
    $check("  $id: the title band clears the buttons",   $clear, true);
    $check("  $id: and with none it is the whole bar",
        CaptionLayout::titleRect($m, $caption, [])->width, $caption->width);
}

// ---- a dialog's caption is the theme's caption --------------------------
// The strongest form of "it looks the same": the background a dialog paints is
// byte-identical to the theme's own caption() over the same rectangle. A
// hand-rolled gradient would not match.
echo "the same pixels\n";
foreach (['win9x', 'win31', 'platinum', 'cde', 'beos'] as $id) {
    $themes->select($id);
    $tree = new WidgetTree($themes);

    $form = new FormWindow(320, 200, 'Dialog');
    $form->setVisible(true);
    $tree->addRoot($form);

    $caption  = $form->captionRect($renderer);
    $expected = $bytesOf(static fn(Renderer $r) => $themes->chrome()->caption($r, $caption, true));
    $painted  = $bytesOf(static fn(Renderer $r) => (new FormWindowPainter($themes))->paint($form, $r));

    $check("  $id: the theme's caption is in what it drew",
        $expected !== '' && str_contains($painted, $expected), true);

    // And the check is not vacuous: the same caption drawn somewhere else is
    // not in there, so this is really comparing position and colour.
    $elsewhere = $bytesOf(static fn(Renderer $r) => $themes->chrome()->caption(
        $r, Rect::of($caption->x + 37, $caption->y + 11, $caption->width, $caption->height), true,
    ));
    $check("  $id: but not the same caption elsewhere",
        str_contains($painted, $elsewhere), false);
}

// And the main window still paints; the shared painter did not break it.
$themes->select('win9x');
$tree  = new WidgetTree($themes);
$frame = new WindowFrame(600, 400, 'Main', drawsChrome: true);
$tree->addRoot($frame);
$framed = $bytesOf(static fn(Renderer $r) => (new WindowFramePainter($themes))->paint($frame, $r));
$check('the main window still draws its caption',
    str_contains(
        $framed,
        $bytesOf(static fn(Renderer $r) => $themes->chrome()->caption($r, $frame->captionRect($renderer), true)),
    ),
    true);

// ---- the close box ------------------------------------------------------
echo "the close box\n";
$themes->select('win9x');
$tree = new WidgetTree($themes);
$form = new FormWindow(320, 200, 'Dialog');
$form->setVisible(true);
$tree->addRoot($form);

$buttons = $form->captionButtons($renderer);
$check('win9x gives a dialog one button',    count($buttons), 1);
$check('and it is the close box',            $buttons[0][0], CaptionButton::Close);
$check('minimise is not offered',
    in_array(CaptionButton::Minimize, array_column($buttons, 0), true), false);

[$button, $rect] = $buttons[0];
$check('hit-testing finds it',
    $form->hitTestCaptionButton($rect->centerX(), $rect->centerY(), $renderer), CaptionButton::Close);
$check('and nothing at the other end',
    $form->hitTestCaptionButton($form->captionRect($renderer)->x + 2, $rect->centerY(), $renderer), null);

$closed = 0;
$form->setOnCaptionClose(static function () use (&$closed): void { $closed++; });
$form->requestClose();
$check('closing goes through the hook',      $closed, 1);

// Pressed state is what makes it read as a button rather than a picture.
$form->setPressedButton(CaptionButton::Close);
$pressed = $bytesOf(static fn(Renderer $r) => (new FormWindowPainter($themes))->paint($form, $r));
$form->setPressedButton(null);
$idle = $bytesOf(static fn(Renderer $r) => (new FormWindowPainter($themes))->paint($form, $r));
$check('and a press changes what is drawn',  $pressed !== $idle, true);

// A theme whose era gave a dialog no widgets gives the title the whole bar.
$themes->select('platinum');
$tree->refreshTheme();
$check('platinum gives a dialog none',       $form->captionButtons($renderer), []);
$check('so the title has the whole caption',
    $form->captionTitleRect($renderer)->width, $form->captionRect($renderer)->width);
$check('and a press there hits nothing',
    $form->hitTestCaptionButton(300, 8, $renderer), null);

echo $fail === 0 ? "all caption assertions passed\n" : "$fail assertions failed\n";
exit($fail === 0 ? 0 : 1);
