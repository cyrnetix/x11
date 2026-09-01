<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Client;

use Closure;

use Psr\Log\LoggerInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Drawing\FontMetrics;
use Cyrnetix\X11\Drawing\GcState;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11SelectionClearEvent;
use Cyrnetix\X11\Event\X11SelectionNotifyEvent;
use Cyrnetix\X11\Event\X11SelectionRequestEvent;
use Cyrnetix\X11\Protocol\EventParser;
use Cyrnetix\X11\Protocol\SetupParser;
use Cyrnetix\X11\Theme\Palette;
use Cyrnetix\X11\Theme\ThemeManager;
use function React\Promise\resolve;
use Cyrnetix\X11\UI\Widget\MessageBox;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\Widget\ResizeEdge;
use Cyrnetix\X11\UI\WidgetManager;

/**
 * The X11 connection, and everything that speaks the wire protocol.
 *
 * There is no Xlib here. This opens a Unix-domain socket, writes the connection
 * setup record by hand and stages itself through `setup` → `setup-complete` →
 * `running`; every request after that is a `pack()` call with its opcode named in
 * a comment. It owns the window, the graphics context, the font, the atoms, the
 * clipboard selections, the cursors, the SHAPE and ARGB plumbing, and the child
 * windows an application asks for — so all the opcodes stay in one file and
 * nothing above this layer knows it is talking to a socket.
 *
 * **Replies arrive in request order**, because X11 processes requests serially,
 * and are matched by a FIFO of handlers. Any request that produces a reply must
 * register one at the moment its bytes are written, even a no-op, or every later
 * reply is mis-paired.
 */
final class X11Client
{
    private ?ConnectionInterface $conn = null;
    private string $buffer             = '';
    private string $stage              = 'setup';

    private int $root      = 0;
    private int $ridBase   = 0;
    private int $windowId       = 0;
    private int $fontId         = 0;
    private int $boldFontId     = 0;
    private int $gcId           = 0;
    private int $cursorFontId   = 0;
    private int $busyCursorId   = 0;
    private int $mouseX    = 0;
    private int $mouseY    = 0;
    private string $lastKey   = '';
    private int $lastMods     = 0;
    private int $windowX      = 100;
    private int $windowY      = 100;
    private int $windowWidth  = 360;
    private int $windowHeight = 280;
    private bool $focused     = false;

    private ?WidgetManager $widgetManager  = null;
    private int             $dialogWindowId = 0;
    private bool            $dialogMapped   = false;

    /**
     * Application-created top-level windows, in creation order.
     *
     * @var list<ChildWindow>
     */
    private array $childWindows = [];

    /**
     * Next free X11 resource id, allocated from ridBase upwards.
     *
     * The fixed ids below are historical: the main window, fonts, GC, cursors
     * and colormap were hand-numbered before there was any need for more than a
     * couple of windows. Everything from here on is allocated, so an application
     * can create as many windows as it likes without picking numbers.
     */
    private int $nextResourceId = 16;

    /**
     * What the shared GC holds, so the renderers can skip redundant ChangeGCs
     * without disagreeing with each other. @see \Cyrnetix\X11\Drawing\GcState
     */
    private readonly GcState $gcState;

    /** Screen extent from the setup record — what a dialog is sized to fit on. */
    private int             $screenWidth  = 0;
    private int             $screenHeight = 0;

    private int             $fileDialogWindowId = 0;
    private ?Renderer       $fileDialogRenderer = null;
    private bool            $fileDialogMapped   = false;
    private int             $fileDialogX        = 0;
    private int             $fileDialogY        = 0;
    private int             $fileDialogWidth    = 1;
    private int             $fileDialogHeight   = 1;

    /** 32-bit ARGB visual + its colormap, both 0 when the server has none. */
    private int             $argbVisual  = 0;
    private int             $colormapId  = 0;
    private ?Renderer       $dialogRenderer = null;
    private ?FontMetrics    $fontMetrics    = null;

    /**
     * FIFO of reply handlers. X11 processes requests serially, so replies
     * arrive in the order their requests were sent. Every request that
     * generates a reply registers a handler here; the first reply pops the
     * front of this queue.
     *
     * @var list<\Closure(string $raw): void>
     */
    private array $replyHandlers = [];

    /** Interned atom IDs (CLIPBOARD, UTF8_STRING, TARGETS, ...). */
    private array $atoms = [];

    /**
     * Text we currently own on each X11 selection, keyed by the selection
     * atom (CLIPBOARD, PRIMARY, …). A SelectionClear event for a given
     * atom drops its entry.
     *
     * @var array<int, string>
     */
    private array $ownedSelections = [];

    /** Callback waiting for ConvertSelection's SelectionNotify → GetProperty. */
    private ?\Closure $pendingPasteCallback = null;

    /** Predefined atoms from the X11 spec — no InternAtom round trip needed. */
    public const ATOM_PRIMARY  = 1;
    private const ATOM_CARDINAL = 6;
    private const ATOM_STRING   = 31;
    private const ATOM_WM_NAME  = 39;
    /** WM_NORMAL_HINTS and its type, WM_SIZE_HINTS. Predefined, so no round trip. */
    private const ATOM_WM_NORMAL_HINTS = 40;
    /**
     * The smallest a resizable window may be dragged.
     *
     * Not zero: a window narrower than its own caption buttons cannot be closed
     * again, and a window manager will happily let you get there.
     */
    private const MIN_WINDOW_WIDTH  = 160;
    private const MIN_WINDOW_HEIGHT = 80;
    private const ATOM_WM_SIZE_HINTS   = 41;

    /** Title shown by the window manager (and by our own caption, if drawn). */
    private string $windowTitle = 'PHP X11';

    /**
     * Major opcode of the SHAPE extension, or 0 when the server doesn't have it.
     * Learned once at setup via QueryExtension.
     */
    private int $shapeOpcode = 0;

    /** Baseline origin of the debug overlay text. */
    private int  $overlayX       = 10;
    private int  $overlayY       = 40;
    private bool $overlayVisible = false;

    /**
     * false = ask the window manager not to draw a frame, so the toolkit can draw
     * a themed caption itself. Must be set before {@see connect()}; the hint is
     * only read when the window is mapped.
     *
     * **Null means the application has not said**, and then it is inferred at map
     * time from whether a {@see \Cyrnetix\X11\UI\Widget\WindowFrame} root
     * draws its own chrome. Getting these two out of step gives a window with two
     * title bars, one inside the other — which is what the widget gallery shipped
     * with, having set the frame's flag and forgotten this one.
     */
    private ?bool $decorated = null;

    /** Whether the window manager may resize the window. {@see setResizable()}. */
    private bool $resizable = true;

    private readonly SetupParser $setupParser;
    private readonly EventParser $eventParser;

    /** Window depth + visual, kept so a theme switch can reopen its font. */
    private int $depth  = 0;
    private int $visual = 0;

    /** Font names currently open on $fontId / $boldFontId. */
    private string $fontName     = '';
    private string $boldFontName = '';

    /** Takes the event loop, the event dispatcher, a logger, a renderer and the theme manager. */
    public function __construct(
        private readonly LoopInterface $loop,
        private readonly AsyncEventDispatcher $dispatcher,
        private readonly LoggerInterface $logger,
        private readonly Renderer $renderer,
        private readonly ThemeManager $themes,
    ) {
        $this->setupParser = new SetupParser();
        $this->eventParser = new EventParser();
        $this->gcState     = new GcState();
    }

    /**
     * Re-apply the active theme to everything the *server* holds: both windows'
     * background pixel and the GC's font. Widget relayout and repainting are the
     * caller's job (see {@see \Cyrnetix\X11\UI\WidgetTree::refreshTheme()}).
     */
    public function applyTheme(): void
    {
        if ($this->conn === null) return;

        $background = $this->windowBackgroundPixel();
        $windows    = [$this->windowId, $this->dialogWindowId, $this->fileDialogWindowId];
        foreach ($this->childWindows as $child) {
            $windows[] = $child->id();
        }
        foreach ($windows as $window) {
            if ($window === 0) continue;
            // ChangeWindowAttributes opcode 2, value-mask 0x0002 = background-pixel.
            $this->conn->write(pack('CCvVVV', 2, 0, 4, $window, 0x0002, $background));
        }

        $this->switchFont();
        $this->redraw();
        $this->redrawDialog();
    }

    /**
     * Probe the theme's font candidates and, if the winner differs from what's
     * open, swap it under the same font id: CloseFont, OpenFont, point the GC at
     * it, then re-read its metrics.
     */
    private function switchFont(): void
    {
        $this->probeFont($this->themes->current()->fontCandidates(), function (?string $chosen): void {
            $chosen ??= self::FALLBACK_FONT;
            if ($chosen === $this->fontName) return;

            // CloseFont (opcode 46) first — the id is still allocated, and X11
            // rejects reusing a live resource id.
            $this->conn->write(pack('CCvV', 46, 0, 2, $this->fontId));
            $this->openFont($chosen);

            // ChangeGC: value-mask 0x4000 = font.
            $this->conn->write(pack('CCvVVV', 56, 0, 4, $this->gcId, 0x4000, $this->fontId));

            $this->queryFont(repaintWhenLoaded: true);
            $this->logger->info('Font switched', ['font' => $chosen]);
        });

        // The bold face belongs to the theme too.
        $this->probeBoldFont();
    }

    /** The renderer. */
    public function getRenderer(): Renderer
    {
        return $this->renderer;
    }

    /**
     * Window title. Applied immediately when the connection is already up, so
     * it can be changed at runtime.
     */
    public function setWindowTitle(string $title): void
    {
        $this->windowTitle = $title;
        if ($this->conn !== null && $this->windowId !== 0) {
            $this->applyWindowTitle();
        }
    }

    /** The window title. */
    public function getWindowTitle(): string { return $this->windowTitle; }

    /**
     * Cut the window down to the given rectangles, so anything outside them shows
     * whatever is behind the window instead — real transparency, not a painted
     * imitation. That's how a BeOS-style tab gets clear space beside it.
     *
     * Clicks in the removed area fall through to the window underneath. Pass an
     * empty list to go back to a plain rectangle.
     *
     * A no-op when the server has no SHAPE extension. What the user sees there
     * depends on the visual: an ARGB window has already painted the hole
     * transparent, and an opaque one falls back to the theme's painted
     * stand-in (see {@see \Cyrnetix\X11\Theme\Chrome::captionSurround()}), which
     * is why that still paints.
     *
     * @param list<array{int, int, int, int}> $rects [x, y, width, height], window-relative
     */
    public function setWindowShape(array $rects): void
    {
        $this->applyShape($this->windowId, $rects, $this->windowWidth, $this->windowHeight);
    }

