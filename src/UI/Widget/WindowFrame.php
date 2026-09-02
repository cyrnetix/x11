<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\CaptionLayout;

/**
 * The window's own frame: a themed caption plus a resize border, drawn by the
 * toolkit instead of by the window manager.
 *
 * It's a plain container, so the caption's height comes out of the client area
 * through {@see contentOffsetY()} — children keep laying themselves out from
 * (0, 0) of the *content* area and a theme with a taller caption just works.
 *
 * With $drawsChrome = false the frame collapses to nothing: no insets, no
 * painting, no hit targets. That's the mode to use when the window manager is
 * still drawing its own decorations, and it keeps app layout code identical
 * either way.
 *
 * Geometry lives here so the painter and the hit-tests agree on where the
 * caption buttons are; which buttons exist, and at which end, is a theme
 * decision read from {@see \Cyrnetix\X11\Theme\Metrics}.
 */
final class WindowFrame extends Widget
{
    private bool $active        = true;
    private bool $maximized     = false;

    private ?CaptionButton $pressedButton = null;
    private ?CaptionButton $hoveredButton = null;

    /** Takes size and its title. */
    /**
     * @param bool $drawsChrome false hands the caption and border back to the
     *        window manager, and this widget then paints nothing and reports
     *        zero insets — `--decorations=wm`.
     * @param bool $resizable false removes the maximise button, the border drag
     *        and the caption's double-click-to-maximise, so a fixed-size window
     *        cannot be resized through the chrome the toolkit draws.
     *        Pair it with {@see \Cyrnetix\X11\Client\X11Client::setResizable()},
     *        which tells the *window manager* the same thing — either route can
     *        resize a window on its own.
     */
    public function __construct(
        public int $width,
        public int $height,
        private string $title = '',
        public readonly bool $drawsChrome = true,
        public readonly bool $resizable = true,
    ) {
        parent::__construct(0, 0);
    }

    // ---- State ----------------------------------------------------------

    /** The title. */
    public function getTitle(): string           { return $this->title; }
    /** Sets title. */
    public function setTitle(string $title): void { $this->title = $title; }

    /** Whether it is active. */
    public function isActive(): bool { return $this->active; }

    /** Returns true when the state actually changed, so callers redraw once. */
    public function setActive(bool $active): bool
    {
        if ($this->active === $active) return false;
        $this->active = $active;
        return true;
    }

    /** Whether it is maximized. */
    public function isMaximized(): bool { return $this->maximized; }

    /** Sets maximized. */
    public function setMaximized(bool $maximized): void { $this->maximized = $maximized; }

    /** The pressed button. */
    public function getPressedButton(): ?CaptionButton { return $this->pressedButton; }
    /** The hovered button. */
    public function getHoveredButton(): ?CaptionButton { return $this->hoveredButton; }

    /** Sets pressed button. */
    public function setPressedButton(?CaptionButton $button): void { $this->pressedButton = $button; }

    /** Returns true when the hover actually moved. */
    public function setHoveredButton(?CaptionButton $button): bool
    {
        if ($this->hoveredButton === $button) return false;
        $this->hoveredButton = $button;
        return true;
    }

