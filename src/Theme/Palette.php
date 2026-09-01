<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme;

/**
 * Every colour a widget can ask for, by *role* rather than by name — the
 * painters never mention "white" or "#D4D0C8", they ask for `content` or
 * `face` and the active theme answers.
 *
 * Each value is an `[r, g, b]` triple so it can be splatted straight into
 * {@see \Cyrnetix\X11\Drawing\Renderer::setForeground()}.
 *
 * The defaults spell out the Windows 9x/2000 system palette, which keeps
 * `new Palette()` meaningful and lets a theme override only what it changes
 * (see {@see Win9x\Win9xTheme}, {@see Platinum\PlatinumTheme}, {@see Cde\CdeTheme}).
 */
final class Palette
{
    /**
     * @param array{int,int,int} $face                  Control/dialog background (3DFACE).
     * @param array{int,int,int} $faceHighlight         Brightest bevel edge (3DHIGHLIGHT).
     * @param array{int,int,int} $faceLight             Second-brightest bevel edge (3DLIGHT).
     * @param array{int,int,int} $faceShadow            Inner shadow edge (3DSHADOW).
     * @param array{int,int,int} $faceDarkShadow        Outer shadow edge (3DDKSHADOW).
     * @param array{int,int,int} $text                  Text on $face.
     * @param array{int,int,int} $textDisabled          Greyed-out text.
     * @param array{int,int,int} $textEmboss            Engrave highlight behind disabled text.
     * @param array{int,int,int} $textDim               Dimmed-but-enabled text.
     * @param array{int,int,int} $content               Editable/scrollable content background.
     * @param array{int,int,int} $contentText           Text on $content.
     * @param array{int,int,int} $selection             Selection highlight fill.
     * @param array{int,int,int} $selectionText         Text inside a selection.
     * @param array{int,int,int}|null $desktop          Shows beside a partial-width caption; null = $face.
     * @param array{int,int,int}|null $panel            Tab page interior; null = $face.
     * @param array{int,int,int} $bar                   Status bar / toolbar strip / rebar.
     * @param array{int,int,int}|null $menuBar          The menu strip; null = $bar.
     * @param array{int,int,int} $menu                  Menu popup body.
     * @param array{int,int,int} $menuText              Menu item label.
     * @param array{int,int,int} $menuHighlight         Hovered menu item fill.
     * @param array{int,int,int} $menuHighlightText     Hovered menu item label.
     * @param array{int,int,int} $track                 Scrollbar trough.
     * @param array{int,int,int} $progressTrough        Progress bar trough.
     * @param array{int,int,int} $progressBar           Progress bar fill.
     * @param array{int,int,int} $captionActive         Active title bar (gradient start).
     * @param array{int,int,int} $captionActiveEnd      Active title bar gradient end.
     * @param array{int,int,int} $captionActiveText     Active title text.
     * @param array{int,int,int} $captionInactive       Inactive title bar (gradient start).
     * @param array{int,int,int} $captionInactiveEnd    Inactive title bar gradient end.
     * @param array{int,int,int} $captionInactiveText   Inactive title text.
     * @param array{int,int,int} $frame                 Hard outline (menu/popup frames).
     * @param array{int,int,int} $focus                 Focus indicator.
     * @param array{int,int,int} $accent                Era accent (CDE teal, Platinum blue).
     * @param array{int,int,int} $tooltip               Tooltip background.
     * @param array{int,int,int} $tooltipText           Tooltip text.
     * @param array{int,int,int} $todayMarker           Calendar "today" ring.
     * @param array{int,int,int} $iconInfo              Information icon disc.
     * @param array{int,int,int} $iconWarning           Warning icon disc.
     * @param array{int,int,int} $iconError             Error icon disc.
     * @param array{int,int,int} $iconQuestion          Question icon disc.
     */
    public function __construct(
        public readonly array $face                = [212, 208, 200],
        public readonly array $faceHighlight       = [255, 255, 255],
        public readonly array $faceLight           = [224, 223, 227],
        public readonly array $faceShadow          = [128, 128, 128],
        public readonly array $faceDarkShadow      = [ 64,  64,  64],
        public readonly array $text                = [  0,   0,   0],
        public readonly array $textDisabled        = [128, 128, 128],
        public readonly array $textEmboss          = [255, 255, 255],
        public readonly array $textDim             = [144, 144, 144],
        public readonly array $content             = [255, 255, 255],
        public readonly array $contentText         = [  0,   0,   0],
        public readonly array $selection           = [ 10,  36, 106],
        public readonly array $selectionText       = [255, 255, 255],
        // Null rather than a literal: a theme that doesn't distinguish these
        // should follow *its own* face and bar, not the Windows defaults.
        public readonly ?array $desktop            = null,
        public readonly ?array $panel              = null,
        public readonly array  $bar                = [212, 208, 200],
        public readonly ?array $menuBar            = null,
        public readonly array $menu                = [212, 208, 200],
        public readonly array $menuText            = [  0,   0,   0],
        public readonly array $menuHighlight       = [ 10,  36, 106],
        public readonly array $menuHighlightText   = [255, 255, 255],
        public readonly array $track               = [232, 228, 220],
        public readonly array $progressTrough      = [212, 208, 200],
        public readonly array $progressBar         = [ 10,  36, 106],
        public readonly array $captionActive       = [ 10,  36, 106],
        public readonly array $captionActiveEnd    = [166, 202, 240],
        public readonly array $captionActiveText   = [255, 255, 255],
        public readonly array $captionInactive     = [128, 128, 128],
        public readonly array $captionInactiveEnd  = [192, 192, 192],
        public readonly array $captionInactiveText = [212, 208, 200],
        public readonly array $frame               = [ 64,  64,  64],
        public readonly array $focus               = [  0,   0,   0],
        public readonly array $accent              = [ 10,  36, 106],
        public readonly array $tooltip             = [255, 255, 225],
        public readonly array $tooltipText         = [  0,   0,   0],
        public readonly array $todayMarker         = [200,   0,   0],
        public readonly array $iconInfo            = [  0,   0, 200],
        public readonly array $iconWarning         = [220, 170,   0],
        public readonly array $iconError           = [200,   0,   0],
        public readonly array $iconQuestion        = [  0, 110, 110],
    ) {}

