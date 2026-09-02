<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * Win95-style group box (a.k.a. Frame in VB6). Doesn't know what kinds of
 * children it holds — addChild() comes from Widget — it only declares where
 * the child coordinate space starts via contentOffsetX/Y.
 */
final class GroupBox extends Widget
{
    /** @param array{int, int, int}|null $background null = the theme's face colour. */
    public function __construct(
        public readonly string $title,
        int $x, int $y,
        public int             $width,
        public int             $height,
        public readonly ?array $background = null,
    ) {
        parent::__construct($x, $y);
    }

    /**
     * Move and resize. Needed by any layout that reflows — the frame's own
     * layout pass positions children by relX/relY and then re-resolves, so a
     * container whose bounds were fixed at construction couldn't follow.
     */
    public function setBounds(int $relX, int $relY, int $width, int $height): void
    {
        $this->relX   = $relX;
        $this->relY   = $relY;
        $this->width  = max(0, $width);
        $this->height = max(0, $height);

        $this->reresolve();
    }

    /** The content offset X. */
    public function contentOffsetX(): int { return $this->metrics()->groupBoxPaddingX; }
    /** The content offset Y. */
    public function contentOffsetY(): int { return $this->metrics()->groupBoxPaddingTop; }
}
