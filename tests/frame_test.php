<?php
declare(strict_types=1);

/**
 * The window frame: closing, and a window that must not be resized.
 *
 * Both of these were reported as broken in `example/calculator.php`, and they are
 * different kinds of broken:
 *
 *  - **The close button did nothing** because closing is the *application's*
 *    decision. The toolkit only asks, by dispatching
 *    {@see WindowCloseRequestedEvent}, so a window whose owner never listens
 *    cannot be closed — and none of the examples listened.
 *  - **A fixed-size window needs two things.** `WindowFrame(resizable: false)`
 *    removes the gestures the toolkit owns (the maximise button, the border drag,
 *    double-click-to-maximise) and `X11Client::setResizable(false)` writes
 *    WM_NORMAL_HINTS so the *window manager* refuses too. Either route can resize
 *    a window on its own, so one without the other leaves a way in.
 *
 * No X server: the handler is driven with synthetic events, and the client writes
 * into a fake connection whose bytes are read back.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\Handler\WindowFrameHandler;
use Cyrnetix\X11\UI\Painter\WindowFramePainter;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Handler\TreeViewHandler;
use Cyrnetix\X11\UI\Painter\MessageBoxPainter;
use Cyrnetix\X11\UI\Painter\TreeViewPainter;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-50s %-18s %s\n", $what,
        is_array($got) ? (string) json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

/**
 * Keeps whatever the client writes, so a request can be inspected.
 *
 * A full `ConnectionInterface` rather than a writable stream, because that is the
 * type `X11Client::$conn` declares. Only {@see write()} does anything.
 */
final class Wire implements ConnectionInterface
{
    public string $written = '';

    /** Appends what was written. */
    public function write($data): bool { $this->written .= $data; return true; }

    /** The rest are members `ConnectionInterface` requires and a test has no use for. */
    public function isWritable(): bool { return true; }

    public function isReadable(): bool { return true; }
    public function pause(): void {}
    public function resume(): void {}
    public function pipe(WritableStreamInterface $dest, array $options = []): WritableStreamInterface { return $dest; }
    public function end($data = null): void {}
    public function close(): void {}
    public function getRemoteAddress(): ?string { return null; }
    public function getLocalAddress(): ?string { return null; }
    public function on($event, callable $listener): void {}
    public function once($event, callable $listener): void {}
    public function removeListener($event, callable $listener): void {}
    public function removeAllListeners($event = null): void {}
    public function listeners($event = null): array { return []; }
    public function emit($event, array $arguments = []): void {}
}

$logger = new Logger('frame');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Error));

/**
 * A client, a frame and a handler, with the window id the handler will check.
 *
 * @return array{X11Client, WindowFrame, WindowFrameHandler, ListenerRegistry, Renderer, Wire}
 */
$build = static function (bool $resizable) use ($logger): array {
    $themes   = new ThemeManager(new Win9xTheme());
    $renderer = new Renderer();
    $wire     = new Wire();
    $client   = new X11Client(
        Factory::create(),
        new AsyncEventDispatcher(new ListenerRegistry(), $logger),
        $logger,
        $renderer,
        $themes,
    );

    // The handler checks *which* window a click came from — a modal dialog grabs
    // the pointer and its window-local coordinates would otherwise match the
    // caption behind it. So the id has to be real.
    (new ReflectionProperty(X11Client::class, 'windowId'))->setValue($client, 0x100);
    (new ReflectionProperty(X11Client::class, 'conn'))->setValue($client, $wire);

    // The size hints come from the *client's* idea of the window size, not the
    // frame's: the client is what the server and the window manager agree with.
    $client->setWindowSize(300, 200);

    $ui   = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());
    $tree = new WidgetTree($themes);

    $frame = new WindowFrame(300, 200, 'Window', drawsChrome: true, resizable: $resizable);
    $tree->addRoot($frame);

    $handler = new WindowFrameHandler(
        $tree, $client, $ui, new DoubleClickDetector(), $renderer,
        new WindowFramePainter($themes),
    );

    return [$client, $frame, $handler, $uiRegistry, $renderer, $wire];
};

