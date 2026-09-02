<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * X11 gives no clip rectangle on a per-string basis, so anything that can
 * overflow its cell (list values, tree labels, window titles) trims itself
 * first. Shared so every caller cuts text the same way.
 */
final class TextClip
{
    /** Longest prefix of $text whose rendered width fits in $maxWidth pixels. */
    public static function toWidth(string $text, int $maxWidth, Renderer $r): string
    {
        if ($maxWidth <= 0) return '';
        if ($r->measureText($text) <= $maxWidth) return $text;

        $end = strlen($text);
        while ($end > 0 && $r->measureText(substr($text, 0, $end)) > $maxWidth) {
            $end--;
        }
        return substr($text, 0, $end);
    }
}
