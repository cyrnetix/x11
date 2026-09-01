<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\UI\Painter\ComboBoxPainter;
use Cyrnetix\X11\UI\Widget\ComboBox;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * ComboBox — paint the field + handle the arrow button (toggle popup)
 * and outside-click dismissal while open. Popup clicks are caught by
 * ListBoxHandler / ScrollBarHandler since the inner ListBox lives in
 * the widget's overlay layer. Text-area caret + drag stays with
 * EditableTextHandler.
 */
final class ComboBoxHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree      $tree,
        private readonly X11Client       $client,
        private readonly ComboBoxPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof ComboBox) return false;
        $this->painter->paintField($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        // Arrow button toggle.
        $cb = $this->findButtonHit($event->x, $event->y);
        if ($cb !== null) {
            $cb->isOpen() ? $cb->close() : $cb->open();
            $this->tree->setFocused($cb);
            $this->client->redraw();
            return true;
        }

        // Outside-click while a popup is open → dismiss.
        $open = $this->findOpen();
        if ($open !== null) {
            $open->close();
            $this->client->redraw();
            return true;
        }
        return false;
    }

    /** The combo box whose drop-down button is under the pointer, if any. */
    private function findButtonHit(int $mx, int $my): ?ComboBox
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof ComboBox && $w->hitTestButton($mx, $my)
        );
        return $found instanceof ComboBox ? $found : null;
    }

    /** The find open. */
    public function findOpen(): ?ComboBox
    {
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof ComboBox && $w->isOpen()
        );
        return $found instanceof ComboBox ? $found : null;
    }
}
