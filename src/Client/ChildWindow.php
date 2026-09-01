<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Client;

use Closure;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Widget\Widget;

/**
 * A top-level auxiliary window belonging to the application: a form, a dialog,
 * a palette.
 *
 * This is the general facility the message box and the file dialog were once
 * two hand-rolled copies of. An application asks {@see X11Client::createWindow()}
 * for one, hands it either a widget subtree or a paint callback, and gets back a
 * handle it can show, move, resize and redraw.
 *
 * **Coordinates inside are local to this window**, starting at (0, 0) — which is
 * what the X server delivers for events on it, so a widget subtree rooted here
 * needs no translation. `show()` positions the window in *root* (screen)
 * coordinates.
 *
 * **Modality is the server's job.** A modal window grabs the pointer when shown,
 * so every pointer event is routed here in these coordinates. Pair that with
 * {@see \Cyrnetix\X11\UI\WidgetTree::setModal()} so hit-testing agrees: without
 * it, a click at (40, 30) in this window can match a main-window widget that
 * happens to sit at (40, 30) there.
 *
 * The window is created `override-redirect`, so the window manager leaves it
 * alone entirely and the toolkit draws the whole frame — the same as the two
 * built-in dialogs, and what makes a themed caption possible.
 */
final class ChildWindow
{
    /** 0 until the X11 window exists, which is at connection setup. */
    private int $id = 0;

    private bool $mapped = false;

    private int $x = 0;
    private int $y = 0;

    /** Takes the X11 client, a renderer and size. */
    public function __construct(
        private readonly X11Client $client,
        public readonly Renderer   $renderer,
        private int                $width,
        private int                $height,
        /**
         * Grab the pointer while mapped, so nothing else can be clicked.
         * True for a dialog; false for a palette or an inspector.
         */
        public readonly bool       $modal = true,
        /**
         * Widget subtree painted into this window. Its coordinates are local to
         * the window, so it belongs at (0, 0).
         */
        private ?Widget            $root = null,
        /**
         * Alternative to $root for a window drawn by hand rather than assembled
         * from widgets — how {@see \Cyrnetix\X11\UI\Widget\MessageBox} works.
         *
         * @var Closure(Renderer): void|null
         */
        private ?Closure           $painter = null,
        /**
         * Stay above the main window.
         *
         * The window is override-redirect, so the window manager never stacks
         * it: raising the main window — a click, an alt-tab — buries it, and
         * nothing puts it back. With this the client re-raises it whenever the
         * main window comes forward. Right for a palette you compare against
         * the application, and for a modal dialog, where being buried looks
         * like the application has hung.
         */
        public readonly bool       $alwaysOnTop = false,
    ) {}

    /** Put this window back above the main one. */
    public function raise(): void
    {
        $this->client->raiseWindow($this->id);
    }

    /** Called by {@see X11Client} once the server has given us the window. */
    public function attach(int $id): void
    {
        $this->id = $id;
    }

    /** The id. */
    public function id(): int         { return $this->id; }
    /** Whether it exists. */
    public function exists(): bool    { return $this->id !== 0; }
    /** Whether it is mapped. */
    public function isMapped(): bool  { return $this->mapped; }
    /** The x. */
    public function x(): int          { return $this->x; }
    /** The y. */
    public function y(): int          { return $this->y; }
    /** The width. */
    public function width(): int      { return $this->width; }
    /** The height. */
    public function height(): int     { return $this->height; }
    /** The root. */
    public function root(): ?Widget   { return $this->root; }
    /** The painter. */
    public function painter(): ?Closure { return $this->painter; }

    /** Sets root. */
    public function setRoot(?Widget $root): void
    {
        $this->root = $root;
    }

    /**
     * Position, size, map and raise. Coordinates are root-relative, so centre
     * over the main window with {@see X11Client::getWindowX()} and friends.
     */
    public function show(int $x, int $y, ?int $width = null, ?int $height = null): void
    {
        $this->x      = $x;
        $this->y      = $y;
        $this->width  = max(1, $width  ?? $this->width);
        $this->height = max(1, $height ?? $this->height);
        $this->mapped = true;

        // Size the widget subtree with the window, so a root that lays itself
        // out from its own extent (as a dialog does) is right before first paint.
        $this->sizeRoot();

        $this->client->windowShow($this, $this->x, $this->y, $this->width, $this->height, $this->modal);
    }

    /** Takes the window off screen. Its widgets stay in the tree but stop being reachable. */
    public function hide(): void
    {
        $this->mapped = false;
        $this->client->windowHide($this, $this->modal);
    }

    /** Move on screen. A real window, so nothing confines it to the parent. */
    public function moveTo(int $x, int $y): void
    {
        $this->x = $x;
        $this->y = $y;
        $this->client->windowMove($this, $x, $y);
    }

    /** Resize, e.g. because a theme switch changed what the contents need. */
    public function resize(int $width, int $height): void
    {
        $width  = max(1, $width);
        $height = max(1, $height);
        if ($width === $this->width && $height === $this->height) return;

        $this->width  = $width;
        $this->height = $height;
        $this->sizeRoot();

        $this->client->windowResize($this, $width, $height);
    }

    /**
     * Clear and repaint. Shape is re-applied first, since a themed caption can
     * change which part of the window is even there.
     */
    public function redraw(): void
    {
        if (!$this->mapped) return;

        $this->client->windowRedraw($this);
    }

    /**
     * Rectangles this window actually occupies, for the SHAPE extension. Empty
     * means the whole rectangle.
     *
     * Taken from the root widget when it offers `shapeRects(Renderer)` — the
     * convention {@see \Cyrnetix\X11\UI\Widget\FileDialog} and `MessageBox`
     * already follow — so a theme whose caption is a partial-width tab gets the
     * strip beside it cut away without the application arranging anything.
     *
     * @return list<array{int, int, int, int}>
     */
    public function shapeRects(): array
    {
        $root = $this->root;
        if ($root === null || !method_exists($root, 'shapeRects')) return [];

        /** @var list<array{int, int, int, int}> $rects */
        $rects = $root->shapeRects($this->renderer);

        return $rects;
    }

    /** Keep a root that takes its extent from the window in step with it. */
    private function sizeRoot(): void
    {
        if ($this->root !== null && method_exists($this->root, 'setSize')) {
            $this->root->setSize($this->width, $this->height);
        }
    }
}