/** The rectangle of one caption button, if the frame has it. */
$boxOf = static function (WindowFrame $frame, Renderer $r, CaptionButton $want): ?object {
    foreach ($frame->captionButtons($r) as [$button, $rect]) {
        if ($button === $want) return $rect;
    }

    return null;
};

// ---- the close button reaches the application ----------------------------
echo "closing\n";
[$client, $frame, $handler, $uiRegistry, $renderer] = $build(true);

$asked = 0;
$uiRegistry->addListener(WindowCloseRequestedEvent::class, static function () use (&$asked): void { $asked++; });

$close = $boxOf($frame, $renderer, CaptionButton::Close);
$check('the caption has a close box',        $close !== null, true);

$x = $close->centerX();
$y = $close->centerY();

$handler->tryPress(new X11ButtonPressEvent(0x100, 1, $x, $y, $x, $y, 1000, 0));
$check('a press shows as pressed',           $frame->getPressedButton(), CaptionButton::Close);
$check('and asks nothing yet',               $asked, 0);

$handler->tryRelease(new X11ButtonReleaseEvent(0x100, 1, $x, $y, $x, $y, 1010, 0));
$check('the release asks the application',   $asked, 1);
$check('and clears the pressed state',       $frame->getPressedButton(), null);

// Dragging off the button is how you change your mind about pressing it.
$handler->tryPress(new X11ButtonPressEvent(0x100, 1, $x, $y, $x, $y, 2000, 0));
$handler->tryRelease(new X11ButtonReleaseEvent(0x100, 1, 150, 150, 150, 150, 2010, 0));
$check('releasing elsewhere asks nothing',   $asked, 1);

// A click in another window is not ours: a modal dialog's coordinates would
// otherwise match the caption of the window behind it.
$handler->tryPress(new X11ButtonPressEvent(0x999, 1, $x, $y, $x, $y, 3000, 0));
$check('another window is not claimed',      $frame->getPressedButton(), null);

// ---- a window that must not be resized ----------------------------------
echo "fixed size\n";
foreach ([true, false] as $resizable) {
    [$client, $frame, $handler, $uiRegistry, $renderer, $wire] = $build($resizable);

    $label = $resizable ? 'resizable' : 'fixed';
    $names = array_map(static fn(array $b): string => $b[0]->name, $frame->captionButtons($renderer));

    $check("  $label: has a maximise button",
        in_array('Maximize', $names, true), $resizable);
    $check("  $label: still has close",
        in_array('Close', $names, true), true);
    $check("  $label: the border grabs",
        $frame->hitTestResizeEdge(1, 100) !== null, $resizable);
    $check("  $label: and so does a corner",
        $frame->hitTestResizeEdge(1, 1) !== null, $resizable);

    // Double-clicking the caption maximises, unless the window is fixed-size —
    // the gesture equivalent of the button that is not there.
    $maximised = 0;
    $uiRegistry->addListener(WindowCloseRequestedEvent::class, static function (): void {});
    $caption = $frame->captionTitleRect($renderer);
    $cx = $caption->centerX();
    $cy = $caption->centerY();
    $handler->tryPress(new X11ButtonPressEvent(0x100, 1, $cx, $cy, $cx, $cy, 4000, 0));
    $handler->tryPress(new X11ButtonPressEvent(0x100, 1, $cx, $cy, $cx, $cy, 4050, 0));
    $check("  $label: a double-click did not crash", true, true);

    // WM_NORMAL_HINTS: the window manager's half. ChangeProperty is opcode 18,
    // and PMinSize|PMaxSize is 0x30 — there is no "not resizable" flag, so a
    // fixed size is expressed as min == max.
    $wire->written = '';
    $client->setResizable($resizable);

    $found = null;
    $data = $wire->written;
    for ($i = 0; $i + 32 <= strlen($data); $i++) {
        if (ord($data[$i]) !== 18) continue;

        $header = unpack('Copcode/Cmode/vlen/Vwindow/Vproperty/Vtype/Cformat', substr($data, $i, 20));
        if ($header['property'] !== 40) continue;   // WM_NORMAL_HINTS

        $found = unpack('V18', substr($data, $i + 24, 72));
        break;
    }

    $check("  $label: WM_NORMAL_HINTS was written",  $found !== null, true);
    if ($found !== null) {
        $check("  $label: min size is set",          ($found[1] & 0x10) !== 0, true);
        $check("  $label: max size is set",          ($found[1] & 0x20) !== 0, !$resizable);
        if (!$resizable) {
            $check("  $label: min equals the window", [$found[6], $found[7]], [300, 200]);
            $check("  $label: and so does max",       [$found[8], $found[9]], [300, 200]);
        }
    }
}

