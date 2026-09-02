<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\UI\Painter\DropDownPainter;
use Cyrnetix\X11\UI\Widget\DropDown;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * DropDown — painting + field click + keyboard nav. The popup itself is
 * a real {@see \Cyrnetix\X11\UI\Widget\ListBox} that lives in the
 * widget's overlay-child layer, so {@see ListBoxHandler} and
 * {@see ScrollBarHandler} handle clicks INSIDE the popup transparently.
 * This handler only deals with:
 *   - paint the closed field
 *   - press on the field: open/close
 *   - press OUTSIDE any popup while open: dismiss
 *   - keyboard nav (Up/Down through items, Enter/Space toggle, Esc close)
 *
 * Required handler order: this runs AFTER ListBoxHandler /
 * ScrollBarHandler so inside-popup clicks reach those handlers first;
 * by the time tryPress runs here, anything that wasn't the field or an
 * outside-click is also not for this handler.
 */
final class DropDownHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree      $tree,
        private readonly X11Client       $client,
        private readonly DropDownPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof DropDown) return false;
        $this->painter->paintField($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        // Field click: toggle popup. Click on another dropdown's field
        // while one is open should swap which is open.
        $dd = $this->findFieldHit($event->x, $event->y);
        if ($dd !== null) {
            $open = $this->findOpen();
            if ($open !== null && $open !== $dd) {
                $open->close();
            }
            $dd->isOpen() ? $dd->close() : $dd->open();
            $this->client->redraw();
            return true;
        }

        // Outside-click while a popup is open → dismiss (modal behaviour).
        $open = $this->findOpen();
        if ($open !== null) {
            $open->close();
            $this->client->redraw();
            return true;
        }
        return false;
    }

    /**
     * Handles key if it belongs to this widget kind. True means the event was claimed and no later
     * handler sees it.
     */
    public function tryKey(string $key): bool
    {
        $dd = $this->tree->getFocused();
        if (!$dd instanceof DropDown) return false;

        $cnt = count($dd->getItems());
        if ($cnt === 0) return false;

        $sel = $dd->getSelectedIndex();
        $cur = $sel < 0 ? 0 : $sel;

        switch ($key) {
            case 'Up':
                $dd->setSelectedIndex(max(0, $cur - 1));
                if ($dd->isOpen()) $dd->getListBox()->ensureSelectedVisible();
                break;
            case 'Down':
                $dd->setSelectedIndex(min($cnt - 1, $cur + 1));
                if ($dd->isOpen()) $dd->getListBox()->ensureSelectedVisible();
                break;
            case 'Home':
                $dd->setSelectedIndex(0);
                if ($dd->isOpen()) $dd->getListBox()->ensureSelectedVisible();
                break;
            case 'End':
                $dd->setSelectedIndex($cnt - 1);
                if ($dd->isOpen()) $dd->getListBox()->ensureSelectedVisible();
                break;
            case 'Enter':
            case ' ':
                $dd->isOpen() ? $dd->close() : $dd->open();
                break;
            case 'Esc':
                if (!$dd->isOpen()) return false;
                $dd->close();
                break;
            default:
                return false;
        }
        $this->client->redraw();
        return true;
    }

    /** The drop-down whose closed field is under the pointer, if any. */
    private function findFieldHit(int $mx, int $my): ?DropDown
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof DropDown && $w->hitTestField($mx, $my)
        );
        return $found instanceof DropDown ? $found : null;
    }

    /** The find open. */
    public function findOpen(): ?DropDown
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof DropDown && $w->isOpen()
        );
        return $found instanceof DropDown ? $found : null;
    }
}