    /**
     * The same treatment for the dialog window, which is a separate X11 window
     * and needs its own shape — otherwise a tab-style dialog keeps a painted
     * strip beside its caption while the main window shows through.
     *
     * @param list<array{int, int, int, int}> $rects
     */
    public function setDialogShape(array $rects): void
    {
        $this->applyShape($this->dialogWindowId, $rects, MessageBox::WIDTH, MessageBox::HEIGHT);
    }

    /**
     * @param list<array{int, int, int, int}> $rects Empty resets to a plain rectangle.
     */
    private function applyShape(int $window, array $rects, int $resetWidth, int $resetHeight): void
    {
        if ($this->conn === null || $window === 0 || $this->shapeOpcode === 0) return;

        if ($rects === []) {
            // Reset: one rectangle covering the whole window.
            $rects = [[0, 0, $resetWidth, $resetHeight]];
        }

        $body = '';
        foreach ($rects as [$x, $y, $width, $height]) {
            if ($width <= 0 || $height <= 0) continue;
            $body .= pack('ssvv', $x, $y, $width, $height);
        }
        if ($body === '') return;

        // ShapeRectangles: op = Set(0), ordering = UnSorted(0).
        //
        // Which *kind* of shape depends on how the hole is made. On an ARGB
        // window the pixels are painted transparent, so clipping would be
        // actively harmful: the server would refuse the very drawing that makes
        // the hole, leaving the compositor to show undefined content. There we
        // shape input only (kind 2) — clicks still fall through to whatever is
        // behind, which is the other half of what a cut-out means. Without an
        // alpha channel the bounding shape (kind 0) is the only way to get a
        // hole at all, and it governs input as well.
        $kind = $this->argbVisual !== 0 ? 2 : 0;

        $this->conn->write(
            pack(
                'CCvCCCCVss',
                $this->shapeOpcode, 1, 4 + intdiv(strlen($body), 4),
                0, $kind, 0, 0,
                $window,
                0, 0,
            ) . $body,
        );
    }

    /** Whether it has a shape extension. */
    public function hasShapeExtension(): bool
    {
        return $this->shapeOpcode !== 0;
    }

    /**
     * QueryExtension (opcode 98) for SHAPE. The reply carries the major opcode
     * every extension request has to be prefixed with.
     */
    private function queryShapeExtension(): void
    {
        $name = 'SHAPE';
        $n    = strlen($name);
        $pad  = (4 - ($n % 4)) % 4;
        $len  = 2 + intdiv($n + $pad, 4);

        $this->expectReply(function (string $raw): void {
            // Reply: present (BOOL) at byte 8, major-opcode at byte 9.
            $present = ord($raw[8]);
            $major   = ord($raw[9]);

            $this->shapeOpcode = $present === 1 ? $major : 0;
            $this->logger->debug('SHAPE extension', [
                'present' => $present === 1,
                'opcode'  => $major,
            ]);
        });

        $this->conn->write(
            pack('CCvvv', 98, 0, $len, $n, 0) . $name . str_repeat("\x00", $pad),
        );
    }

    /**
     * Move the debug overlay's text block — used to clear a caption the toolkit
     * draws itself, since the overlay is in raw window coordinates.
     */
    public function setOverlayOrigin(int $x, int $y): void
    {
        $this->overlayX = $x;
        $this->overlayY = $y;
    }

    /**
     * Show the live debug readout (pointer position, last key, geometry, focus).
     *
     * Off by default: it draws in raw window coordinates straight over whatever
     * the application put there, which is useful while working on the toolkit
     * and unwelcome in anything built with it.
     */
    public function setOverlayVisible(bool $visible): void
    {
        $this->overlayVisible = $visible;
    }

    /** Whether it is overlay visible. */
    public function isOverlayVisible(): bool { return $this->overlayVisible; }

    /**
     * Turn the window manager's frame off (or on). Only meaningful before
     * {@see connect()} — window managers read _MOTIF_WM_HINTS when the window
     * is mapped, not afterwards.
     */
    public function setDecorated(bool $decorated): void
    {
        $this->decorated = $decorated;
    }

    /**
     * Whether to ask the window manager for a frame, deciding it if nobody has.
     *
     * An application that draws its own caption almost never wants the window
     * manager's as well, so that is the default rather than something to
     * remember. Calling {@see setDecorated()} overrides it either way.
     */
    private function wantsDecorations(): bool
    {
        if ($this->decorated !== null) return $this->decorated;

        return !($this->widgetManager?->drawsOwnWindowChrome() ?? false);
    }

    /**
     * Whether the window manager may resize this window.
     *
     * False pins it to whatever {@see setWindowSize()} asked for, which also
     * stops it being maximised — a maximise is a resize, so a window manager that
     * honours the size hints refuses both. Works before {@see connect()}: the
     * hints are written when the window is created, and re-written if this is
     * called later.
     *
     * This is only half the job when the *toolkit* draws the frame: the caption's
     * maximise button and the border drag are ours, and it is
     * `new WindowFrame(..., resizable: false)` that removes those. Both halves
     * are needed, because either route can resize a window on its own.
     */
    public function setResizable(bool $resizable): void
    {
        $this->resizable = $resizable;

        if ($this->conn !== null && $this->windowId !== 0) {
            $this->applySizeHints();
        }
    }

    /** Whether the window manager may resize this window. */
    public function isResizable(): bool
    {
        return $this->resizable;
    }

    /**
     * Size the window before {@see connect()} creates it. Afterwards the size is
     * whatever the window manager last reported in a ConfigureNotify.
     */
    public function setWindowSize(int $width, int $height): void
    {
        $this->windowWidth  = max(1, $width);
        $this->windowHeight = max(1, $height);
    }

    /** Whether the window manager will be asked for a frame. */
    public function isDecorated(): bool { return $this->wantsDecorations(); }

    // -------------------------------------------------------------------------
    // Window management requests
    //
    // With the frame drawn by the toolkit, moving/resizing/iconifying has to be
    // asked for explicitly. These go through the EWMH messages the window
    // manager already understands, so the *WM* runs the interactive drag — which
    // keeps snapping, constraints and smoothness intact rather than us
    // re-implementing them from motion events.
    // -------------------------------------------------------------------------

    /** _NET_WM_MOVERESIZE direction for an interactive move. */
    private const MOVERESIZE_MOVE = 8;

    /**
     * Hand an interactive window move to the WM. Coordinates are the pointer's
     * root-relative position at the moment of the press.
     */
    public function startWindowMove(int $rootX, int $rootY, int $button = 1): void
    {
        $this->sendMoveResize($rootX, $rootY, self::MOVERESIZE_MOVE, $button);
    }

    /** Hand an interactive resize of the given border to the WM. */
    public function startWindowResize(ResizeEdge $edge, int $rootX, int $rootY, int $button = 1): void
    {
        // Direction values are fixed by the EWMH spec, starting at the top-left
        // corner and running clockwise.
        $direction = match ($edge) {
            ResizeEdge::TopLeft     => 0,
            ResizeEdge::Top         => 1,
            ResizeEdge::TopRight    => 2,
            ResizeEdge::Right       => 3,
            ResizeEdge::BottomRight => 4,
            ResizeEdge::Bottom      => 5,
            ResizeEdge::BottomLeft  => 6,
            ResizeEdge::Left        => 7,
        };

        $this->sendMoveResize($rootX, $rootY, $direction, $button);
    }

    /**
     * Asks the window manager to run an interactive move or resize (`_NET_WM_MOVERESIZE`), so
     * its own snapping and constraints apply rather than ours.
     */
    private function sendMoveResize(int $rootX, int $rootY, int $direction, int $button): void
    {
        $this->withAtom('_NET_WM_MOVERESIZE', function (int $atom) use ($rootX, $rootY, $direction, $button): void {
            // data: root x, root y, direction, button, source (1 = normal app)
            $this->sendClientMessage($atom, [$rootX, $rootY, $direction, $button, 1]);
        });
    }

    /** Minimise. WM_CHANGE_STATE with IconicState is the ICCCM way. */
    public function iconifyWindow(): void
    {
        $this->withAtom('WM_CHANGE_STATE', function (int $atom): void {
            $this->sendClientMessage($atom, [3, 0, 0, 0, 0]);   // 3 = IconicState
        });
    }

    /**
     * Toggle maximized in both axes. The WM answers with a ConfigureNotify, so
     * the new size arrives through the normal resize path.
     */
    public function toggleMaximize(): void
    {
        $this->withAtom('_NET_WM_STATE', function (int $state): void {
            $this->withAtom('_NET_WM_STATE_MAXIMIZED_VERT', function (int $vert) use ($state): void {
                $this->withAtom('_NET_WM_STATE_MAXIMIZED_HORZ', function (int $horz) use ($state, $vert): void {
                    // 2 = _NET_WM_STATE_TOGGLE
                    $this->sendClientMessage($state, [2, $vert, $horz, 1, 0]);
                });
            });
        });
    }

    /**
     * Close the window: unmap it and stop the loop, which ends the process.
     * Apps that want to intervene should act on the UI event the frame handler
     * dispatches and only call this once they're ready.
     */
    public function closeWindow(): void
    {
        if ($this->conn !== null) {
            // UnmapWindow (10) then DestroyWindow (4).
            $this->conn->write(pack('CCvV', 10, 0, 2, $this->windowId));
            $this->conn->write(pack('CCvV', 4, 0, 2, $this->windowId));
        }
        $this->logger->info('Window closed');
        $this->loop->stop();
    }

    /**
     * Run $then with an interned atom, interning it first if this is the first
     * time we've needed it. Keeps the reply FIFO honest: the InternAtom handler
     * is registered at the moment its request goes out.
     *
     * @param callable(int):void $then
     */
    private function withAtom(string $name, callable $then): void
    {
        if ($this->conn === null) return;

        if (isset($this->atoms[$name])) {
            $then($this->atoms[$name]);
            return;
        }

        $this->internAtom($name, $then);
    }

