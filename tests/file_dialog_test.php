<?php
declare(strict_types=1);

/**
 * The file dialog's three ways out: Cancel, the caption's close box, and Esc.
 *
 * All three were reported broken together, and they were one bug plus one
 * omission:
 *
 *  - **Cancel left a grey box over the application.** The dialog's own Cancel
 *    button called {@see FileDialog::finish()}, which hides the *widget* — the
 *    only code that can unmap the window, drop the pointer grab and clear tree
 *    modality is {@see FileDialogHandler::dismiss()}, and nothing routed back to
 *    it. So the window stayed mapped with nothing painted on it, the grab stayed
 *    held by a window with no reachable widgets, and `setModal()` still pointed
 *    at a hidden dialog — which is why the application behind it looked
 *    interactive-but-broken afterwards. Closing now goes through
 *    {@see FileDialog::requestDismiss()}.
 *  - **The close box did nothing.** `setOnCaptionClose()` was wired all along,
 *    but the handler tested {@see FileDialog::hitTestCaption()} first, so a press
 *    on the box started a *window drag* instead. Buttons are tested before the
 *    drag now, the way `FormWindowHandler` has always done it.
 *  - **Esc did nothing** because the handler matched `'Escape'`, and
 *    {@see KeyTranslator::SPECIAL} emits `'Esc'`. `'BackSpace'` was wrong the
 *    same way.
 *
 * No X server: the handler is driven with synthetic events and the client writes
 * into a fake connection, so the UnmapWindow and UngrabPointer requests can be
 * read back as bytes.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Filesystem\DirectoryLister;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\Fluent\FluentTheme;
use Cyrnetix\X11\Theme\Material\MaterialTheme;
use Cyrnetix\X11\Theme\Theme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\FileDialogClosedEvent;
use Cyrnetix\X11\UI\Handler\FileDialogHandler;
use Cyrnetix\X11\UI\Painter\FileDialogPainter;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\FileDialog;
use Cyrnetix\X11\UI\Widget\FileDialogMode;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Socket\ConnectionInterface;
use React\Stream\WritableStreamInterface;

$fail  = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-52s %-16s %s\n", $what,
        is_array($got) ? (string) json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

/** Keeps whatever the client writes, so a request can be looked for. */
final class DialogWire implements ConnectionInterface
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

$logger = new Logger('file-dialog');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Error));

const DIALOG_WINDOW = 0x400;

/**
 * A dialog, its handler, and a wire to read the requests off.
 *
 * The results are collected into an object rather than an array: a `list()`
 * assignment copies, so a plain array would be captured empty and never see
 * what the listener appended to it afterwards.
 *
 * @return array{FileDialog, FileDialogHandler, WidgetTree, Renderer, DialogWire, object}
 */
$build = static function (Theme $theme) use ($logger): array {
    $themes   = new ThemeManager($theme);
    $renderer = new Renderer();
    $wire     = new DialogWire();
    $client   = new X11Client(
        Factory::create(),
        new AsyncEventDispatcher(new ListenerRegistry(), $logger),
        $logger,
        $renderer,
        $themes,
    );

    (new ReflectionProperty(X11Client::class, 'conn'))->setValue($client, $wire);
    (new ReflectionProperty(X11Client::class, 'fileDialogWindowId'))->setValue($client, DIALOG_WINDOW);

    $ui   = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());
    $tree = new WidgetTree($themes);

    $dialog = new FileDialog(560, 400, $ui, renderer: $renderer);
    $tree->addRoot($dialog);

    $handler = new FileDialogHandler(
        $tree, $client, new DirectoryLister(), $renderer, $logger,
        new FileDialogPainter($themes), new DoubleClickDetector(),
    );

    $closed = new class {
        /** @var list<FileDialogClosedEvent> */
        public array $events = [];
    };
    $uiRegistry->addListener(FileDialogClosedEvent::class,
        static function (FileDialogClosedEvent $e) use ($closed): void { $closed->events[] = $e; });

    // show() reads the screen and window geometry off the client and sizes the
    // dialog from it, exactly as an application's open does.
    $dialog->open(FileDialogMode::OpenFile, __DIR__, []);
    $handler->show($dialog);

    return [$dialog, $handler, $tree, $renderer, $wire, $closed];
};

