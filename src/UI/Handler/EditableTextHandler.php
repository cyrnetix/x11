<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Widget\EditableText;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Caret + drag-to-select for any EditableText widget (TextBox, ComboBox
 * text area). Driven from the focus block in WidgetManager: after focus
 * is set, this handler runs and — if the focused widget is EditableText —
 * positions the caret and captures the drag. Motion extends the
 * selection until release.
 *
 * Per-key editing (typed characters, arrow caret movement, …) stays on
 * the widget itself via Focusable::handleKey — only the mouse-driven
 * selection lives here.
 */
final class EditableTextHandler extends WidgetHandler
{
    private ?EditableText $dragWidget = null;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree $tree,
        private readonly X11Client  $client,
        private readonly Renderer   $renderer,
    ) {}

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        // WidgetManager has already set focus to whichever Focusable was
        // hit; we only care when that Focusable is an EditableText.
        $focused = $this->tree->getFocused();
        if (!$focused instanceof EditableText) return false;
        if (!$focused->hitTestForFocus($event->x, $event->y)) return false;

        $pos    = $focused->pixelToCursor($event->x, $this->renderer);
        $extend = ($event->state & 0x0001) !== 0;
        $focused->selectCursor($pos, $extend);
        $this->dragWidget = $focused;
        $this->client->redraw();
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool
    {
        if ($this->dragWidget === null) return false;
        $pos = $this->dragWidget->pixelToCursor($event->x, $this->renderer);
        $this->dragWidget->selectCursor($pos, extend: true);
        $this->client->redraw();
        return true;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool
    {
        if ($this->dragWidget === null) return false;
        $this->dragWidget = null;
        return true;
    }
}