    /**
     * SendEvent (opcode 25) of a 32-bit ClientMessage to the root window, which
     * is where the window manager listens. Mask = SubstructureNotify |
     * SubstructureRedirect, per EWMH.
     *
     * @param array{int, int, int, int, int} $data
     */
    private function sendClientMessage(int $messageType, array $data): void
    {
        if ($this->conn === null) return;

        $event = pack(
            'CCvVVVVVVV',
            33, 32, 0,          // ClientMessage, format=32, sequence filled by server
            $this->windowId,
            $messageType,
            $data[0], $data[1], $data[2], $data[3], $data[4],
        );

        $this->conn->write(
            pack('CCvVV', 25, 0, 11, $this->root, 0x00180000) . $event,
        );
    }

    /** Exposed for widgets that need timers (UpDown auto-repeat, animations, …). */
    public function getLoop(): LoopInterface
    {
        return $this->loop;
    }

    /** Sets widget manager. */
    public function setWidgetManager(WidgetManager $widgetManager): void
    {
        $this->widgetManager = $widgetManager;

        // Catch up on every window made before the manager existed, which is
        // the normal order: windows first, then the handlers that paint them.
        foreach ($this->childWindows as $window) {
            $this->registerWindowRoot($window);
        }
    }

    /** The window X. */
    public function getWindowX(): int      { return $this->windowX; }
    /** The window Y. */
    public function getWindowY(): int      { return $this->windowY; }
    /** The window width. */
    public function getWindowWidth(): int  { return $this->windowWidth; }
    /** The window height. */
    public function getWindowHeight(): int { return $this->windowHeight; }
    /** The window id. */
    public function getWindowId(): int       { return $this->windowId; }
    /** The dialog window id. */
    public function getDialogWindowId(): int { return $this->dialogWindowId; }

    /**
     * Maps the message box at these root coordinates and grabs the pointer, which is what
     * makes it modal.
     */
    public function showDialogWindow(int $x, int $y): void
    {
        if ($this->conn === null) return;

        // ConfigureWindow: move to (x, y) — value-mask x=0x1, y=0x2
        $this->conn->write(pack('CCvVvvVV', 12, 0, 5, $this->dialogWindowId, 0x0003, 0, $x, $y));
        // MapWindow
        $this->conn->write(pack('CCvV', 8, 0, 2, $this->dialogWindowId));
        $this->dialogMapped = true;
        $this->raiseWindow($this->dialogWindowId);
        // GrabPointer: route every pointer event to the dialog so the parent
        // can't intercept clicks (and therefore can't be raised over us).
        // event-mask = ButtonPress | ButtonRelease | PointerMotion | Button1Motion
        $eventMask = 0x0004 | 0x0008 | 0x0040 | 0x0100;
        // GrabPointer produces a 32-byte reply (success status). Drop it
        // explicitly so it doesn't shift the FIFO of clipboard handlers.
        $this->expectReply(static function (string $raw): void { /* status: ignore */ });
        $this->conn->write(pack(
            'CCvVvCCVVV',
            26,                      // GrabPointer opcode
            0,                       // owner-events = False (everything to dialog)
            6,                       // request length (24 bytes / 4)
            $this->dialogWindowId,   // grab-window
            $eventMask,
            1,                       // pointer-mode = Async
            1,                       // keyboard-mode = Async
            0,                       // confine-to = None
            0,                       // cursor = None
            0,                       // time = CurrentTime
        ));
    }

    /** Unmaps the message box and releases the pointer grab. */
    public function hideDialogWindow(): void
    {
        if ($this->conn === null) return;

        // UngrabPointer — return pointer events to the normal routing.
        $this->conn->write(pack('CCvV', 27, 0, 2, 0));
        // UnmapWindow
        $this->conn->write(pack('CCvV', 10, 0, 2, $this->dialogWindowId));
        $this->dialogMapped = false;
    }

    /** Moves the message box, in root coordinates. */
    public function moveDialogWindow(int $x, int $y): void
    {
        if ($this->conn === null) return;
        $this->conn->write(pack('CCvVvvVV', 12, 0, 5, $this->dialogWindowId, 0x0003, 0, $x, $y));
    }

    /** Repaints the message box, re-applying its shape in case the theme changed. */
    public function redrawDialog(): void
    {
        if ($this->conn === null || $this->dialogRenderer === null) return;
        // The dialog's caption may be a tab, in which case the strip beside it is
        // cut out of the window before painting.
        $this->setDialogShape($this->widgetManager?->dialogShapeRects($this->dialogRenderer) ?? []);

        // ClearArea the dialog window
        $this->conn->write(pack('CCvVvvvv', 61, 0, 4, $this->dialogWindowId, 0, 0, 0, 0));
        $this->widgetManager?->paintDialog($this->dialogRenderer);
        $this->dialogRenderer->flush();
    }

    // -------------------------------------------------------------------------
    // Clipboard (CLIPBOARD selection, UTF8_STRING only)
    // -------------------------------------------------------------------------

    /**
     * Claim ownership of an arbitrary selection atom (CLIPBOARD, PRIMARY, …).
     * We cache $text and hand it back to any client that asks via
     * SelectionRequest. A SelectionClear event drops the cache.
     */
    public function writeSelection(int $selectionAtom, string $text): void
    {
        if ($this->conn === null || $selectionAtom === 0) return;

        $this->ownedSelections[$selectionAtom] = $text;
        // SetSelectionOwner opcode 22: owner = our main window, time = 0.
        $this->conn->write(pack('CCvVVV', 22, 0, 4, $this->windowId, $selectionAtom, 0));
    }

    /**
     * Ask the current owner of $selectionAtom for UTF8_STRING text.
     * $callback is invoked with the result (or null on failure) the next
     * time around the event loop.
     */
    public function readSelection(int $selectionAtom, \Closure $callback): void
    {
        if ($this->conn === null || $selectionAtom === 0) {
            $callback(null);
            return;
        }
        $utf8 = $this->atoms['UTF8_STRING']             ?? 0;
        $prop = $this->atoms['PHP_X11_CLIPBOARD_DATA']  ?? 0;
        if (!$utf8 || !$prop) {
            $callback(null);
            return;
        }

        $this->pendingPasteCallback = $callback;

        // ConvertSelection opcode 24.
        $this->conn->write(pack(
            'CCvVVVVV',
            24, 0, 6,
            $this->windowId,
            $selectionAtom,
            $utf8,
            $prop,
            0,                          // time = CurrentTime
        ));
    }

    // ---- Thin convenience wrappers for the two selections we use --------

    /**
     * Takes ownership of CLIPBOARD and holds this text until another client asks for it or
     * claims the selection.
     */
    public function writeClipboard(string $text): void
    {
        $this->writeSelection($this->atoms['CLIPBOARD'] ?? 0, $text);
    }

    /**
     * Asks the CLIPBOARD owner to convert its selection; the text arrives later, through the
     * callback.
     */
    public function readClipboard(\Closure $callback): void
    {
        $this->readSelection($this->atoms['CLIPBOARD'] ?? 0, $callback);
    }

    /** Takes ownership of PRIMARY — the selection a middle-click pastes. */
    public function writePrimary(string $text): void
    {
        $this->writeSelection(self::ATOM_PRIMARY, $text);
    }

    /** Asks the PRIMARY owner for its text, for a middle-click paste. */
    public function readPrimary(\Closure $callback): void
    {
        $this->readSelection(self::ATOM_PRIMARY, $callback);
    }

    /**
     * SelectionNotify lands here once the clipboard owner has written the
     * data to our window property. We GetProperty to read it back.
     */
    public function onSelectionNotify(X11SelectionNotifyEvent $event): PromiseInterface
    {
        if ($this->pendingPasteCallback === null) return resolve(null);

        if ($event->property === 0) {
            // Conversion failed (no owner, refused target, etc.).
            ($this->pendingPasteCallback)(null);
            $this->pendingPasteCallback = null;
            return resolve(null);
        }

        // GetProperty opcode 20: delete=1, offset=0, length=4096 32-bit units.
        $this->expectReply(function (string $raw): void {
            $callback = $this->pendingPasteCallback;
            $this->pendingPasteCallback = null;
            if ($callback === null) return;

            $format     = ord($raw[1]);
            $valueLen   = unpack('V', substr($raw, 16, 4))[1];

            if ($format !== 8 || $valueLen === 0) {
                $callback(null);
                return;
            }
            $callback(substr($raw, 32, $valueLen));
        });
        $this->conn->write(pack(
            'CCvVVVVV',
            20, 1, 6,                  // opcode, delete=true, length
            $event->requestor,         // our window
            $event->property,          // the property they wrote into
            0,                         // type = AnyPropertyType
            0,                         // long-offset (in 32-bit units)
            4096,                      // long-length (in 32-bit units, ~16KB)
        ));

        return resolve(null);
    }

    /**
     * Another client wants our cached selection data in some target format.
     * Dispatches by $event->selection (CLIPBOARD vs PRIMARY vs …) and by
     * $event->target (UTF8_STRING / STRING / TARGETS). Anything else is
     * refused by acking with property = 0.
     */
    public function onSelectionRequest(X11SelectionRequestEvent $event): PromiseInterface
    {
        $text = $this->ownedSelections[$event->selection] ?? null;

        $utf8       = $this->atoms['UTF8_STRING'] ?? 0;
        $targets    = $this->atoms['TARGETS']     ?? 0;
        $stringAtom = 31;   // XA_STRING, predefined
        $atomAtom   = 4;    // XA_ATOM,   predefined

        $deliver  = false;
        $property = $event->property !== 0 ? $event->property : $event->target;

        if ($text !== null
            && ($event->target === $utf8 || $event->target === $stringAtom)) {
            $this->changeProperty($event->requestor, $property, $event->target, 8, $text);
            $deliver = true;
        } elseif ($text !== null && $event->target === $targets) {
            $list = pack('V*', $targets, $utf8, $stringAtom);
            $this->changeProperty($event->requestor, $property, $atomAtom, 32, $list);
            $deliver = true;
        }

        $this->sendSelectionNotify(
            $event->requestor,
            $event->selection,
            $event->target,
            $deliver ? $property : 0,
            $event->time,
        );

        return resolve(null);
    }

    /** Another client stole the named selection from us — drop our cache. */
    public function onSelectionClear(X11SelectionClearEvent $event): PromiseInterface
    {
        unset($this->ownedSelections[$event->selection]);
        return resolve(null);
    }

    /** ChangeProperty opcode 18. format ∈ {8, 16, 32}. */
    private function changeProperty(
        int $window, int $property, int $type, int $format, string $data, int $mode = 0,
    ): void {
        $n    = strlen($data);
        $pad  = (4 - ($n % 4)) % 4;
        $unit = match ($format) {  8 => 1, 16 => 2, 32 => 4, default => 1 };
        $count = intdiv($n, $unit);
        $len  = 6 + intdiv($n + $pad, 4);

        $this->conn->write(
            pack('CCvVVVCCCCV', 18, $mode, $len, $window, $property, $type, $format, 0, 0, 0, $count)
            . $data . str_repeat("\x00", $pad),
        );
    }

