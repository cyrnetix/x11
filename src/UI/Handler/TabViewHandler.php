<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\UI\Painter\TabViewPainter;
use Cyrnetix\X11\UI\Widget\TabView;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Paints a tab view and switches pages when a tab is clicked.
 *
 * It holds a `Renderer` because a theme may size tabs to their labels, so
 * hit-testing a tab strip needs to measure text — the same reason
 * {@see \Cyrnetix\X11\UI\Widget\WindowFrame} does.
 */
final class TabViewHandler extends WidgetHandler
{
    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree      $tree,
        private readonly X11Client       $client,
        private readonly LoggerInterface $logger,
        /** Tabs are sized to their labels, so hit-testing needs to measure text. */
        private readonly Renderer        $renderer,
        private readonly TabViewPainter  $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof TabView) return false;
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $hit = $this->findHit($event->x, $event->y);
        if ($hit === null) return false;

        [$tabView, $tabIdx] = $hit;
        if ($tabIdx !== $tabView->getActiveIndex()) {
            $tabView->setActiveIndex($tabIdx);
            $this->logger->debug('Tab selected', ['index' => $tabIdx]);
            $this->client->redraw();
        }
        return true;
    }

    /** @return array{TabView, int}|null */
    private function findHit(int $mx, int $my): ?array
    {
        $found = null;
        $this->tree->visitAll(function (Widget $w) use ($mx, $my, &$found): void {
            if ($found !== null) return;
            if ($w instanceof TabView) {
                $idx = $w->hitTestTab($mx, $my, $this->renderer);
                if ($idx !== -1) {
                    $found = [$w, $idx];
                }
            }
        });
        return $found;
    }
}
