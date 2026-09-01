<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\UI\Painter\DateTimePickerPainter;
use Cyrnetix\X11\UI\Widget\DateTimePicker;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * DateTimePicker — three responsibilities, all here:
 *  - field clicks (arrow toggles popup; segment click sets active segment)
 *  - modal-when-popup-open click handling (nav buttons get the
 *    press/release split for visible feedback; day cells fire on press;
 *    anything else dismisses)
 *  - keyboard nav (segments + step + digit entry when closed; day +
 *    week + month walk + Enter/Esc when open)
 */
final class DateTimePickerHandler extends WidgetHandler
{
    /** Captured during a nav-button press until the matching release. */
    private ?DateTimePicker $captured = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree            $tree,
        private readonly X11Client             $client,
        private readonly Renderer              $renderer,
        private readonly DateTimePickerPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof DateTimePicker) return false;
        $this->painter->paintField($w, $r);
        return true;
    }

    /** The calendar belongs to the widget that opened it. */
    public function overlayAnchor(): ?Widget { return $this->findOpen(); }

    /** Paints the overlay. */
    public function paintOverlay(Renderer $r): void
    {
        $open = $this->findOpen();
        if ($open !== null) $this->painter->paintCalendar($open, $r);
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        // Modal-when-open: ANY click while a calendar is up captures here.
        $open = $this->findOpen();
        if ($open !== null) {
            $this->handleOpenClick($open, $event->x, $event->y);
            return true;
        }

        $dtp = $this->findFieldHit($event->x, $event->y);
        if ($dtp === null) return false;

        if ($dtp->hitTestButton($event->x, $event->y)) {
            $dtp->isOpen() ? $dtp->close() : $dtp->open();
        } else {
            $seg = $dtp->hitTestSegmentInField($event->x, $event->y, $this->renderer);
            if ($seg !== -1) $dtp->setActiveSegment($seg);
        }
        $this->client->redraw();
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;
        $dtp = $this->captured;
        $nav = $dtp->getPressedNav();
        $dtp->setPressedNav(null);
        $this->captured = null;

        $stillOver = ($nav === 'prev' && $dtp->hitTestCalendarPrev($event->x, $event->y))
                  || ($nav === 'next' && $dtp->hitTestCalendarNext($event->x, $event->y));
        if ($stillOver) {
            $nav === 'prev' ? $dtp->prevMonth() : $dtp->nextMonth();
        }
        $this->client->redraw();
        return true;
    }

    /**
     * Handles key if it belongs to this widget kind. True means the event was claimed and no later
     * handler sees it.
     */
    public function tryKey(string $key): bool
    {
        $dtp = $this->tree->getFocused();
        if (!$dtp instanceof DateTimePicker) return false;

        if ($dtp->isOpen()) {
            switch ($key) {
                case 'Up':    $dtp->setValue($dtp->getValue()->modify('-7 days')); break;
                case 'Down':  $dtp->setValue($dtp->getValue()->modify('+7 days')); break;
                case 'Left':  $dtp->setValue($dtp->getValue()->modify('-1 day'));  break;
                case 'Right': $dtp->setValue($dtp->getValue()->modify('+1 day'));  break;
                case 'PgUp':  $dtp->prevMonth();                                   break;
                case 'PgDn':  $dtp->nextMonth();                                   break;
                case 'Enter':
                case ' ':
                case 'Esc':   $dtp->close();                                       break;
                default:      return false;
            }
            $this->client->redraw();
            return true;
        }

        switch ($key) {
            case 'Left':  $dtp->prevSegment(); break;
            case 'Right': $dtp->nextSegment(); break;
            case 'Up':    $dtp->step(+1);      break;
            case 'Down':  $dtp->step(-1);      break;
            case 'Enter':
            case ' ':     $dtp->open();        break;
            case 'PgUp':  $dtp->setValue($dtp->getValue()->modify('-1 month')); break;
            case 'PgDn':  $dtp->setValue($dtp->getValue()->modify('+1 month')); break;
            case 'Esc':   return false;
            default:
                if (strlen($key) === 1 && ctype_digit($key)) {
                    $dtp->typeDigit($key);
                    break;
                }
                return false;
        }
        $this->client->redraw();
        return true;
    }

    /** A click while the calendar is open: navigate, pick a day, or dismiss it. */
    private function handleOpenClick(DateTimePicker $dtp, int $mx, int $my): void
    {
        // Nav buttons use press/release for feedback — press marks pressed,
        // release fires the action if the cursor is still over the button.
        if ($dtp->hitTestCalendarPrev($mx, $my)) {
            $dtp->setPressedNav('prev');
            $this->captured = $dtp;
            $this->client->redraw();
            return;
        }
        if ($dtp->hitTestCalendarNext($mx, $my)) {
            $dtp->setPressedNav('next');
            $this->captured = $dtp;
            $this->client->redraw();
            return;
        }

        $day = $dtp->hitTestCalendarDay($mx, $my);
        if ($day !== null) {
            $dtp->setValue($day);
            $dtp->close();
            $this->client->redraw();
            return;
        }

        $dtp->close();
        $this->client->redraw();
    }

    /** The picker whose field is under the pointer, if any. */
    private function findFieldHit(int $mx, int $my): ?DateTimePicker
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof DateTimePicker && $w->hitTestField($mx, $my)
        );
        return $found instanceof DateTimePicker ? $found : null;
    }

    /** The find open. */
    public function findOpen(): ?DateTimePicker
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof DateTimePicker && $w->isOpen()
        );
        return $found instanceof DateTimePicker ? $found : null;
    }
}