    /** SendEvent (opcode 25) carrying a SelectionNotify back to the requestor. */
    private function sendSelectionNotify(int $requestor, int $selection, int $target, int $property, int $time): void
    {
        // 32-byte SelectionNotify event payload, server fills in the sequence.
        $event = pack(
            'CCvVVVVV',
            31, 0, 0,                  // type=SelectionNotify, pad, seq=0
            $time,
            $requestor,
            $selection,
            $target,
            $property,
        ) . str_repeat("\x00", 8);

        // SendEvent: propagate=0, length=11, destination=requestor, mask=0.
        $this->conn->write(
            pack('CCvVV', 25, 0, 11, $requestor, 0) . $event,
        );
    }

    /**
     * Opens the socket and starts the handshake. The returned promise resolves once the server
     * has answered and the window exists.
     */
    public function connect(string $display = 'unix:///tmp/.X11-unix/X0'): PromiseInterface
    {
        $connector = new Connector($this->loop);

        return $connector->connect($display)->then(
            fn(ConnectionInterface $conn) => $this->onConnect($conn),
            fn(\Throwable $e) => $this->onError($e),
        );
    }

    /** The socket is open: write the connection setup record and wait for the reply. */
    private function onConnect(ConnectionInterface $conn): void
    {
        $this->conn = $conn;
        $this->logger->info('Connected to X server');

        // Little-endian connection setup request (no auth)
        $this->conn->write(pack('CCvvvvv', 0x6c, 0x00, 11, 0, 0, 0, 0));

        $this->conn->on('data',  fn(string $data) => $this->onData($data));
        $this->conn->on('error', fn(\Throwable $e) => $this->onError($e));
        $this->conn->on('close', fn() => $this->onClose());
    }

    /**
     * Bytes arrived. Buffers them and drains whole records, since a read can split or coalesce
     * them.
     */
    private function onData(string $data): void
    {
        $this->buffer .= $data;

        if ($this->stage === 'setup') {
            $this->processSetup();
        } else {
            $this->processEvents();
        }
    }

    /**
     * Parses the setup reply, then creates everything that needs the resource id range it
     * hands back.
     */
    private function processSetup(): void
    {
        if (!$this->setupParser->canParse($this->buffer)) {
            return;
        }

        [$setupEvent, $consumed] = $this->setupParser->parse($this->buffer);
        $this->buffer = substr($this->buffer, $consumed);

        $this->root     = $setupEvent->root;
        $this->ridBase  = $setupEvent->ridBase;
        $this->windowId     = $this->ridBase | 1;
        $this->fontId       = $this->ridBase | 2;
        $this->boldFontId   = $this->ridBase | 7;
        $this->gcId         = $this->ridBase | 3;
        $this->cursorFontId = $this->ridBase | 5;
        $this->busyCursorId = $this->ridBase | 6;
        $this->colormapId   = $this->ridBase | 8;

        $this->argbVisual  = $setupEvent->argbVisual;
        $this->screenWidth  = $setupEvent->screenWidth;
        $this->screenHeight = $setupEvent->screenHeight;

        $this->logger->debug('Server info', [
            'root'   => sprintf('0x%x', $this->root),
            'window' => sprintf('0x%x', $this->windowId),
        ]);
        $this->logger->debug('Screen info', [
            'width'       => $setupEvent->screenWidth,
            'height'      => $setupEvent->screenHeight,
            'depth'       => $setupEvent->depth,
            'argb visual' => $this->argbVisual !== 0
                ? sprintf('0x%x', $this->argbVisual)
                : 'none — window stays opaque',
        ]);

        // An ARGB window carries its own colormap: the root's belongs to a
        // 24-bit visual, and mixing the two is a BadMatch.
        if ($this->argbVisual !== 0) {
            $this->createColormap();
        }

        $this->createWindow(
            $this->argbVisual !== 0 ? 32 : $setupEvent->depth,
            $this->argbVisual !== 0 ? $this->argbVisual : $setupEvent->visual,
        );
        $this->stage = 'events';

        $this->dispatcher->dispatch($setupEvent);

        if (strlen($this->buffer) > 0) {
            $this->processEvents();
        }
    }

    /**
     * Pull events AND replies out of the buffer. Events/errors are 32 bytes;
     * replies have a 4-byte "extra length" at offset 4 (in 4-byte units beyond
     * the 32-byte header), so total reply size = 32 + 4 * extra.
     */
    private function processEvents(): void
    {
        while (true) {
            $len = strlen($this->buffer);
            if ($len < 32) return;

            $type = ord($this->buffer[0]);

            if ($type === 1) {
                $extra = unpack('V', substr($this->buffer, 4, 4))[1];
                $total = 32 + 4 * $extra;
                if ($len < $total) return;

                $raw          = substr($this->buffer, 0, $total);
                $this->buffer = substr($this->buffer, $total);
                $this->handleReply($raw);
                continue;
            }

            $raw          = substr($this->buffer, 0, 32);
            $this->buffer = substr($this->buffer, 32);

            // Before dispatching: an event that means the main window just came
            // forward is the cue to put the floating windows back above it.
            $this->keepFloatingOnTop($raw);

            $event = $this->eventParser->parseOne($raw);
            if ($event !== null) {
                $this->dispatcher->dispatchAsync($event);
            }
        }
    }

    /**
     * Re-raise the floating windows when the main window comes forward.
     *
     * Read off the raw event rather than the parsed one, because it has to
     * happen *before* the application sees the event — otherwise a handler that
     * repaints leaves the newly-raised window painted underneath.
     *
     * Two cues, and both are needed: **ButtonPress** is the window manager
     * raising us because the user clicked, and **Expose** is it raising us for
     * any other reason (alt-tab, another application closing) — a click is not
     * the only way to come forward. Expose from our own drawing can't trigger
     * this: every ClearArea the toolkit sends has exposures = False.
     */
    private function keepFloatingOnTop(string $raw): void
    {
        // Event codes: 4 = ButtonPress, 12 = Expose. The low 7 bits; bit 7 marks
        // an event sent by SendEvent, which we treat the same.
        $code = ord($raw[0]) & 0x7F;
        if ($code !== 4 && $code !== 12) return;

        // The window sits at a different offset in each: Expose's `window` comes
        // straight after the sequence number, ButtonPress's `event` after time
        // and root as well. Only the main window's count.
        $window = unpack('V', substr($raw, $code === 12 ? 4 : 12, 4))[1];
        if ($window !== $this->windowId) return;

        $this->raiseFloatingWindows();
    }

    /**
     * Pop the next pending reply handler and run it. Relies on X11's
     * serial processing — replies come back in the order their requests
     * were sent, so a simple FIFO keeps things matched up.
     */
    private function handleReply(string $raw): void
    {
        $handler = array_shift($this->replyHandlers);
        if ($handler === null) {
            $this->logger->warning('Unexpected X11 reply with no pending handler', [
                'size' => strlen($raw),
            ]);
            return;
        }
        $handler($raw);
    }

    /** Register a reply handler before sending a reply-expecting request. */
    private function expectReply(\Closure $handler): void
    {
        $this->replyHandlers[] = $handler;
    }

    /**
     * Parse the X11 QueryFont reply. Layout (offsets in bytes):
     *   40-41  min-char-or-byte2
     *   42-43  max-char-or-byte2
     *   46-47  number of FONTPROPs (n)
     *   52-53  font-ascent
     *   54-55  font-descent
     *   56-59  number of CHARINFOs (m)
     *   60..   8n bytes of FONTPROPs, then 12m bytes of CHARINFOs
     *   each CHARINFO has the per-glyph char-width at byte 4 (INT16).
     */
    private function parseQueryFontReply(string $raw): FontMetrics
    {
        $minChar      = unpack('v', substr($raw, 40, 2))[1];
        $maxChar      = unpack('v', substr($raw, 42, 2))[1];
        $numProps     = unpack('v', substr($raw, 46, 2))[1];
        $fontAscent   = unpack('s', substr($raw, 52, 2))[1];
        $fontDescent  = unpack('s', substr($raw, 54, 2))[1];
        $numCharInfos = unpack('V', substr($raw, 56, 4))[1];

        $infoStart = 60 + $numProps * 8;
        $widths    = [];
        for ($i = 0; $i < $numCharInfos; $i++) {
            $widths[] = unpack('s', substr($raw, $infoStart + $i * 12 + 4, 2))[1];
        }

        $count        = count($widths);
        $defaultWidth = $count > 0 ? max(1, intdiv((int) array_sum($widths), $count)) : 6;

        return new FontMetrics(
            ascent: $fontAscent,
            descent: $fontDescent,
            defaultWidth: $defaultWidth,
            minChar: $minChar,
            maxChar: $maxChar,
            widths: $widths,
        );
    }

    /** Last-resort font: 'fixed' is present on every X server. */
    private const FALLBACK_FONT = 'fixed';

    /**
     * CreateColormap (opcode 78) for the ARGB visual. alloc = None(0): a
     * TrueColor colormap has no entries to allocate, the pixel values *are* the
     * colours.
     */
    private function createColormap(): void
    {
        $this->conn->write(pack('CCvVVV', 78, 0, 4, $this->colormapId, $this->root, $this->argbVisual));
    }

    /** True when the windows have an alpha channel, so a hole is really see-through. */
    public function hasTranslucency(): bool
    {
        return $this->argbVisual !== 0;
    }

    /**
     * Window background, opaque. On an ARGB visual the top byte is the alpha
     * channel, and a background-pixel with alpha 0 would make every ClearArea
     * flash the whole window transparent before the painters run.
     */
    private function windowBackgroundPixel(): int
    {
        $pixel = Palette::pixel($this->themes->palette()->face);

        return $this->argbVisual !== 0 ? $pixel | 0xFF000000 : $pixel;
    }

