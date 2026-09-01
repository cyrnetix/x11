<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\ThemeManager;

/**
 * Base widget node. Stores its position both relative to its parent's content
 * area (relX/relY — preserved across re-parenting) and absolute on the window
 * (x/y — resolved at addChild time so painters can use it directly).
 *
 * Container behaviour is entirely opt-in: containers override contentOffsetX/Y
 * to declare where their child coordinate space starts, and getVisibleChildren
 * to filter which children get walked (e.g. only the active tab page).
 *
 * Widgets also inherit the live {@see ThemeManager} down the tree — the same
 * way they inherit coordinates — so {@see metrics()} always reports the sizes
 * the active theme wants. Hit-testing reads those metrics, and so do the
 * painters, which is why the two can't disagree after a theme switch.
 */
class Widget
{
    public int $x;
    public int $y;
    public int $relX;
    public int $relY;
    public ?Widget $parent = null;

    /** @var list<Widget> */
    protected array $children = [];

    /** Live theme source, inherited from the parent; null until attached. */
    protected ?ThemeManager $themes = null;

    /** Takes position. */
    public function __construct(int $x = 0, int $y = 0)
    {
        $this->x    = $x;
        $this->y    = $y;
        $this->relX = $x;
        $this->relY = $y;
    }

    /** Adds a child. */
    public function addChild(Widget $child): static
    {
        $child->parent = $this;
        $this->resolveCoords($child);
        $this->children[] = $child;
        // Late arrivals inherit the theme immediately, so a widget added after
        // the tree is live still lays itself out with the right metrics.
        if ($this->themes !== null) {
            $child->attachThemes($this->themes);
        }
        return $this;
    }

    /**
     * Hand this widget and everything it owns the live theme source.
     *
     * Called by {@see \Cyrnetix\X11\UI\WidgetTree::addChild()} when a root is
     * attached, by {@see addChild()} for later additions, and again on every
     * theme switch. Children are reached first so a parent's {@see relayout()}
     * can position children that already know their own metrics.
     */
    public function attachThemes(ThemeManager $themes): void
    {
        $this->themes = $themes;

        foreach ($this->children as $child) {
            $child->attachThemes($themes);
        }
        foreach ($this->ownedWidgets() as $owned) {
            $owned->attachThemes($themes);
        }

        $this->relayout();
    }

    /**
     * Widgets this one owns that aren't in $children — popup lists that only
     * appear in {@see getOverlayChildren()} while open. Override so they get
     * the theme even while hidden.
     *
     * @return list<Widget>
     */
    protected function ownedWidgets(): array { return []; }

    /** Measurements of the live theme; toolkit defaults before attach. */
    public function metrics(): Metrics
    {
        return $this->themes?->metrics() ?? Metrics::defaults();
    }

    /**
     * Drawing primitives of the live theme, or null before attach.
     *
     * Nullable where {@see metrics()} has a default, because there is no
     * theme-neutral Chrome to fall back on — a caller has to decide what to do
     * without one.
     */
    public function chrome(): ?Chrome
    {
        return $this->themes?->chrome();
    }

    /**
     * The surface this widget's *children* are drawn on top of, or null to pass
     * the parent's along. Containers that fill themselves with something other
     * than the plain face override it — a {@see TabPage} sits on a panel.
     */
    public function childSurface(): ?Surface { return null; }

    /**
     * The surface this widget itself sits on, found by walking up the tree.
     *
     * Painters need it because X11's ImageText8 fills the glyph cell: a label on
     * a Mac OS 9 tab page has to paint #EEEEEE behind its text, not the #DDDDDD
     * window face, or it draws a visible box around itself.
     */
    public function surface(): Surface
    {
        for ($parent = $this->parent; $parent !== null; $parent = $parent->parent) {
            $surface = $parent->childSurface();
            if ($surface !== null) {
                return $surface;
            }
        }
        return Surface::Face;
    }

    /**
     * The top of this widget's subtree — a tree root.
     *
     * Which root a widget belongs to is which *window* it is painted into, so
     * anything aiming a repaint at a widget has to ask this first. Its
     * coordinates are that window's, and painting it into the main window would
     * draw the change at the same offset in the wrong place.
     */
    public function rootWidget(): self
    {
        $node = $this;
        while ($node->parent !== null) {
            $node = $node->parent;
        }

        return $node;
    }

    /**
     * Recompute internal geometry that depends on theme metrics — embedded
     * scrollbar bounds, natural widths, popup sizes. Called after attach and
     * after every theme switch; the default is a no-op because most widgets
     * derive their layout at paint time.
     */
    public function relayout(): void {}

    /** @return list<Widget> */
    public function getChildren(): array { return $this->children; }

    /** Children currently visible. Override for tab-like switches. */
    public function getVisibleChildren(): array { return $this->children; }

    /**
     * Children that should paint as overlays AFTER the entire main tree
     * walk — popup widgets that need to draw on top of any sibling that
     * would otherwise occlude them (open dropdown popup overlapping a
     * row of controls below). Default is empty; popup-owning widgets
     * (DropDown, ComboBox, …) override to return their popup subtree
     * while open.
     *
     * Hit-tests visit overlay children first so the popup captures
     * clicks before the main tree does.
     */
    public function getOverlayChildren(): array { return []; }

    /** Offset added to children's (relX, relY) within this widget's own space. */
    public function contentOffsetX(): int { return 0; }
    /** The content offset Y. */
    public function contentOffsetY(): int { return 0; }

    /**
     * Re-resolve this widget's own absolute coords after its relative ones
     * moved, cascading into its children.
     *
     * {@see addChild()} is the usual resolver, but a widget that moves *after*
     * attach — a dialog being centred, or dragged — has no way back into it
     * without this. Computing absolutes by hand at the call site is the thing
     * that goes stale after a theme switch.
     */
    public function reresolve(): void
    {
        $parent = $this->parent;

        if ($parent === null) {
            $this->x = $this->relX;
            $this->y = $this->relY;
        } else {
            $this->x = $parent->x + $parent->contentOffsetX() + $this->relX;
            $this->y = $parent->y + $parent->contentOffsetY() + $this->relY;
        }

        foreach ($this->children as $child) {
            $this->resolveCoords($child);
        }
    }

    /** Resolves the coords. */
    protected function resolveCoords(Widget $child): void
    {
        $child->x = $this->x + $this->contentOffsetX() + $child->relX;
        $child->y = $this->y + $this->contentOffsetY() + $child->relY;
        foreach ($child->getChildren() as $g) {
            $child->resolveCoords($g);
        }
    }
}
