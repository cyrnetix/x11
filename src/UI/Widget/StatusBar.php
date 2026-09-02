<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * Win2k-style status bar. Sticks to the bottom of a window, hosts a row of
 * StatusBarPane cells, optionally with a resize grip in the bottom-right
 * corner. Anchoring to the window bottom is the caller's job — the
 * MainWindow's resize listener moves the bar's $y and updates its width.
 */
final class StatusBar extends Widget
{
    /** @var list<StatusBarPane> */
    private array $panes = [];

    /** Takes position and size. */
    public function __construct(
        int $x, int $y,
        public int $width,
        public bool $showGrip = true,
    ) {
        parent::__construct($x, $y);
    }

    /** Adds a pane. */
    public function addPane(StatusBarPane $pane): self
    {
        $this->panes[] = $pane;
        return $this;
    }

    /** @return list<StatusBarPane> */
    public function getPanes(): array { return $this->panes; }

    /** The pane. */
    public function getPane(int $idx): ?StatusBarPane
    {
        return $this->panes[$idx] ?? null;
    }

    /** Sets pane text. */
    public function setPaneText(int $idx, string $text): void
    {
        $this->panes[$idx]?->setText($text);
    }

    /** Sets size. */
    public function setSize(int $width): void
    {
        $this->width = max(0, $width);
    }

    /**
     * Resolve each pane's pixel width. Panes with width=0 share the remaining
     * space — only the first one stretches; any others get DEFAULT_WIDTH.
     *
     * @return list<int>
     */
    public function computePaneWidths(): array
    {
        $widths       = [];
        $fixedSum     = 0;
        $stretchIdx   = -1;
        $m            = $this->metrics();
        $gripReserved = $this->showGrip ? $m->statusBarGripWidth : 0;
        $available    = max(0, $this->width - $gripReserved);

        foreach ($this->panes as $i => $pane) {
            if ($pane->width === 0 && $stretchIdx === -1) {
                $stretchIdx = $i;
                $widths[$i] = 0;     // placeholder — filled below
            } else {
                $widths[$i] = $pane->width > 0 ? $pane->width : $m->statusBarPaneDefaultWidth;
                $fixedSum += $widths[$i];
            }
        }

        if ($stretchIdx !== -1) {
            $widths[$stretchIdx] = max(20, $available - $fixedSum);
        }

        return $widths;
    }
}