    /**
     * Creates the main window (CreateWindow, opcode 1), on the ARGB visual when the server has
     * one.
     */
    private function createWindow(int $depth, int $visual): void
    {
        $eventMask = 0x0001       // KeyPressMask
                   | 0x0002       // KeyReleaseMask
                   | 0x0004       // ButtonPressMask
                   | 0x0008       // ButtonReleaseMask
                   | 0x0040       // PointerMotionMask
                   | 0x00008000   // ExposureMask
                   | 0x00020000   // StructureNotifyMask  (configure/map/unmap)
                   | 0x00200000;  // FocusChangeMask

        $this->depth  = $depth;
        $this->visual = $visual;

        // On an ARGB visual the window's depth differs from its parent's, which
        // means border-pixel and colormap have to be given explicitly —
        // inheriting either from a 24-bit root is a BadMatch. Values go out in
        // ascending mask-bit order.
        $translucent = $this->argbVisual !== 0;
        $valueMask   = $translucent
            ? 0x0002 | 0x0008 | 0x0800 | 0x2000   // background-pixel | border-pixel | event-mask | colormap
            : 0x0002 | 0x0800;                    // background-pixel | event-mask
        $values = $translucent
            ? pack('VVVV', $this->windowBackgroundPixel(), 0, $eventMask, $this->colormapId)
            : pack('VV', $this->windowBackgroundPixel(), $eventMask);

        $request  = pack('CCvVVssvvvvVV', 1, $depth, 8 + intdiv(strlen($values), 4),
                         $this->windowId, $this->root, 100, 100,
                         $this->windowWidth, $this->windowHeight, 0, 1, $visual, $valueMask);
        $request .= $values;
        $this->conn->write($request);

        $this->logger->info('Window created', ['id' => sprintf('0x%x', $this->windowId)]);

        // Ask the server which of the theme's fonts it actually has. The rest of
        // setup (OpenFont / CreateGC / QueryFont / atom interning / dialog
        // window / MapWindow) runs in the reply handler with the chosen font.
        $this->probeFontAndComplete($depth, $visual);
    }

    /**
     * Probe the theme's fonts, then finish setup with whichever one the server
     * has. This is the only async step on the startup critical path — MapWindow
     * is deferred until the font is open so the first Expose paints with real
     * metrics.
     */
    private function probeFontAndComplete(int $depth, int $visual): void
    {
        $this->probeFont(
            $this->themes->current()->fontCandidates(),
            fn(?string $chosen) => $this->completeSetup($depth, $visual, $chosen ?? self::FALLBACK_FONT),
        );
    }

    /**
     * Open the theme's bold face on a second font id, for window titles.
     *
     * Optional by design: a theme may not name one, and a server may not have
     * it, in which case the id stays 0 and {@see Renderer::setBold()} no-ops so
     * titles simply render in the regular face. The reply lands after the first
     * Expose, so a repaint is requested once the metrics are in.
     */
    private function probeBoldFont(): void
    {
        $candidates = $this->themes->current()->boldFontCandidates();
        if ($candidates === []) {
            $this->applyBoldFont(null);
            return;
        }

        $this->probeFont($candidates, fn(?string $chosen) => $this->applyBoldFont($chosen), null);
    }

    /** Opens the theme's bold face, if the server has one; the id stays 0 when it has not. */
    private function applyBoldFont(?string $name): void
    {
        if ($name === null || $name === $this->boldFontName) {
            if ($name === null) {
                $this->renderer->setFonts($this->fontId, 0);
                $this->dialogRenderer?->setFonts($this->fontId, 0);
                $this->fileDialogRenderer?->setFonts($this->fontId, 0);
                foreach ($this->childWindows as $child) $child->renderer->setFonts($this->fontId, 0);
                $this->logger->debug('No bold font — titles use the regular face');
            }
            return;
        }

        // Swap under the same id when one is already open.
        if ($this->boldFontName !== '') {
            $this->conn->write(pack('CCvV', 46, 0, 2, $this->boldFontId));   // CloseFont
        }

        $n   = strlen($name);
        $pad = (4 - ($n % 4)) % 4;
        $len = 3 + intdiv($n + $pad, 4);
        $this->conn->write(
            pack('CCvVvv', 45, 0, $len, $this->boldFontId, $n, 0) . $name . str_repeat("\x00", $pad),
        );
        $this->boldFontName = $name;

        $this->renderer->setFonts($this->fontId, $this->boldFontId);
        $this->dialogRenderer?->setFonts($this->fontId, $this->boldFontId);
        $this->fileDialogRenderer?->setFonts($this->fontId, $this->boldFontId);
        foreach ($this->childWindows as $child) $child->renderer->setFonts($this->fontId, $this->boldFontId);

        // QueryFont so bold titles measure with bold widths.
        $this->expectReply(function (string $raw): void {
            if (strlen($raw) < 60) return;

            $metrics = $this->parseQueryFontReply($raw);
            $this->renderer->setBoldFontMetrics($metrics);
            $this->dialogRenderer?->setBoldFontMetrics($metrics);
            $this->fileDialogRenderer?->setBoldFontMetrics($metrics);
            foreach ($this->childWindows as $child) $child->renderer->setBoldFontMetrics($metrics);
            $this->redraw();
            $this->redrawDialog();
        });
        $this->conn->write(pack('CCvV', 47, 0, 2, $this->boldFontId));

        $this->logger->debug('Bold font loaded', ['font' => $name]);
    }

    /**
     * Walk $candidates in order, asking ListFonts about each, and hand the first
     * one the server matches to $done. Falls back to 'fixed' when nothing
     * matches.
     *
     * Each probe registers its reply handler at the same moment its request goes
     * out, which keeps the shared reply FIFO paired up.
     *
     * @param list<string>           $candidates
     * @param callable(?string):void $done       Receives $fallback if nothing matched.
     */
    private function probeFont(array $candidates, callable $done, ?string $fallback = self::FALLBACK_FONT): void
    {
        $pattern = array_shift($candidates);

        if ($pattern === null) {
            $done($fallback);
            return;
        }

        if ($pattern === self::FALLBACK_FONT) {
            // No point asking about the font every server is required to have.
            $done(self::FALLBACK_FONT);
            return;
        }

        $n   = strlen($pattern);
        $pad = (4 - ($n % 4)) % 4;
        $len = 2 + intdiv($n + $pad, 4);

        $this->expectReply(function (string $raw) use ($pattern, $candidates, $done, $fallback): void {
            // ListFonts reply: 2-byte name count at offset 8.
            $count = unpack('v', substr($raw, 8, 2))[1];

            $this->logger->debug('Font probe', ['pattern' => $pattern, 'matches' => $count]);

            if ($count > 0) {
                $done($pattern);
                return;
            }
            $this->probeFont($candidates, $done, $fallback);
        });

        // ListFonts opcode 49: max-names (CARD16), pattern-length (CARD16),
        // pattern (STRING8), pad-to-4-bytes.
        $this->conn->write(
            pack('CCvvv', 49, 0, $len, 1, $n) . $pattern . str_repeat("\x00", $pad),
        );
    }

    /**
     * Interns the atoms, sets the window properties and maps the window — in that order,
     * because a window manager reads the undecorate hint only at map time.
     */
    private function completeSetup(int $depth, int $visual, string $fontName): void
    {
        $this->openFont($fontName);
        $this->createGC();
        $this->openCursorFont();
        $this->createBusyCursor();
        $this->queryFont();
        // Pre-intern the clipboard atoms so writeClipboard/readClipboard
        // can use them by name later without a round trip.
        $this->internAtom('CLIPBOARD');
        $this->internAtom('UTF8_STRING');
        $this->internAtom('TARGETS');
        $this->internAtom('PHP_X11_CLIPBOARD_DATA');

        // Learn whether we can give the window a non-rectangular outline.
        $this->queryShapeExtension();

        $this->renderer->init($this->conn, $this->windowId, $this->gcId, $this->gcState);
        $this->renderer->setTranslucent($this->argbVisual !== 0);
        $this->renderer->setFonts($this->fontId);
        $this->logger->debug('Renderer initialised');

        $this->createDialogWindow($depth, $visual);
        $this->createFileDialogWindow($depth, $visual);

        // Windows the application registered before connecting.
        foreach ($this->childWindows as $window) {
            $this->realiseWindow($window);
        }

        $this->applyWindowTitle();
        $this->applySizeHints();
        $this->probeBoldFont();

        // Map last so the QueryFont + InternAtom replies land before the first
        // Expose event. When the frame is ours to draw, the decoration hint has
        // to be on the window *before* it maps, and that needs one more atom —
        // so mapping waits for that reply.
        if ($this->wantsDecorations()) {
            $this->mapWindow($fontName);
            return;
        }

        $this->internAtom('_MOTIF_WM_HINTS', function (int $atom) use ($fontName): void {
            // MWM hints are 5 CARD32s: flags, functions, decorations, input
            // mode, status. flags = MWM_HINTS_DECORATIONS (2) with decorations
            // = 0 means "no frame, please".
            $this->changeProperty(
                $this->windowId, $atom, self::ATOM_CARDINAL, 32,
                pack('VVVVV', 2, 0, 0, 0, 0),
            );
            $this->mapWindow($fontName);
        });
    }

    /** Puts the main window on screen (MapWindow, opcode 8). */
    private function mapWindow(string $fontName): void
    {
        $this->conn->write(pack('CCvV', 8, 0, 2, $this->windowId));
        $this->logger->info('Window mapped', [
            'font'      => $fontName,
            'decorated' => $this->wantsDecorations(),
        ]);
    }

    /**
     * WM_NORMAL_HINTS, so the window manager knows whether it may resize us.
     *
     * The ICCCM structure is eighteen 32-bit words and mostly obsolete; only the
     * flags and the min/max pairs matter here. `PMinSize | PMaxSize` is 0x30, and
     * setting both to the same size is how a fixed-size window is expressed —
     * there is no "not resizable" flag.
     *
     * A resizable window gets a minimum only, so it cannot be dragged smaller
     * than its own chrome.
     */
    private function applySizeHints(): void
    {
        $minWidth  = $this->resizable ? self::MIN_WINDOW_WIDTH  : $this->windowWidth;
        $minHeight = $this->resizable ? self::MIN_WINDOW_HEIGHT : $this->windowHeight;

        $flags = 0x10;                                   // PMinSize
        $maxWidth = $maxHeight = 0;
        if (!$this->resizable) {
            $flags |= 0x20;                              // PMaxSize
            $maxWidth  = $this->windowWidth;
            $maxHeight = $this->windowHeight;
        }

        $this->changeProperty(
            $this->windowId,
            self::ATOM_WM_NORMAL_HINTS,
            self::ATOM_WM_SIZE_HINTS,
            32,
            pack(
                'V18',
                $flags,
                0, 0, 0, 0,                              // obsolete x, y, width, height
                $minWidth, $minHeight,
                $maxWidth, $maxHeight,
                0, 0,                                    // width_inc, height_inc
                0, 0, 0, 0,                              // min/max aspect
                0, 0,                                    // base width, height
                0,                                       // win_gravity
            ),
        );
    }

