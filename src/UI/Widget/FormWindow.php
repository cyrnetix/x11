<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Closure;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\CaptionButton;
use Cyrnetix\X11\Theme\CaptionLayout;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Surface;

/**
 * Root widget for a window of the application's own: a form, a dialog, a panel.
 *
 * Pair it with {@see \Cyrnetix\X11\Client\X11Client::createChildWindow()} and it
 * is a real top-level window with a themed caption and border, built out of
 * ordinary widgets. Add children to it and they lay out inside its content box;
 * every existing handler and painter applies to them unchanged.
 *
 * **Coordinates are local to the window.** This widget sits at (0, 0) of its own
 * window and children resolve inside that, which is exactly what the X server
 * delivers for events on it.
 *
 * The caption geometry lives here because three windows needed the same
 * formulas: on a full-width theme the caption sits *inside* the body's border so
 * the frame runs around it, and on a tab-caption theme (BeOS) it is only as wide
 * as its title, sits flush in the corner, overlaps the body by a row, and the
 * strip beside it is cut out of the window. All of them take the tab's width
 * from {@see \Cyrnetix\X11\Theme\Metrics::captionTabWidth()}, so a window and a
 * dialog can't disagree about where a tab ends.
 */
class FormWindow extends Widget
{
    protected bool $visible = false;

    /** Caption button state, so the press reads as a press. */
    private ?CaptionButton $pressedButton = null;
    private ?CaptionButton $hoveredButton = null;

    /** Called when the caption's close box is used. */
    private ?Closure $onCaptionClose = null;

    /** Takes size and its title. */
    public function __construct(
        public int              $width,
        public int              $height,
        protected string        $title = '',
    ) {
        parent::__construct(0, 0);
    }

    /** The title. */
    public function getTitle(): string          { return $this->title; }
    /** Sets title. */
    public function setTitle(string $t): void   { $this->title = $t; }
    /** Whether it is visible. */
    public function isVisible(): bool           { return $this->visible; }

