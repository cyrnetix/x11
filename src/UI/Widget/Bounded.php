<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;

/**
 * A widget that can say where it is, so a change to it can be repainted without
 * clearing and redrawing the whole window.
 *
 * Not on {@see Widget} itself, and deliberately: a base-class default would
 * have to be an empty rectangle, and an empty region means *nothing repaints*.
 * A widget that hasn't opted in would silently stop updating. As an interface,
 * the worst case is that a handler falls back to the full repaint it did before.
 *
 * {@see paintsOwnBackground()} is the second half of the contract, and it
 * decides how much work the repaint can skip:
 *
 * - **true** — the widget fills every pixel of its own rectangle (a button's
 *   bevel, a text field's well, a list's frame). The region can be cleared and
 *   only this widget repainted.
 * - **false** — the widget draws *onto* whatever is behind it and leaves gaps: a
 *   checkbox's label is glyphs on the surface, with the surface showing between
 *   the strokes. Repainting it alone over a cleared region would put it on the
 *   window's background colour instead of the panel it actually sits on, so the
 *   whole tree is repainted, clipped to the region. Still no full-window flash,
 *   just more work.
 */
interface Bounded
{
    /** The bounds. */
    public function bounds(): Rect;

    /** Whether this widget fills its own rectangle, so a repaint of it alone is safe. */
    public function paintsOwnBackground(): bool;
}