    /** WM_NAME is a predefined atom, so the title costs no round trip. */
    private function applyWindowTitle(): void
    {
        $this->changeProperty(
            $this->windowId,
            self::ATOM_WM_NAME,
            self::ATOM_STRING,
            8,
            $this->windowTitle,
        );
    }

    /**
     * InternAtom (opcode 16). only-if-exists=false so the X server creates
     * the atom on demand (PHP_X11_CLIPBOARD_DATA almost certainly doesn't
     * exist anywhere else). Result stored in $this->atoms by name.
     */
    private function internAtom(string $name, ?callable $then = null): void
    {
        $n   = strlen($name);
        $pad = (4 - ($n % 4)) % 4;
        $len = 2 + intdiv($n + $pad, 4);

        $this->expectReply(function (string $raw) use ($name, $then): void {
            // InternAtom reply: atom ID at bytes 8..11
            $atom = unpack('V', substr($raw, 8, 4))[1];
            $this->atoms[$name] = $atom;
            $this->logger->debug('Atom interned', ['name' => $name, 'id' => $atom]);
            if ($then !== null) {
                $then($atom);
            }
        });

        $this->conn->write(
            pack('CCvvv', 16, 0, $len, $n, 0) . $name . str_repeat("\x00", $pad),
        );
    }

    /**
     * Creates the message box's window: override-redirect, so the window manager leaves its
     * frame to us.
     */
    private function createDialogWindow(int $depth, int $visual): void
    {
        $this->dialogWindowId = $this->ridBase | 4;
        $this->dialogRenderer = new Renderer();

        // ButtonPressMask | ButtonReleaseMask | Button1MotionMask | ExposureMask
        $eventMask = 0x0004 | 0x0008 | 0x0100 | 0x8000;

        // Same visual as the main window, so the dialog can punch its own
        // transparent strip (see createWindow() for why the extra values).
        $translucent = $this->argbVisual !== 0;
        $valueMask   = $translucent
            ? 0x0002 | 0x0008 | 0x0200 | 0x0800 | 0x2000
            : 0x0002 | 0x0200 | 0x0800;
        $values = $translucent
            ? pack('VVVVV', $this->windowBackgroundPixel(), 0, 1, $eventMask, $this->colormapId)
            : pack('VVV', $this->windowBackgroundPixel(), 1, $eventMask);

        // Created off-screen at (0,0); positioned via ConfigureWindow before mapping.
        $request  = pack('CCvVVssvvvvVV', 1, $depth, 8 + intdiv(strlen($values), 4),
                         $this->dialogWindowId, $this->root,
                         0, 0, MessageBox::WIDTH, MessageBox::HEIGHT,
                         0, 1, $visual, $valueMask);
        $request .= $values;
        $this->conn->write($request);

        $this->dialogRenderer->init($this->conn, $this->dialogWindowId, $this->gcId, $this->gcState);
        $this->dialogRenderer->setTranslucent($translucent);
        $this->logger->debug('Dialog window created', ['id' => sprintf('0x%x', $this->dialogWindowId)]);
    }

    /**
     * The file dialog's own top-level window.
     *
     * Same shape as the message box's: override-redirect, so the window manager
     * leaves it alone and the toolkit draws the whole thing, and its own
     * Renderer so the widget subtree inside it paints in window-local
     * coordinates. Created here and resized on demand — unlike the message box
     * its size comes from the theme, so it changes on a switch.
     */
    private function createFileDialogWindow(int $depth, int $visual): void
    {
        $this->fileDialogWindowId = $this->ridBase | 9;
        $this->fileDialogRenderer = new Renderer();

        // ButtonPress | ButtonRelease | PointerMotion | Button1Motion | Exposure
        $eventMask = 0x0004 | 0x0008 | 0x0040 | 0x0100 | 0x8000;

        $translucent = $this->argbVisual !== 0;
        $valueMask   = $translucent
            ? 0x0002 | 0x0008 | 0x0200 | 0x0800 | 0x2000
            : 0x0002 | 0x0200 | 0x0800;
        $values = $translucent
            ? pack('VVVVV', $this->windowBackgroundPixel(), 0, 1, $eventMask, $this->colormapId)
            : pack('VVV', $this->windowBackgroundPixel(), 1, $eventMask);

        $request  = pack('CCvVVssvvvvVV', 1, $depth, 8 + intdiv(strlen($values), 4),
                         $this->fileDialogWindowId, $this->root,
                         0, 0, max(1, $this->fileDialogWidth), max(1, $this->fileDialogHeight),
                         0, 1, $visual, $valueMask);
        $request .= $values;
        $this->conn->write($request);

        $this->fileDialogRenderer->init($this->conn, $this->fileDialogWindowId, $this->gcId, $this->gcState);
        $this->fileDialogRenderer->setTranslucent($translucent);
        $this->logger->debug('File dialog window created', [
            'id' => sprintf('0x%x', $this->fileDialogWindowId),
        ]);
    }

    /** Screen extent, falling back to the window's own size before setup lands. */
    public function getScreenWidth(): int  { return $this->screenWidth  > 0 ? $this->screenWidth  : $this->windowWidth;  }
    /** The screen height. */
    public function getScreenHeight(): int { return $this->screenHeight > 0 ? $this->screenHeight : $this->windowHeight; }

    /** The file dialog window id. */
    public function getFileDialogWindowId(): int { return $this->fileDialogWindowId; }

    // -------------------------------------------------------------------------
    // Application windows
    //
    // The general facility behind the two built-in dialogs. All of the wire
    // protocol stays here — ChildWindow is a handle that names a window and
    // calls these.
    // -------------------------------------------------------------------------

    /**
     * Create a top-level window for the application to draw a form in.
     *
     * Safe to call before {@see connect()}: the handle comes back straight away
     * and the X11 window is created when the connection is set up, which is the
     * same deferral {@see setWindowSize()} and {@see setDecorated()} rely on.
     * Operations on a window that doesn't exist yet are no-ops, so an
     * application never has to sequence around the connection.
     *
     * @param Widget|null  $root    Widget subtree to paint into it, rooted at (0, 0).
     * @param Closure|null $painter Alternative for a hand-drawn window: Closure(Renderer): void.
     * @param bool $alwaysOnTop Re-raise it whenever the main window comes forward.
     */
    public function createChildWindow(
        int      $width,
        int      $height,
        bool     $modal       = true,
        ?Widget  $root        = null,
        ?Closure $painter     = null,
        bool     $alwaysOnTop = false,
    ): ChildWindow {
        $window = new ChildWindow(
            $this,
            new Renderer(),
            $width,
            $height,
            $modal,
            $root,
            $painter,
            $alwaysOnTop,
        );

        $this->childWindows[] = $window;

        // Handing over a root *is* saying "this widget is this window's tree", so
        // registering it is this method's job and not the application's.
        //
        // It used to be a second, separate call the application had to remember,
        // and forgetting it failed silently in the worst way: an unregistered
        // root never gets a ThemeManager, so `Widget::metrics()` falls back to
        // `Metrics::defaults()` — the *Windows 9x* numbers — while the painters
        // keep drawing in the live theme. Under BeOS that is a partial tab's
        // chrome painted into a full-width Win9x caption, with nothing cut away
        // because the root also reports no shape. It looks like a broken theme
        // rather than a missing line of setup.
        $this->registerWindowRoot($window);

        // Already connected? Then create it now rather than never.
        if ($this->conn !== null && $this->visual !== 0) {
            $this->realiseWindow($window);
        }

        return $window;
    }

    /**
     * Hand a child window's root to the widget tree, if there is one yet.
     *
     * Windows are usually created *before* the manager exists — an application
     * wires its windows, then builds the handlers that paint them — so this runs
     * again from {@see setWidgetManager()} for everything registered by then.
     * {@see \Cyrnetix\X11\UI\WidgetTree::addRoot()} is idempotent, so an
     * application that also registers its roots by hand is not punished for it.
     */
    private function registerWindowRoot(ChildWindow $window): void
    {
        $root = $window->root();
        if ($root === null) return;

        $this->widgetManager?->addChild($root);
    }

    /**
     * Raise one window to the top of the stack.
     *
     * ConfigureWindow with stack-mode = Above. Needed because every window this
     * toolkit makes besides the main one is override-redirect: the window manager
     * doesn't stack them, so when it raises the *main* window they end up behind
     * it with nothing to put them back.
     */
    public function raiseWindow(int $windowId): void
    {
        if ($this->conn === null || $windowId === 0) return;

        // ConfigureWindow opcode 12: value-mask 0x0040 = stack-mode, Above = 0.
        $this->conn->write(pack('CCvVvvV', 12, 0, 4, $windowId, 0x0040, 0, 0));
    }

    /**
     * Put every window that should float above the main one back on top.
     *
     * Called when the main window comes forward. Modal windows go last so they
     * end up above the rest: a message box buried under a palette looks like the
     * application has stopped responding, because every click is going to its
     * grab and nothing visible answers.
     *
     * Raising a window that is already on top produces no exposure, so the
     * Expose this causes doesn't come back round again.
     */
    public function raiseFloatingWindows(): void
    {
        if ($this->conn === null) return;

        foreach ($this->childWindows as $window) {
            if ($window->alwaysOnTop && $window->isMapped()) $window->raise();
        }

        if ($this->fileDialogMapped) $this->raiseWindow($this->fileDialogWindowId);
        if ($this->dialogMapped)     $this->raiseWindow($this->dialogWindowId);
    }

    /** @return list<ChildWindow> */
    public function getChildWindows(): array
    {
        return $this->childWindows;
    }

    /** The child window with this X11 id, if it is one of ours. */
    public function findChildWindow(int $id): ?ChildWindow
    {
        if ($id === 0) return null;

        foreach ($this->childWindows as $window) {
            if ($window->id() === $id) return $window;
        }
        return null;
    }

