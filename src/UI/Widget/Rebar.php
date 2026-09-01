<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Theme\Surface;

/**
 * Win32-style rebar — a multi-row container of {@see RebarBand}s. Each
 * band wraps one child widget (Toolbar, TextBox, …) with an optional
 * grip chevron + caption label on its left. Bands stack horizontally
 * within a row; rows stack vertically.
 *
 *   ╔══════════════════════════════════════════════════════════════╗
 *   ║ ┊ [tb tb tb tb] ┊ Address: [textbox..........] ┊ Find: [tb] ║  ← row 0
 *   ║ ┊ [tb tb tb tb tb]                                          ║  ← row 1
 *   ╚══════════════════════════════════════════════════════════════╝
 *
 * Layout policy:
 *   - fillWidth bands share the leftover row width equally.
 *   - non-fill bands keep their constructor-time width.
 *   - the child widget's own width property is overridden ONLY when the
 *     band is fillWidth (so an address TextBox actually stretches).
 *
 * Children are real Widget instances added via addChild so their input
 * flows through the normal handler dispatch — Rebar is just layout.
 */
final class Rebar extends Widget
{
    /** @var list<list<RebarBand>> rows of bands; rows[r][b] = band */
    private array $rows = [[]];

    public int $height = 0;

    /** Takes position and size. */
    public function __construct(
        int        $x,
        int        $y,
        public int $width,
    ) {
        parent::__construct($x, $y);
    }

    /** Band children sit on the rebar strip, not on the window face. */
    public function childSurface(): ?Surface { return Surface::Bar; }

    /** Resize the strip and reflow its rows — bands re-share the new width. */
    public function setSize(int $width): void
    {
        $this->width = max(0, $width);
        $this->layout();
    }

    /** Append a band to the current (last) row. */
    public function addBand(RebarBand $band): self
    {
        if ($band->child->parent !== $this) {
            $this->addChild($band->child);
        }
        $this->rows[count($this->rows) - 1][] = $band;
        $this->layout();
        return $this;
    }

    /** Start a new row — subsequent addBand() calls land in this row. */
    public function newRow(): self
    {
        $this->rows[] = [];
        return $this;
    }

    /** @return list<list<RebarBand>> */
    public function getRows(): array { return $this->rows; }

    /**
     * Recompute pixel positions for every band + child. Called after
     * each addBand() / newRow(); the resulting absolute coords feed the
     * painter + hit-testing.
     */
    public function layout(): void
    {
        $rowPad = $this->metrics()->rebarRowPad;

        $y = $rowPad;
        foreach ($this->rows as $row) {
            $h = $this->layoutRow($row, $y);
            if ($h > 0) $y += $h + $rowPad;
        }
        $this->height = $y;
    }

    /** Band geometry depends on grip + caption metrics, so a switch reflows. */
    public function relayout(): void
    {
        $this->layout();
    }

    /**
     * Width a band occupies: its explicit width, or — when constructed with
     * `width: 0` — just enough to hug the grip, caption and child. Hugging
     * keeps app code out of the metrics business.
     */
    private function bandWidth(RebarBand $band, int $perFill): int
    {
        if ($band->fillWidth) return $perFill;
        if ($band->width > 0) return $band->width;

        return $this->bandHeaderWidth($band) + ($band->child->width ?? 0);
    }

    /** Grip + caption gutter to the left of a band's child. */
    private function bandHeaderWidth(RebarBand $band): int
    {
        $m = $this->metrics();

        return ($band->showGrip ? $m->rebarGripWidth : 0)
             + ($band->captionWidth > 0 ? $band->captionWidth + $m->rebarCaptionGap : 0);
    }

    /**
     * Layout one row, returning its computed height. Updates the
     * band-side bookkeeping (x/y/w/h) and the child widget's relX/relY
     * (+ width for fill bands), then re-resolves the child's absolute
     * coordinates so its descendants get the right positions too.
     */
    private function layoutRow(array $row, int $rowY): int
    {
        if ($row === []) return 0;

        $m = $this->metrics();

        // Sum fixed widths + count fill bands.
        $fixed = 0;
        $fills = 0;
        foreach ($row as $band) {
            if ($band->fillWidth) {
                $fills++;
            } else {
                $fixed += $this->bandWidth($band, 0);
            }
        }
        $fixed += $m->rebarBandGap * (count($row) - 1);

        $available = max(0, $this->width - $fixed);
        $perFill   = $fills > 0 ? intdiv($available, $fills) : 0;

        $x        = 0;
        $rowH     = 0;
        foreach ($row as $band) {
            $bandW    = $this->bandWidth($band, $perFill);
            $headerW  = $this->bandHeaderWidth($band);
            $childW   = max(0, $bandW - $headerW);

            $band->x = $x;
            $band->y = $rowY;
            $band->w = $bandW;

            // Snap the child into the band's content area. Setting relX/Y
            // then calling resolveCoords cascades absolute coords to any
            // grand-children (e.g. ListBox's embedded scrollbar).
            $band->child->relX = $x + $headerW;
            $band->child->relY = $rowY;
            if ($band->fillWidth) {
                // Stretch the child to fill the band. Most widgets carry
                // their width as a public int; ones that auto-compute it
                // (Toolbar) shouldn't be in fill bands.
                $band->child->width = $childW;
            }
            $this->resolveCoords($band->child);

            $childH   = $band->child->height ?? 0;
            $band->h  = max($childH, 22);
            $rowH     = max($rowH, $band->h);

            $x += $bandW + $m->rebarBandGap;
        }
        return $rowH;
    }

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->height;
    }
}
