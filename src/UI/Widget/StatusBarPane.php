<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

/**
 * One cell in a status bar. Text is mutable; width can be a fixed pixel
 * count, or 0 to mean "stretch to fill the remaining space" (only the first
 * width=0 pane stretches — any others fall back to a default).
 */
final class StatusBarPane
{
    /** Takes its text and size. */
    public function __construct(
        public string $text,
        public readonly int $width = 0,
    ) {}

    /** Sets text. */
    public function setText(string $text): void
    {
        $this->text = $text;
    }
}