    /** Actually ask the server for the window. */
    private function realiseWindow(ChildWindow $window): void
    {
        if ($this->conn === null || $window->exists()) return;

        $id = $this->allocateResourceId();
        $window->attach($id);

        // ButtonPress | ButtonRelease | PointerMotion | Button1Motion | Exposure
        $eventMask = 0x0004 | 0x0008 | 0x0040 | 0x0100 | 0x8000;

        $translucent = $this->argbVisual !== 0;
        $valueMask   = $translucent
            ? 0x0002 | 0x0008 | 0x0200 | 0x0800 | 0x2000
            : 0x0002 | 0x0200 | 0x0800;
        $values = $translucent
            ? pack('VVVVV', $this->windowBackgroundPixel(), 0, 1, $eventMask, $this->colormapId)
            : pack('VVV', $this->windowBackgroundPixel(), 1, $eventMask);

        // CreateWindow opcode 1. override-redirect so the window manager leaves
        // it alone and the toolkit owns the whole frame.
        $request  = pack('CCvVVssvvvvVV', 1, $this->depth, 8 + intdiv(strlen($values), 4),
                         $id, $this->root,
                         0, 0, max(1, $window->width()), max(1, $window->height()),
                         0, 1, $this->visual, $valueMask);
        $request .= $values;
        $this->conn->write($request);

        $window->renderer->init($this->conn, $id, $this->gcId, $this->gcState);
        $window->renderer->setTranslucent($translucent);
        $window->renderer->setFonts($this->fontId, $this->boldFontId);
        if ($this->fontMetrics !== null) {
            $window->renderer->setFontMetrics($this->fontMetrics);
        }

        $this->logger->debug('Application window created', ['id' => sprintf('0x%x', $id)]);
    }

    /** The allocate resource id. */
    private function allocateResourceId(): int
    {
        return $this->ridBase | ($this->nextResourceId++);
    }

    // ---- Primitives ChildWindow calls ------------------------------------

    /** Maps a child window at these root coordinates. Called by {@see ChildWindow::show()}. */
    public function windowShow(ChildWindow $window, int $x, int $y, int $width, int $height, bool $modal): void
    {
        if ($this->conn === null || !$window->exists()) return;

        // ConfigureWindow opcode 12: x | y | width | height
        $this->conn->write(pack('CCvVvvVVVV', 12, 0, 7, $window->id(), 0x000F, 0,
            $x, $y, max(1, $width), max(1, $height)));
        $this->conn->write(pack('CCvV', 8, 0, 2, $window->id()));                    // MapWindow
        $this->raiseWindow($window->id());

        if ($modal) {
            $this->grabPointerFor($window->id());
        }
    }

    /** Unmaps a child window, releasing its grab if it had one. */
    public function windowHide(ChildWindow $window, bool $modal): void
    {
        if ($this->conn === null || !$window->exists()) return;

        if ($modal) {
            $this->conn->write(pack('CCvV', 27, 0, 2, 0));   // UngrabPointer
        }
        $this->conn->write(pack('CCvV', 10, 0, 2, $window->id()));   // UnmapWindow
    }

    /** Moves a child window, in root coordinates. */
    public function windowMove(ChildWindow $window, int $x, int $y): void
    {
        if ($this->conn === null || !$window->exists()) return;

        $this->conn->write(pack('CCvVvvVV', 12, 0, 5, $window->id(), 0x0003, 0, $x, $y));
    }

    /** Resizes a child window and its root widget together, so the two cannot disagree. */
    public function windowResize(ChildWindow $window, int $width, int $height): void
    {
        if ($this->conn === null || !$window->exists()) return;

        $this->conn->write(pack('CCvVvvVV', 12, 0, 5, $window->id(), 0x000C, 0,
            max(1, $width), max(1, $height)));
    }

    /** Repaints one child window and nothing else. */
    public function windowRedraw(ChildWindow $window): void
    {
        if ($this->conn === null || !$window->exists()) return;

        $this->applyShape($window->id(), $window->shapeRects(), $window->width(), $window->height());

        // ClearArea opcode 61, then paint.
        $this->conn->write(pack('CCvVvvvv', 61, 0, 4, $window->id(), 0, 0, 0, 0));
        $this->widgetManager?->paintChildWindow($window);
        $window->renderer->flush();
    }

    /**
     * Route every pointer event to $windowId, in its coordinates.
     *
     * owner-events = False, so clicks outside the window arrive here too (with
     * coordinates outside its bounds) rather than reaching the application
     * behind — which is what makes a modal window modal.
     */
    private function grabPointerFor(int $windowId): void
    {
        $eventMask = 0x0004 | 0x0008 | 0x0040 | 0x0100;

        // GrabPointer returns a status reply; drop it so the shared FIFO stays paired.
        $this->expectReply(static function (string $raw): void { /* status: ignore */ });
        $this->conn->write(pack(
            'CCvVvCCVVV',
            26, 0, 6,
            $windowId,
            $eventMask,
            1, 1, 0, 0, 0,
        ));
    }
    /** The file dialog renderer. */
    public function getFileDialogRenderer(): ?Renderer { return $this->fileDialogRenderer; }
    /** Whether it is file dialog mapped. */
    public function isFileDialogMapped(): bool   { return $this->fileDialogMapped; }
    /** The file dialog X. */
    public function getFileDialogX(): int        { return $this->fileDialogX; }
    /** The file dialog Y. */
    public function getFileDialogY(): int        { return $this->fileDialogY; }

    /**
     * Size, position and map the file dialog window, grabbing the pointer the
     * way the message box does so the dialog is genuinely modal — the server
     * routes every pointer event to it, in its own coordinates.
     */
    public function showFileDialogWindow(int $x, int $y, int $width, int $height): void
    {
        // Geometry is recorded before the guard: where the client believes the
        // window is, is state — not a side effect of having a live socket.
        $this->fileDialogWidth  = max(1, $width);
        $this->fileDialogHeight = max(1, $height);
        $this->fileDialogX      = $x;
        $this->fileDialogY      = $y;
        $this->fileDialogMapped = true;

        if ($this->conn === null || $this->fileDialogWindowId === 0) return;

        // ConfigureWindow: x | y | width | height
        $this->conn->write(pack('CCvVvvVVVV', 12, 0, 7, $this->fileDialogWindowId, 0x000F, 0,
            $x, $y, $this->fileDialogWidth, $this->fileDialogHeight));
        $this->conn->write(pack('CCvV', 8, 0, 2, $this->fileDialogWindowId));           // MapWindow
        $this->raiseWindow($this->fileDialogWindowId);

        $eventMask = 0x0004 | 0x0008 | 0x0040 | 0x0100;
        $this->expectReply(static function (string $raw): void { /* status: ignore */ });
        $this->conn->write(pack(
            'CCvVvCCVVV',
            26, 0, 6,
            $this->fileDialogWindowId,
            $eventMask,
            1, 1, 0, 0, 0,
        ));
    }

    /** Unmaps the file picker and releases its pointer grab. */
    public function hideFileDialogWindow(): void
    {
        $this->fileDialogMapped = false;

        if ($this->conn === null || $this->fileDialogWindowId === 0) return;

        $this->conn->write(pack('CCvV', 27, 0, 2, 0));                          // UngrabPointer
        $this->conn->write(pack('CCvV', 10, 0, 2, $this->fileDialogWindowId));  // UnmapWindow
    }

    /** Move it anywhere on screen — it's a real window, not a panel. */
    public function moveFileDialogWindow(int $x, int $y): void
    {
        $this->fileDialogX = $x;
        $this->fileDialogY = $y;

        if ($this->conn === null || $this->fileDialogWindowId === 0) return;

        $this->conn->write(pack('CCvVvvVV', 12, 0, 5, $this->fileDialogWindowId, 0x0003, 0, $x, $y));
    }

    /** Re-size it, for a theme whose dialog metrics differ. */
    public function resizeFileDialogWindow(int $width, int $height): void
    {
        if ($width === $this->fileDialogWidth && $height === $this->fileDialogHeight) return;

        $this->fileDialogWidth  = max(1, $width);
        $this->fileDialogHeight = max(1, $height);

        if ($this->conn === null || $this->fileDialogWindowId === 0) return;

        $this->conn->write(pack('CCvVvvVV', 12, 0, 5, $this->fileDialogWindowId, 0x000C, 0,
            $this->fileDialogWidth, $this->fileDialogHeight));
    }

    /** Sets file dialog shape. */
    public function setFileDialogShape(array $rects): void
    {
        $this->applyShape($this->fileDialogWindowId, $rects,
            $this->fileDialogWidth, $this->fileDialogHeight);
    }

    /** Repaints the file picker, re-applying its shape in case the theme changed. */
    public function redrawFileDialog(): void
    {
        if ($this->conn === null || $this->fileDialogRenderer === null) return;
        if (!$this->fileDialogMapped) return;

        $this->setFileDialogShape(
            $this->widgetManager?->fileDialogShapeRects($this->fileDialogRenderer) ?? []
        );

        $this->conn->write(pack('CCvVvvvv', 61, 0, 4, $this->fileDialogWindowId, 0, 0, 0, 0));
        $this->widgetManager?->paintFileDialog($this->fileDialogRenderer);
        $this->fileDialogRenderer->flush();
    }

    /**
     * OpenFont (opcode 45). Caller picks the name (XLFD pattern or alias);
     * 'fixed' is the safest fallback as it's guaranteed to be present on
     * every X server.
     */
    private function openFont(string $name): void
    {
        $n   = strlen($name);
        $pad = (4 - ($n % 4)) % 4;
        $len = 3 + intdiv($n + $pad, 4);

        $this->conn->write(
            pack('CCvVvv', 45, 0, $len, $this->fontId, $n, 0) . $name . str_repeat("\x00", $pad),
        );
        $this->fontName = $name;
        $this->logger->debug('Font loaded', ['font' => $name]);
    }

    /**
     * Open the X11 'cursor' font — a special server-side font whose glyphs
     * are the standard cursor shapes (arrow, watch, crosshair, …). Always
     * present on every X server.
     */
    private function openCursorFont(): void
    {
        $name = 'cursor';
        $n    = strlen($name);
        $pad  = (4 - ($n % 4)) % 4;
        $len  = 3 + intdiv($n + $pad, 4);

        $this->conn->write(
            pack('CCvVvv', 45, 0, $len, $this->cursorFontId, $n, 0) . $name . str_repeat("\x00", $pad),
        );
    }

    /**
     * CreateGlyphCursor opcode 94: build a cursor from a glyph in the cursor
     * font. XC_watch = 150 (the iconic "wait clock"); X11 stores its mask
     * shape at the next-odd-indexed glyph (151). Foreground black, background
     * white — standard Win2k busy-cursor colours.
     */
    private function createBusyCursor(): void
    {
        $this->conn->write(pack(
            'CCvVVVvvvvvvvv',
            94, 0, 8,
            $this->busyCursorId,
            $this->cursorFontId,        // source-font
            $this->cursorFontId,        // mask-font (same)
            150, 151,                   // XC_watch + its mask
            0, 0, 0,                    // fg = black (16-bit channels)
            0xFFFF, 0xFFFF, 0xFFFF,     // bg = white
        ));
    }