/** The rectangle of one caption button, if this theme's dialog has it. */
$boxOf = static function (FileDialog $dialog, Renderer $r, CaptionButton $want): ?object {
    foreach ($dialog->captionButtons($r) as [$button, $rect]) {
        if ($button === $want) return $rect;
    }

    return null;
};

/** UnmapWindow (opcode 10) for the dialog's window, as bytes on the wire. */
$unmap  = pack('CCvV', 10, 0, 2, DIALOG_WINDOW);
/** UngrabPointer (opcode 27), CurrentTime. */
$ungrab = pack('CCvV', 27, 0, 2, 0);
/** ConfigureWindow (opcode 12) moving the dialog: value-mask x | y. */
$move   = pack('CCvVvv', 12, 0, 5, DIALOG_WINDOW, 0x0003, 0);

// -------------------------------------------------------------------------
// Cancel closes the window, not just the widget
// -------------------------------------------------------------------------
echo "cancel\n";
[$dialog, $handler, $tree, $renderer, $wire, $closed] = $build(new FluentTheme());

$check('the dialog opens visible',            $dialog->isVisible(), true);
$check('and takes tree modality',             $tree->getModal() === $dialog, true);

// release(true) is the path a real click takes: ButtonHandler captures the
// press and fires the click from the release.
$wire->written = '';
$dialog->getCancelButton()->release(true);

$check('cancel hides the widget',             $dialog->isVisible(), false);
$check('and reports the result',              count($closed->events), 1);
$check('with no path, meaning cancelled',     $closed->events[0]->path, null);
$check('it releases tree modality',           $tree->getModal(), null);
$check('it unmaps the window',                str_contains($wire->written, $unmap), true);
$check('and ungrabs the pointer',             str_contains($wire->written, $ungrab), true);

// -------------------------------------------------------------------------
// The caption's close box means the same thing
// -------------------------------------------------------------------------
echo "the caption's close box\n";
foreach ([new Win9xTheme(), new FluentTheme(), new MaterialTheme()] as $theme) {
    [$dialog, $handler, $tree, $renderer, $wire, $closed] = $build($theme);

    $id  = $theme->id();
    $box = $boxOf($dialog, $renderer, CaptionButton::Close);
    $check("  $id: the caption has a close box", $box !== null, true);
    if ($box === null) continue;

    $x = $box->centerX();
    $y = $box->centerY();

    $wire->written = '';
    $handler->tryPress(new X11ButtonPressEvent(DIALOG_WINDOW, 1, $x, $y, $x, $y, 1000, 0));

    $check("  $id: the press shows as pressed",  $dialog->getPressedButton(), CaptionButton::Close);
    $check("  $id: and closes nothing yet",      $dialog->isVisible(), true);
    // The press must not also start a window drag, which is what testing the
    // caption before its buttons did — and it only showed on the motion after.
    $handler->tryMotion(new X11MotionEvent(DIALOG_WINDOW, 900, 900, $x + 20, $y + 20, 1005, 0));
    $check("  $id: and starts no window drag", str_contains($wire->written, $move), false);

    $handler->tryRelease(new X11ButtonReleaseEvent(DIALOG_WINDOW, 1, $x, $y, $x, $y, 1010, 0));

    $check("  $id: the release closes it",       $dialog->isVisible(), false);
    $check("  $id: clearing the pressed state",  $dialog->getPressedButton(), null);
    $check("  $id: it unmaps the window",        str_contains($wire->written, $unmap), true);
    $check("  $id: and ungrabs the pointer",     str_contains($wire->written, $ungrab), true);
    $check("  $id: reported as cancelled",
        count($closed->events) === 1 && $closed->events[0]->path === null, true);

    // Dragging off the box is how you change your mind about pressing it.
    [$dialog, $handler, $tree, $renderer, $wire, $closed] = $build($theme);
    $handler->tryPress(new X11ButtonPressEvent(DIALOG_WINDOW, 1, $x, $y, $x, $y, 2000, 0));
    $handler->tryRelease(new X11ButtonReleaseEvent(DIALOG_WINDOW, 1, 280, 300, 280, 300, 2010, 0));
    $check("  $id: releasing elsewhere keeps it", $dialog->isVisible(), true);
}