    /** Sets visible. */
    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }

    /**
     * Take the size of the window we're drawn into. Called by
     * {@see \Cyrnetix\X11\Client\ChildWindow} on show and resize, so the two can't
     * disagree.
     */
    public function setSize(int $width, int $height): void
    {
        $this->width  = max(1, $width);
        $this->height = max(1, $height);

        $this->x = $this->relX = 0;
        $this->y = $this->relY = 0;

        $this->relayout();
    }

    /**
     * Hidden means unreachable, not just unpainted: the subtree stays in the
     * main widget tree so handlers can find it, so leaving it visible would let
     * a click in another window match a widget here at the same offset.
     */
    public function getVisibleChildren(): array
    {
        return $this->visible ? $this->children : [];
    }

    /** The child surface. */
    public function childSurface(): ?Surface { return Surface::Face; }

    /** The content offset X. */
    public function contentOffsetX(): int
    {
        return $this->metrics()->edge + $this->contentPadding();
    }

    /** The content offset Y. */
    public function contentOffsetY(): int
    {
        return $this->captionBottom() + $this->contentPadding();
    }

    /** Inset between the border and the content. Override to change it. */
    protected function contentPadding(): int
    {
        return 8;
    }

    /** Caption height: a tab-caption theme uses its own, others the dialog's. */
    protected function captionH(): int
    {
        $m = $this->metrics();

        return $m->captionFitsTitle ? $m->captionHeight : $m->dialogTitleHeight;
    }

    /**
     * Where the body starts. A tab caption overlaps it by a row so the tab
     * covers the body's top border where they meet.
     */
    protected function bodyTop(): int
    {
        return $this->metrics()->captionFitsTitle ? max(0, $this->captionH() - 1) : 0;
    }

    /** First row below the caption that content may use. */
    protected function captionBottom(): int
    {
        $m = $this->metrics();

        return $m->captionFitsTitle
            ? $this->bodyTop() + $m->edge
            : $m->edge + $this->captionH();
    }

    /**
     * Where the caption sits: inset inside the border on a full-width theme, flush in the
     * corner and only as wide as its title on a tab-caption one.
     */
    public function captionRect(Renderer $r): Rect
    {
        $m      = $this->metrics();
        $wanted = $m->captionTabWidth($r->measureText($this->title), 0);

        if ($wanted === 0) {
            return Rect::of($m->edge, $m->edge, max(0, $this->width - 2 * $m->edge), $this->captionH());
        }

        return Rect::of(0, 0, min($this->width, max(40, $wanted)), $this->captionH());
    }

    /** The part of the window the border frames. */
    public function bodyRect(): Rect
    {
        $top = $this->bodyTop();

        return Rect::of(0, $top, $this->width, max(0, $this->height - $top));
    }

    /** The outer rect. */
    public function outerRect(): Rect
    {
        return Rect::of(0, 0, $this->width, $this->height);
    }

    /** The strip a tab-style caption leaves beside itself, if any. */
    public function captionSurroundRect(Renderer $r): Rect
    {
        if (!$this->metrics()->captionFitsTitle) return Rect::of(0, 0, 0, 0);

        $caption = $this->captionRect($r);
        if ($caption->right() >= $this->width - 1) return Rect::of(0, 0, 0, 0);

        return Rect::of(
            $caption->right() + 1,
            0,
            $this->width - $caption->right() - 1,
            max(0, $caption->height - 1),
        );
    }

    /**
     * Rectangles the window occupies, for the SHAPE extension. Empty means the
     * whole rectangle. {@see \Cyrnetix\X11\Client\ChildWindow::shapeRects()} calls
     * this by convention.
     *
     * @return list<array{int, int, int, int}>
     */
    public function shapeRects(Renderer $r): array
    {
        if ($this->captionSurroundRect($r)->isEmpty()) return [];

        $caption = $this->captionRect($r);
        $body    = $this->bodyRect();

        return [
            [$caption->x, $caption->y, $caption->width, $caption->height],
            [$body->x, $body->y, $body->width, $body->height],
        ];
    }

    /**
     * The caption's buttons, placed exactly as the window frame's are.
     *
     * A dialog's set comes from {@see Metrics::$dialogCaptionLeading} and
     * {@see Metrics::$dialogCaptionTrailing} rather than the window's: it draws
     * the same caption but cannot be minimised or maximised, so offering those
     * would be two buttons that do nothing. A theme whose era gave a dialog no
     * widgets returns none, and the title then has the whole bar.
     *
     * @return list<array{CaptionButton, Rect}>
     */
    public function captionButtons(Renderer $r): array
    {
        $m = $this->metrics();

        return CaptionLayout::buttons(
            $m,
            $this->captionRect($r),
            $m->dialogCaptionLeading,
            $m->dialogCaptionTrailing,
        );
    }

    /** Space left for the title, between whichever button groups exist. */
    public function captionTitleRect(Renderer $r): Rect
    {
        return CaptionLayout::titleRect(
            $this->metrics(),
            $this->captionRect($r),
            $this->captionButtons($r),
        );
    }

    /** The pressed button. */
    public function getPressedButton(): ?CaptionButton { return $this->pressedButton; }
    /** The hovered button. */
    public function getHoveredButton(): ?CaptionButton { return $this->hoveredButton; }

    /** Sets pressed button. */
    public function setPressedButton(?CaptionButton $button): void { $this->pressedButton = $button; }
    /** Sets hovered button. */
    public function setHoveredButton(?CaptionButton $button): void { $this->hoveredButton = $button; }

    /** Which button is under (mx, my), if any. */
    public function hitTestCaptionButton(int $mx, int $my, Renderer $r): ?CaptionButton
    {
        if (!$this->visible) return null;

        foreach ($this->captionButtons($r) as [$button, $rect]) {
            if ($rect->contains($mx, $my)) return $button;
        }

        return null;
    }

    /**
     * What the caption's close box does.
     *
     * A hook rather than tearing the window down, for the same reason the main
     * window's close is a hook: a form may want to ask something first, and only
     * the application knows. A form that already has a Close *button* should
     * point this at the same closure, so there is one way out and not two that
     * can disagree.
     */
    public function setOnCaptionClose(?Closure $cb): void { $this->onCaptionClose = $cb; }

    /** Runs whatever the caption's close box was pointed at. Does not hide the window itself. */
    public function requestClose(): void
    {
        if ($this->onCaptionClose !== null) ($this->onCaptionClose)();
    }

    /**
     * Does (mx, my) fall on the caption? That's what starts a drag.
     *
     * The buttons are *not* excluded here: the handler tries them first, and a
     * caption that stopped being draggable wherever a button sits would be a
     * worse answer than the order of two checks.
     */
    public function hitTestCaption(int $mx, int $my, Renderer $r): bool
    {
        return $this->visible && $this->captionRect($r)->contains($mx, $my);
    }

    /** {@inheritDoc} */
    public function relayout(): void
    {
        foreach ($this->children as $child) {
            $this->resolveCoords($child);
        }
    }
}