    /**
     * Swap the main window's cursor between the wait-clock and the default.
     * Implemented via ChangeWindowAttributes (opcode 2) with the cursor bit
     * (0x4000). cursor = 0 (None) makes the window inherit its parent's
     * cursor, which for a top-level window is the root's default arrow.
     */
    public function setBusyCursor(bool $busy): void
    {
        if ($this->conn === null) return;

        $cursor = $busy ? $this->busyCursorId : 0;
        $this->conn->write(pack('CCvVVV', 2, 0, 4, $this->windowId, 0x4000, $cursor));
    }

    /**
     * Ask for the open font's metrics so text can be measured with real glyph
     * widths instead of a monospace guess.
     *
     * @param bool $repaintWhenLoaded Repaint once the reply lands. Needed after
     *        a live font swap: the metrics arrive asynchronously, so the paint
     *        that triggered the swap measured text with the *old* font.
     */
    private function queryFont(bool $repaintWhenLoaded = false): void
    {
        // QueryFont opcode 47.
        $this->expectReply(function (string $raw) use ($repaintWhenLoaded): void {
            if (strlen($raw) < 60) {
                $this->logger->warning('QueryFont reply too short — skipping');
                return;
            }
            $metrics = $this->parseQueryFontReply($raw);
            $this->fontMetrics = $metrics;
            $this->renderer->setFontMetrics($metrics);
            $this->dialogRenderer?->setFontMetrics($metrics);
            $this->fileDialogRenderer?->setFontMetrics($metrics);
            foreach ($this->childWindows as $child) $child->renderer->setFontMetrics($metrics);
            $this->logger->debug('Font metrics loaded', [
                'ascent'  => $metrics->ascent,
                'descent' => $metrics->descent,
                'glyphs'  => count($metrics->widths),
            ]);

            if ($repaintWhenLoaded) {
                $this->redraw();
                $this->redrawDialog();
            }
        });
        $this->conn->write(pack('CCvV', 47, 0, 2, $this->fontId));
    }

    /**
     * Creates the shared graphics context (CreateGC, opcode 55) that every renderer draws
     * through.
     */
    private function createGC(): void
    {
        $valueMask = 0x0004 | 0x0008 | 0x4000; // foreground | background | font

        $this->conn->write(
            pack('CCvVVV', 55, 0, 7, $this->gcId, $this->windowId, $valueMask)
            . pack('VVV', 0x000000, 0xFFFFFF, $this->fontId),
        );
        $this->logger->debug('Graphics context created', [
            'id' => sprintf('0x%x', $this->gcId),
        ]);
    }

    // -------------------------------------------------------------------------
    // Public state mutators (called by handlers)
    // -------------------------------------------------------------------------

    /** Updates the mouse. */
    public function updateMouse(int $x, int $y): void
    {
        $this->mouseX = $x;
        $this->mouseY = $y;
    }

    /** Updates the key. */
    public function updateKey(string $keyName, int $modifiers): void
    {
        $this->lastKey  = $keyName;
        $this->lastMods = $modifiers;
    }

    /** Updates the geometry. */
    public function updateGeometry(int $x, int $y, int $width, int $height): void
    {
        $this->windowX      = $x;
        $this->windowY      = $y;
        $this->windowWidth  = $width;
        $this->windowHeight = $height;
    }

    /** Updates the focus. */
    public function updateFocus(bool $focused): void
    {
        $this->focused = $focused;
    }

    // -------------------------------------------------------------------------
    // Drawing  (called by handlers)
    // -------------------------------------------------------------------------

    /** Send a ClearArea that erases the entire window. */
    public function clear(): void
    {
        if ($this->conn === null) return;
        $this->conn->write(pack('CCvVvvvv', 61, 0, 4, $this->windowId, 0, 0, 0, 0));
    }

    /**
     * Redraw only the status text overlay (no clear, no background shapes).
     * Resets the GC foreground to black first so colour changes by other draw
     * calls do not bleed into the text.
     */
    public function drawOverlay(): void
    {
        if ($this->conn === null || !$this->overlayVisible) return;

        $palette = $this->themes->palette();
        $this->renderer->setBackground(...$palette->face);
        $this->renderer->setForeground(...$palette->text);

        $x    = $this->overlayX;
        $line = $this->overlayY;
        $step = 16;

        $this->renderer->drawText("mouse  x={$this->mouseX}  y={$this->mouseY}", $x, $line);
        $line += $step;

        if ($this->lastKey !== '') {
            $mods = $this->formatMods($this->lastMods);
            $this->renderer->drawText("key    {$this->lastKey}{$mods}", $x, $line);
        }
        $line += $step;

        $this->renderer->drawText(
            "size   {$this->windowWidth}x{$this->windowHeight}  pos={$this->windowX},{$this->windowY}",
            $x, $line,
        );
        $line += $step;

        $this->renderer->drawText('focus  ' . ($this->focused ? 'yes' : 'no'), $x, $line);
    }

    /** Draw the overlay and paint all widgets on top. */
    public function redrawOverlay(): void
    {
        $this->drawOverlay();
        $this->widgetManager?->paintAll();
        $this->renderer->flush();
    }

    /** Clear the window and repaint everything (overlay + widgets). */
    /**
     * Repaint only $region of the main window.
     *
     * For a change that cannot affect anything outside a known rectangle — a
     * list scrolling under its own scrollbar being the case that prompted this.
     * A plain {@see redraw()} clears the whole window and repaints every widget
     * *and* every open child window, which during a thumb drag happens on each
     * motion event: the tree, the group boxes, the status bar and any open form
     * all flash for a change confined to one list.
     *
     * Child windows are deliberately left alone — nothing inside this window's
     * rectangle can have changed them.
     *
     * $subtree narrows the *work* as well as the visible effect. Without it the
     * whole tree is walked and the clip merely discards what falls outside,
     * which fixes the flashing but still sends every request — around 26 kB per
     * motion event during a drag. With it only that subtree is painted, which is
     * sound when the subtree covers the region it was given: a list fills its own
     * bounds, so the cleared pixels are all its own. The overlay passes still
     * run, so an open popup over the region survives.
     */
    public function redrawRegion(Rect $region, ?Widget $subtree = null): void
    {
        if ($this->conn === null || $region->isEmpty()) return;

        // Which window the widget is painted into, since a region is in *that*
        // window's coordinates. A handler asks for "repaint this widget" without
        // knowing whether it is in a form of its own; painting it here regardless
        // drew the change into the main window at the same offset.
        $surface = $this->surfaceFor($subtree);
        if ($surface === null) return;          // its window isn't on screen
        [$windowId, $renderer] = $surface;

        // ClearArea (61) bounded to the region, rather than the whole window.
        $this->conn->write(pack('CCvVvvvv', 61, 0, 4, $windowId,
            $region->x, $region->y, $region->width, $region->height));

        $renderer->setClipRect($region);
        try {
            if ($subtree !== null) {
                $this->widgetManager?->paintSubtree($subtree, $renderer);
            } else {
                $this->widgetManager?->paintAll();
            }
        } finally {
            // Whatever happens, the GC must not be left clipped — every later
            // draw shares it, and the GC itself is shared between windows.
            $renderer->setClipRect(null);
            $renderer->flush();
        }
    }

    /**
     * Is this widget painted into a window of its own rather than the main one?
     *
     * A caller aiming a repaint at it must then name it (or its root) as the
     * subtree, because the region it hands over is in *that* window's
     * coordinates — see {@see redrawRegion()}.
     */
    public function paintsInOwnWindow(Widget $widget): bool
    {
        $root = $widget->rootWidget();

        foreach ($this->childWindows as $window) {
            if ($window->root() === $root) return true;
        }

        return $this->fileDialogRenderer !== null
            && $root === $this->widgetManager?->fileDialogRoot();
    }

    /**
     * The window a widget's subtree is painted into, and the renderer for it.
     *
     * Null means there is nothing to paint: the widget belongs to a window that
     * isn't mapped. A subtree of null is the main window, which is what a
     * whole-tree region repaint wants.
     *
     * @return array{int, Renderer}|null
     */
    private function surfaceFor(?Widget $subtree): ?array
    {
        if ($subtree === null) return [$this->windowId, $this->renderer];

        $root = $subtree->rootWidget();

        foreach ($this->childWindows as $window) {
            if ($window->root() !== $root) continue;

            return $window->isMapped() && $window->exists()
                ? [$window->id(), $window->renderer]
                : null;
        }

        if ($this->fileDialogRenderer !== null && $root === $this->widgetManager?->fileDialogRoot()) {
            return $this->fileDialogMapped
                ? [$this->fileDialogWindowId, $this->fileDialogRenderer]
                : null;
        }

        return [$this->windowId, $this->renderer];
    }

    /**
     * Clears and repaints the whole main window, then refreshes every open child window. The
     * blunt instrument; {@see redrawRegion()} is the narrow one.
     */
    public function redraw(): void
    {
        $this->clear();
        $this->redrawOverlay();

        // Handlers say "redraw" without knowing which window their widget lives
        // in, and while the file dialog is up most of them are acting on its
        // widgets. Refreshing it here is what lets every existing handler work
        // inside the dialog without being taught about a second window.
        if ($this->fileDialogMapped) {
            $this->redrawFileDialog();
        }

        foreach ($this->childWindows as $window) {
            if ($window->isMapped()) $window->redraw();
        }
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    /** A modifier mask as readable names, for the debug overlay and the log. */
    private function formatMods(int $state): string
    {
        $parts = [];
        if ($state & 0x0001) $parts[] = 'Shift';
        if ($state & 0x0004) $parts[] = 'Ctrl';
        if ($state & 0x0008) $parts[] = 'Alt';
        if ($state & 0x0040) $parts[] = 'Meta';

        return $parts !== [] ? ' +' . implode('+', $parts) : '';
    }

    /** The socket failed. Logs it and stops. */
    private function onError(\Throwable $error): void
    {
        $this->logger->error('Connection error', ['message' => $error->getMessage()]);
    }

    /** The server closed the connection. */
    private function onClose(): void
    {
        $this->logger->info('Connection closed');
    }
}