    /** Sets size. */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(1, $width);
        $this->height = max(1, $height);
    }

    /**
     * A theme change can move the caption's height, which moves the whole client
     * area — so the children's absolute coordinates have to be resolved again.
     * Children have already taken the new metrics by the time this runs.
     */
    public function relayout(): void
    {
        foreach ($this->children as $child) {
            $this->resolveCoords($child);
        }
    }

    // ---- Container ------------------------------------------------------

    /** The content offset X. */
    public function contentOffsetX(): int
    {
        if (!$this->drawsChrome) return 0;

        $m = $this->metrics();
        return $m->windowBorder + $m->windowClientEdge;
    }

    /** The content offset Y. */
    public function contentOffsetY(): int
    {
        if (!$this->drawsChrome) return 0;

        $m = $this->metrics();

        // Full-width caption: border, then the caption, then the content.
        // Tab caption: the tab *is* the top edge and overlaps the body's border
        // by a row, so the border comes after the caption rather than before it.
        $aboveContent = $m->captionFitsTitle
            ? max(0, $m->captionHeight - 1) + $m->windowBorder
            : $m->windowBorder + $m->captionHeight;

        return $aboveContent + $m->windowClientEdge;
    }

    /** Client area left for the app's widgets. */
    public function contentRect(): Rect
    {
        if (!$this->drawsChrome) {
            return Rect::of(0, 0, $this->width, $this->height);
        }

        $m    = $this->metrics();
        $edge = $m->windowClientEdge;
        $top  = $this->contentOffsetY();

        return Rect::of(
            $m->windowBorder + $edge,
            $top,
            max(0, $this->width  - 2 * ($m->windowBorder + $edge)),
            max(0, $this->height - $top - $m->windowBorder - $edge),
        );
    }

    /** @return array{int, int} [width, height] of the client area */
    public function contentSize(): array
    {
        $content = $this->contentRect();
        return [$content->width, $content->height];
    }

    // ---- Frame geometry -------------------------------------------------

    /** The outer rect. */
    public function outerRect(): Rect
    {
        return Rect::of(0, 0, $this->width, $this->height);
    }

    /**
     * The caption bar. Normally the full width between the borders, but a theme
     * can ask for one only as wide as its own furniture — BeOS's tab — in which
     * case the title has to be measured, hence the Renderer.
     */
    public function captionRect(Renderer $r): Rect
    {
        if (!$this->drawsChrome) return Rect::of(0, 0, 0, 0);

        $m    = $this->metrics();
        $full = Rect::of(
            $m->windowBorder,
            $m->windowBorder,
            max(0, $this->width - 2 * $m->windowBorder),
            $m->captionHeight,
        );

        $wanted = $m->captionTabWidth(
            $r->measureText($this->title),
            count($m->captionLeading) + count($m->captionTrailing),
        );
        if ($wanted === 0) return $full;

        // A tab is part of the window's outline rather than something inside the
        // border, so it starts flush at the top-left corner.
        return Rect::of(0, 0, min($this->width, max(40, $wanted)), $m->captionHeight);
    }

    /**
     * The window's body — everything the border frames.
     *
     * With a full-width caption that's the whole window. With a tab it starts one
     * row *inside* the tab's bottom edge, so the tab overlaps the body's top
     * border exactly the way BeOS draws it, and no border is drawn above or
     * beside the tab.
     */
    public function bodyRect(Renderer $r): Rect
    {
        if (!$this->drawsChrome || !$this->metrics()->captionFitsTitle) {
            return $this->outerRect();
        }

        $top = max(0, $this->metrics()->captionHeight - 1);

        return Rect::of(0, $top, $this->width, max(0, $this->height - $top));
    }

    /**
     * The strip a partial-width caption leaves over, or an empty rect when the
     * caption spans the window. It reaches the window's edge rather than
     * stopping at the border, because a floating tab has the desktop behind it
     * right into the corner.
     */
    public function captionSurroundRect(Renderer $r): Rect
    {
        if (!$this->drawsChrome) return Rect::of(0, 0, 0, 0);

        $caption = $this->captionRect($r);
        if ($caption->isEmpty()) return Rect::of(0, 0, 0, 0);

        // Where a full-width caption would have ended.
        $bandRight = $this->width - $this->metrics()->windowBorder - 1;
        if ($caption->right() >= $bandRight) return Rect::of(0, 0, 0, 0);

        // Stops one row short of the caption's bottom: that last row belongs to
        // the body, which spans the full width underneath the tab.
        return Rect::of(
            $caption->right() + 1,
            0,
            max(0, $this->width - $caption->right() - 1),
            max(0, $caption->height - 1),
        );
    }

    /**
     * Caption buttons with their rectangles, laid out from both ends of the
     * bar. Consumed by the painter *and* by {@see hitTestCaptionButton()}.
     *
     * @return list<array{CaptionButton, Rect}>
     */
    public function captionButtons(Renderer $r): array
    {
        if (!$this->drawsChrome) return [];

        $m = $this->metrics();

        // A fixed-size window offers no maximise: the button would be there and
        // do nothing, which is worse than its absence. The rest of the caption
        // closes up around the gap, because CaptionLayout places what it is given
        // rather than reserving slots.
        $keep = fn(CaptionButton $b): bool
            => $this->resizable || !in_array($b, [CaptionButton::Maximize, CaptionButton::Restore], true);

        // Placed by CaptionLayout, which a dialog's caption also uses: the two
        // draw the same caption and used to place it differently.
        return CaptionLayout::buttons(
            $m,
            $this->captionRect($r),
            array_values(array_filter(array_map($this->resolve(...), $m->captionLeading), $keep)),
            array_values(array_filter(array_map($this->resolve(...), $m->captionTrailing), $keep)),
        );
    }

    /** Space left for the title, between the two button groups. */
    public function captionTitleRect(Renderer $r): Rect
    {
        return CaptionLayout::titleRect(
            $this->metrics(),
            $this->captionRect($r),
            $this->captionButtons($r),
        );
    }

    /**
     * The rectangles the window actually occupies, for the SHAPE extension.
     *
     * A full-width caption gives a plain rectangle, so this returns an empty
     * list meaning "nothing to cut away". A tab-style caption gives two: the tab
     * itself, and the body below it — which leaves the space beside the tab
     * genuinely outside the window rather than painted to look like it.
     *
     * @return list<array{int, int, int, int}> [x, y, width, height]
     */
    public function shapeRects(Renderer $r): array
    {
        if (!$this->drawsChrome) return [];

        $surround = $this->captionSurroundRect($r);
        if ($surround->isEmpty()) return [];

        $caption = $this->captionRect($r);
        $body    = $this->bodyRect($r);

        return [
            [$caption->x, $caption->y, $caption->width, $caption->height],
            [$body->x, $body->y, $body->width, $body->height],
        ];
    }

    // ---- Hit tests ------------------------------------------------------

    /** Which caption button is at these coordinates, if any. */
    public function hitTestCaptionButton(int $mx, int $my, Renderer $r): ?CaptionButton
    {
        foreach ($this->captionButtons($r) as [$button, $rect]) {
            if ($rect->contains($mx, $my)) return $button;
        }
        return null;
    }

    /** True for the draggable part of the caption (not its buttons). */
    public function hitTestCaption(int $mx, int $my, Renderer $r): bool
    {
        return $this->drawsChrome
            && $this->captionRect($r)->contains($mx, $my)
            && $this->hitTestCaptionButton($mx, $my, $r) === null;
    }

    /**
     * Which border region (mx, my) grabs, or null for the caption and client
     * area. Corners win over edges, and a theme can restrict resizing to the
     * corners alone.
     */
    public function hitTestResizeEdge(int $mx, int $my): ?ResizeEdge
    {
        if (!$this->drawsChrome || !$this->resizable) return null;

        $m = $this->metrics();
        $t = $m->windowBorder;
        if ($t <= 0) return null;

        $outer = $this->outerRect();
        if (!$outer->contains($mx, $my)) return null;

        // Only the border ring resizes; the caption and client area don't.
        $onLeft   = $mx < $t;
        $onRight  = $mx > $outer->right() - $t;
        $onTop    = $my < $t;
        $onBottom = $my > $outer->bottom() - $t;

        if (!$onLeft && !$onRight && !$onTop && !$onBottom) return null;

        // Within a corner box, the grab counts as diagonal even when the cursor
        // is on a straight edge — which is what makes corners easy to hit.
        $corner = max($t, $m->windowResizeCorner);
        $nearL  = $mx < $corner;
        $nearR  = $mx > $outer->right() - $corner;
        $nearT  = $my < $corner;
        $nearB  = $my > $outer->bottom() - $corner;

        $edge = match (true) {
            $nearT && $nearL => ResizeEdge::TopLeft,
            $nearT && $nearR => ResizeEdge::TopRight,
            $nearB && $nearL => ResizeEdge::BottomLeft,
            $nearB && $nearR => ResizeEdge::BottomRight,
            $onTop           => ResizeEdge::Top,
            $onBottom        => ResizeEdge::Bottom,
            $onLeft          => ResizeEdge::Left,
            default          => ResizeEdge::Right,
        };

        // A theme can keep resizing to the corners alone (Mac's grow box).
        if (!$m->windowResizeFromEdges) {
            return match ($edge) {
                ResizeEdge::TopLeft, ResizeEdge::TopRight,
                ResizeEdge::BottomLeft, ResizeEdge::BottomRight => $edge,
                default => null,
            };
        }

        return $edge;
    }

    /** Maximize or Restore, depending on the current window state. */
    private function resolve(CaptionButton $button): CaptionButton
    {
        if ($button === CaptionButton::Maximize && $this->maximized) {
            return CaptionButton::Restore;
        }
        return $button;
    }
}