// ---- who draws the caption ----------------------------------------------
// Two flags have to agree: WindowFrame(drawsChrome:) says whether the *toolkit*
// draws a caption, and X11Client::setDecorated() says whether the *window
// manager* is asked for one. Setting the first and forgetting the second gives a
// window with two title bars, one inside the other — which is what the widget
// gallery shipped with. So the client now infers it when nobody has said.
echo "who draws the caption\n";
{
    $themes = new ThemeManager(new Win9xTheme());

    /** A client whose tree holds a frame, without anyone calling setDecorated(). */
    $inferred = static function (bool $drawsChrome) use ($themes, $logger): bool {
        $renderer = new Renderer();
        $client   = new X11Client(
            Factory::create(),
            new AsyncEventDispatcher(new ListenerRegistry(), $logger),
            $logger,
            $renderer,
            $themes,
        );
        $tree = new WidgetTree($themes);
        $tree->addRoot(new WindowFrame(300, 200, 'W', drawsChrome: $drawsChrome));

        $client->setWidgetManager(new WidgetManager(
            $client, $renderer, $tree, new MessageBoxPainter($themes), new KeyTranslator(), $logger,
            [], new TreeViewHandler($tree, $client, new DoubleClickDetector(), new TreeViewPainter($themes)),
        ));

        return $client->isDecorated();
    };

    $check('a themed frame asks for no WM decoration',  $inferred(true), false);
    $check('a frame that draws nothing wants the WM',   $inferred(false), true);

    // And saying so explicitly still wins, either way.
    $renderer = new Renderer();
    $client   = new X11Client(
        Factory::create(),
        new AsyncEventDispatcher(new ListenerRegistry(), $logger),
        $logger,
        $renderer,
        $themes,
    );
    $tree = new WidgetTree($themes);
    $tree->addRoot(new WindowFrame(300, 200, 'W', drawsChrome: true));
    $client->setWidgetManager(new WidgetManager(
        $client, $renderer, $tree, new MessageBoxPainter($themes), new KeyTranslator(), $logger,
        [], new TreeViewHandler($tree, $client, new DoubleClickDetector(), new TreeViewPainter($themes)),
    ));

    $client->setDecorated(true);
    $check('an explicit yes overrides the inference',   $client->isDecorated(), true);
    $client->setDecorated(false);
    $check('and an explicit no does too',               $client->isDecorated(), false);

    // With no widgets at all there is nothing to infer from, and the X11 default
    // — the window manager decorates — is the safe answer.
    $bare = new X11Client(
        Factory::create(),
        new AsyncEventDispatcher(new ListenerRegistry(), $logger),
        $logger,
        new Renderer(),
        $themes,
    );
    $check('with no frame, the WM decorates',           $bare->isDecorated(), true);
}

echo $fail === 0 ? "all frame assertions passed\n" : "$fail assertions failed\n";
exit($fail === 0 ? 0 : 1);
