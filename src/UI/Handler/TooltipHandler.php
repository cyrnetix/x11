<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use React\EventLoop\TimerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Widget\Tooltipped;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Global tooltip popup. Runs FIRST in the handler list so it observes
 * every motion + press regardless of which downstream handler consumes
 * them; returns false in every try* so dispatch continues to the real
 * widget handlers.
 *
 * State machine:
 *   - On motion: find the topmost {@see Tooltipped} widget under the
 *     cursor + its tooltip text. Text differs from current → cancel any
 *     pending timer, hide current tooltip, schedule a fresh dwell timer
 *     remembering THIS cursor position. Text matches → no-op (tooltip
 *     stays anchored to its original location). Text is null → hide.
 *   - On timer fire: flip visible + redraw.
 *   - On press: hide + cancel.
 *
 * Win convention: tooltip appears below + right of the cursor (+14, +18).
 * Clipped to the window so it doesn't drift off the right or bottom edge.
 *
 * The body and text come from the active theme; only the dwell timing and the
 * anchor arithmetic live here.
 */
final class TooltipHandler extends WidgetHandler
{
    private const DWELL_SECONDS = 0.5;
    private const OFFSET_X      = 14;
    private const OFFSET_Y      = 18;

    private ?string         $text     = null;
    /** The widget the current tooltip belongs to — which window it paints in. */
    private ?Widget         $owner    = null;
    private int             $anchorX  = 0;
    private int             $anchorY  = 0;
    private bool            $visible  = false;
    private ?TimerInterface $timer    = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree $tree,
        private readonly X11Client  $client,
    ) {}

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        [$text, $owner] = $this->findTooltip($event->x, $event->y);

        if ($text === null) {
            $this->hide();
            return false;
        }
        if ($text === $this->text) {
            // Same item / same text — don't re-anchor or restart the timer.
            return false;
        }

        // New tooltip — drop the old + start fresh.
        $this->hide();
        $this->text    = $text;
        $this->owner   = $owner;
        $this->anchorX = $event->x;
        $this->anchorY = $event->y;
        $this->timer   = $this->client->getLoop()->addTimer(
            self::DWELL_SECONDS,
            function (): void { $this->show(); }
        );
        return false;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $this->hide();
        return false;
    }

    /** Paints the overlay. */
    public function paintOverlay(Renderer $r): void
    {
        if (!$this->visible || $this->text === null) return;

        $chrome = $this->tree->themes()->chrome();
        $m      = $this->tree->themes()->metrics();

        $textW = $r->measureText($this->text);
        $w     = $textW + 2 * $m->tooltipPadX;
        $h     = $r->fontAscent() + $r->fontDescent() + 2 * $m->tooltipPadY;

        // Clip to window: if the tooltip would extend past the right or
        // bottom edge, shift it back inside.
        $winW = $this->client->getWindowWidth();
        $winH = $this->client->getWindowHeight();
        $tx   = min($this->anchorX + self::OFFSET_X, max(0, $winW - $w - 1));
        $ty   = min($this->anchorY + self::OFFSET_Y, max(0, $winH - $h - 1));

        $box = Rect::of($tx, $ty, $w, $h);
        $chrome->tooltip($r, $box);
        $chrome->text(
            $r,
            $this->text,
            $box->x + $m->tooltipPadX,
            $r->baselineYForRect($box->y, $box->height),
            TextStyle::Tooltip,
        );
    }

    /** Shows the tooltip the hover timer was waiting on. */
    private function show(): void
    {
        $this->timer   = null;
        $this->visible = true;
        $this->client->redraw();
    }

    /** Hides the tooltip and cancels any pending timer. */
    private function hide(): void
    {
        if ($this->timer !== null) {
            $this->client->getLoop()->cancelTimer($this->timer);
            $this->timer = null;
        }
        if ($this->visible) {
            $this->visible = false;
            $this->text    = null;
            $this->owner   = null;
            $this->client->redraw();
        } else {
            $this->text = null;
        }
    }

    /**
     * Walk the tree (overlay subtrees first via findFirst) looking for a
     * Tooltipped widget whose getTooltipAt returns non-null. The first
     * such widget wins; null means "no tooltip anywhere".
     */
    /**
     * The tooltip under the cursor and the widget it belongs to.
     *
     * The widget matters as much as the text: it says which window the tip has
     * to be painted into, since a hovered control in a form of its own is at
     * coordinates that also exist in the main window.
     *
     * @return array{?string, ?Widget}
     */
    private function findTooltip(int $mx, int $my): array
    {
        $text  = null;
        $owner = $this->tree->findFirst(static function (Widget $w) use ($mx, $my, &$text): bool {
            if (!$w instanceof Tooltipped) return false;
            $t = $w->getTooltipAt($mx, $my);
            if ($t === null) return false;
            $text = $t;
            return true;
        });

        return [$text, $owner];
    }

    /** {@inheritDoc} */
    public function overlayAnchor(): ?Widget
    {
        return $this->visible ? $this->owner : null;
    }
}
