<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\UI\Painter\RadioButtonPainter;
use Cyrnetix\X11\UI\Widget\RadioButton;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/** Paints radio buttons and, on click, selects one and clears the rest of its group. */
final class RadioButtonHandler extends WidgetHandler
{
    private ?RadioButton $captured = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree         $tree,
        private readonly X11Client          $client,
        private readonly Renderer           $renderer,
        private readonly LoggerInterface    $logger,
        private readonly RadioButtonPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof RadioButton) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $rb = $this->findHit($event->x, $event->y);
        if ($rb === null) return false;
        $this->captured = $rb;
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->captured === null) return false;
        $captured       = $this->captured;
        $this->captured = null;

        if ($captured->hitTest($event->x, $event->y, $this->renderer)) {
            $captured->setChecked(true);
            $this->logger->debug('Radio selected', ['label' => $captured->label]);
            $this->client->redraw();
        }
        return true;
    }

    /** The widget of this kind under the pointer, if any. */
    private function findHit(int $mx, int $my): ?RadioButton
    {
        $renderer = $this->renderer;
        $found = $this->tree->findFirst(
            static fn(Widget $w): bool => $w instanceof RadioButton && $w->hitTest($mx, $my, $renderer)
        );
        return $found instanceof RadioButton ? $found : null;
    }
}
