<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * An etched dividing line — the static control every dialog of this era used to
 * separate a form from its buttons.
 *
 * Windows called it `SS_ETCHEDHORZ`, Motif a `XmSeparator`; the toolkit already
 * knew how to draw one ({@see \Cyrnetix\X11\Theme\Chrome::separator()}) for menus
 * and rebars, and this makes it available to a layout.
 *
 * Only the length is given: the thickness is the theme's business, since an
 * etched line is two rows in Windows and one in Mac OS.
 */
final class Separator extends Widget
{
    /** Takes position and size. */
    public function __construct(
        int $x, int $y,
        public int $length,
        public readonly ScrollOrientation $orientation = ScrollOrientation::Horizontal,
    ) {
        parent::__construct($x, $y);
    }

    /** Whether it is horizontal. */
    public function isHorizontal(): bool
    {
        return $this->orientation === ScrollOrientation::Horizontal;
    }

    /** Two rows for an etched line, which is what a bevel needs. */
    public function thickness(): int
    {
        return $this->metrics()->thinEdge * 2;
    }
}
