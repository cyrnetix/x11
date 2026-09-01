<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\UI\Widget\Bounded;
use Cyrnetix\X11\UI\Widget\Widget;

/**
 * Per-widget interaction + paint handler. WidgetManager owns an ordered
 * list of these and dispatches every event by walking the list and asking
 * each handler "did you consume this?" — returning true stops the walk.
 *
 * Default implementations are no-ops so display-only handlers (Label,
 * GroupBox, StatusBar) only need to override paint(), and capture-style
 * handlers (Button, Checkbox, …) only override what they actually use.
 *
 * Returning true from paint() also stops the walk, so each widget is
 * painted exactly once.
 */
abstract class WidgetHandler
{
    /**
     * Repaint just what changed.
     *
     * The three tiers, cheapest first — and each is a correctness statement, not
     * only an optimisation:
     *
     * 1. **Bounded and opaque**: clear the widget's rectangle and repaint that
     *    widget alone. Sound because it fills every pixel it was given.
     * 2. **Bounded but not opaque**: clear the rectangle and repaint the whole
     *    tree, clipped to it. The container's surface is redrawn first, so a
     *    label's glyphs land on the panel they actually sit on rather than the
     *    window's background colour. Same work as before, but no full-window
     *    flash.
     * 3. **Not bounded**: the old whole-window repaint. A widget that can't say
     *    where it is — or one whose change overflows it, like a menu opening —
     *    gets the blunt instrument.
     *
     * Pass several widgets and the region is their union, for a change that
     * moved more than one (a hover leaving one button and entering another).
     *
     * @param Widget|list<Widget> $changed
     */
    protected function repaint(X11Client $client, Widget|array $changed): void
    {
        $widgets = is_array($changed) ? array_values($changed) : [$changed];
        if ($widgets === []) return;

        $region = Rect::of(0, 0, 0, 0);
        $opaque = true;

        // Grouped by root, because a root is a *window*: widgets in two different
        // windows share no coordinate space, so their regions can't be unioned
        // and neither can be painted on the other's surface.
        /** @var array<int, array{root: Widget, region: Rect}> $byWindow */
        $byWindow = [];

        foreach ($widgets as $widget) {
            if (!$widget instanceof Bounded) {
                $client->redraw();
                return;
            }

            $region = $region->union($widget->bounds());
            $opaque = $opaque && $widget->paintsOwnBackground();

            $root = $widget->rootWidget();
            $key  = spl_object_id($root);

            $byWindow[$key] ??= ['root' => $root, 'region' => Rect::of(0, 0, 0, 0)];
            $byWindow[$key]['region'] = $byWindow[$key]['region']->union($widget->bounds());
        }

        if ($region->isEmpty()) {
            $client->redraw();
            return;
        }

        // A focus ring or a pressed shadow can sit a pixel or two outside the
        // widget's own rectangle, so give each region a little room.
        if ($opaque) {
            // One repaint each, rather than one covering their union: the gap
            // between two distant widgets belongs to whatever is underneath, and
            // clearing it without repainting that would leave it blank.
            foreach ($widgets as $widget) {
                $client->redrawRegion($widget->bounds()->grow(2), $widget);
            }
            return;
        }

        // Something here draws on the surface beneath it, so the tree paints —
        // clipped to everything that changed, one window at a time. In a window
        // of its own that means *that* window's tree: the region is in its
        // coordinates, and the main window would paint something else entirely
        // at the same offset.
        foreach ($byWindow as ['root' => $root, 'region' => $damage]) {
            $client->redrawRegion(
                $damage->grow(2),
                $client->paintsInOwnWindow($root) ? $root : null,
            );
        }
    }

    /** @return true if the press was consumed (router stops the walk). */
    public function tryPress(X11ButtonPressEvent $event): bool { return false; }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(X11ButtonReleaseEvent $event): bool { return false; }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(X11MotionEvent $event): bool { return false; }

    /**
     * Handle a key for the currently-focused widget. Implementations
     * typically check $this->tree->getFocused() against their own widget
     * type and return false when the focused widget isn't theirs.
     */
    public function tryKey(string $key): bool { return false; }

    /** Paint $w if it's of this handler's widget kind. */
    public function paint(Widget $w, Renderer $r): bool { return false; }

    /**
     * Post-tree overlay paint — popups (open dropdown, calendar, menu
     * chain) draw here so they overflow their parent's bounds. Called
     * after paint() has been dispatched to every widget in the tree.
     */
    public function paintOverlay(Renderer $r): void {}

    /**
     * The widget this handler's overlay hangs off, or null when nothing is open.
     *
     * A handler-owned popup isn't in the widget tree, so the paint walk has no
     * way to tell which *window* it belongs to — and it gets one walk per window.
     * An open menu was being drawn into every child window at its main-window
     * coordinates until this existed. Any handler that overrides
     * {@see paintOverlay()} owes an answer here; the default is "nothing open",
     * which is also the safe answer for an overlay that belongs everywhere.
     */
    public function overlayAnchor(): ?Widget { return null; }
}