// A theme whose era gave a dialog no close box must not grow one, and its
// window-menu box is the way out instead — by double-click, as 3.1 did it.
echo "windows 3.1 has no close box\n";
[$dialog, $handler, $tree, $renderer, $wire, $closed] = $build(new Win31Theme());

$check('no close box',                        $boxOf($dialog, $renderer, CaptionButton::Close), null);
$menu = $boxOf($dialog, $renderer, CaptionButton::Menu);
$check('but a window-menu box',               $menu !== null, true);

if ($menu !== null) {
    $mx = $menu->centerX();
    $my = $menu->centerY();

    $handler->tryPress(new X11ButtonPressEvent(DIALOG_WINDOW, 1, $mx, $my, $mx, $my, 3000, 0));
    $handler->tryRelease(new X11ButtonReleaseEvent(DIALOG_WINDOW, 1, $mx, $my, $mx, $my, 3010, 0));
    $check('one click does not close it',     $dialog->isVisible(), true);

    $wire->written = '';
    $handler->tryPress(new X11ButtonPressEvent(DIALOG_WINDOW, 1, $mx, $my, $mx, $my, 3100, 0));
    $check('a double click does',             $dialog->isVisible(), false);
    $check('and unmaps the window',           str_contains($wire->written, $unmap), true);
}

// -------------------------------------------------------------------------
// The keys the translator actually emits
// -------------------------------------------------------------------------
echo "keys\n";
[$dialog, $handler, $tree, $renderer, $wire, $closed] = $build(new FluentTheme());

$check("'Escape' is not a key name",          $handler->tryKey('Escape'), false);
$check('and leaves it open',                  $dialog->isVisible(), true);

$wire->written = '';
$check("'Esc' is",                            $handler->tryKey('Esc'), true);
$check('and closes it',                       $dialog->isVisible(), false);
$check('unmapping the window',                str_contains($wire->written, $unmap), true);
$check('and ungrabbing the pointer',          str_contains($wire->written, $ungrab), true);

// Every name this handler answers to has to be one the translator produces.
// This is the check that would have caught both wrong names, and it is worth
// more than the two above: it fails for a name nobody has thought to try yet.
$special = (new ReflectionClass(\Cyrnetix\X11\UI\KeyTranslator::class))->getConstant('SPECIAL');
$source  = file_get_contents(dirname(__DIR__) . '/src/UI/Handler/FileDialogHandler.php');

preg_match('/function tryKey\(.*?\n    \}/s', $source, $body);
$check('tryKey was found to read',            $body !== [], true);
preg_match_all("/case '([A-Za-z]+)':/", $body[0] ?? '', $matches);

$check('and it answers to some keys',         count($matches[1]) > 0, true);
$unknown = array_values(array_diff(array_unique($matches[1]), array_values($special)));
$check('every key it answers to is emitted',  $unknown, []);

// -------------------------------------------------------------------------
// Accept still works, and it was never the broken path
// -------------------------------------------------------------------------
echo "accept\n";
[$dialog, $handler, $tree, $renderer, $wire, $closed] = $build(new FluentTheme());

$wire->written = '';
$handler->tryPress(new X11ButtonPressEvent(DIALOG_WINDOW, 1, -40, -40, 0, 0, 4000, 0));
$check('a press outside is swallowed',        $dialog->isVisible(), true);
$check('and touches no window',               $wire->written, '');

printf("\nfile dialog: %s\n", $fail === 0 ? 'all checks passed' : "$fail check(s) FAILED");
exit($fail === 0 ? 0 : 1);