    /**
     * Split 0xRRGGBB into an [r, g, b] triple — lets theme definitions read
     * like the system-colour tables they're transcribed from.
     *
     * @return array{int, int, int}
     */
    public static function hex(int $rgb): array
    {
        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    /** Pack a triple into the 24-bit pixel value X11 wants. */
    public static function pixel(array $rgb): int
    {
        return (($rgb[0] & 0xFF) << 16) | (($rgb[1] & 0xFF) << 8) | ($rgb[2] & 0xFF);
    }

    /**
     * Linear blend between two colours. Handy inside a theme definition so
     * derived shades stay in step with the base ones.
     *
     * @param array{int,int,int} $a
     * @param array{int,int,int} $b
     * @param float              $t 0.0 = all $a, 1.0 = all $b
     * @return array{int, int, int}
     */
    public static function mix(array $a, array $b, float $t): array
    {
        return [
            (int) round($a[0] + ($b[0] - $a[0]) * $t),
            (int) round($a[1] + ($b[1] - $a[1]) * $t),
            (int) round($a[2] + ($b[2] - $a[2]) * $t),
        ];
    }

    /**
     * Colour showing beside a caption that doesn't span the window — BeOS's tab
     * floats above the frame with the desktop visible either side of it.
     *
     * @return array{int, int, int}
     */
    public function desktopColor(): array
    {
        return $this->desktop ?? $this->face;
    }

    /** Colour for a text role — the one lookup every painter needs. */
    public function forText(TextStyle $style): array
    {
        return match ($style) {
            TextStyle::Normal          => $this->text,
            TextStyle::Content         => $this->contentText,
            TextStyle::Selected        => $this->selectionText,
            TextStyle::Menu            => $this->menuText,
            TextStyle::MenuHighlighted => $this->menuHighlightText,
            TextStyle::Disabled        => $this->textDisabled,
            TextStyle::Dim             => $this->textDim,
            TextStyle::Caption         => $this->captionActiveText,
            TextStyle::CaptionInactive => $this->captionInactiveText,
            TextStyle::Tooltip         => $this->tooltipText,
        };
    }

    /** Fill colour for a surface role. */
    public function forSurface(Surface $surface): array
    {
        return match ($surface) {
            Surface::Face      => $this->face,
            Surface::Panel     => $this->panel ?? $this->face,
            Surface::Content   => $this->content,
            Surface::Bar       => $this->bar,
            Surface::MenuBar   => $this->menuBar ?? $this->bar,
            Surface::Menu      => $this->menu,
            Surface::Track     => $this->track,
            Surface::Selection => $this->selection,
            Surface::Tooltip   => $this->tooltip,
        };
    }
}
