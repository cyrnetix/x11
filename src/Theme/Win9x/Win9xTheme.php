<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Theme\Win9x;

use Cyrnetix\X11\Theme\BaseTheme;
use Cyrnetix\X11\Theme\Chrome;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\Palette;

/**
 * Windows 9x / 2000 — the toolkit's original look, and the reference the
 * {@see Palette} and {@see Metrics} defaults were transcribed from, so its
 * standard variant is deliberately just the defaults plus its chrome.
 *
 * ## Its variants, and where they come from
 *
 * This era let you recolour the whole interface from the control panel, and
 * shipped a list of named schemes to choose from — so a theme of it having more
 * than one palette is faithful to how it was actually used, not a modern
 * retrofit.
 *
 * The three here are **mine**, though. The schemes that shipped with the real
 * thing are specific published artwork, and these were not measured from them:
 * they are a standard grey, a dark scheme and a high-contrast one, chosen to
 * work with this era's chrome. That matters because the chrome draws bevels
 * from the palette's own highlight and shadow roles, so a variant has to keep
 * those in the right relationship — lighter than the face on top-left, darker
 * on bottom-right — or every control comes out inside-out.
 */
final class Win9xTheme extends BaseTheme
{
    /** The id. */
    public function id(): string   { return 'win9x'; }
    /** The name. */
    public function name(): string { return 'Windows 9x / 2000'; }

    /** The palettes this era offers. */
    public function variants(): array
    {
        return [
            'standard' => 'Standard',
            'dark'     => 'Dark',
            'contrast' => 'High Contrast',
        ];
    }

    /** Builds the palette. */
    protected function buildPalette(string $variant): Palette
    {
        return match ($variant) {
            'dark'     => self::darkPalette(),
            'contrast' => self::contrastPalette(),
            default    => new Palette(),
        };
    }

    /**
     * A charcoal scheme.
     *
     * Every bevel role moves together: the face darkens, and the highlight and
     * the two shadows move with it so a raised control still reads as raised.
     * The focus ring flips to near-white for the same reason — the default is
     * black, which on this face would be invisible.
     */
    private static function darkPalette(): Palette
    {
        $face  = Palette::hex(0x3C3C3C);
        $text  = Palette::hex(0xE6E6E6);
        $white = Palette::hex(0xFFFFFF);
        $blue  = Palette::hex(0x3C78C8);

        return new Palette(
            face:                $face,
            faceHighlight:       Palette::hex(0x6E6E6E),
            faceLight:           Palette::hex(0x565656),
            faceShadow:          Palette::hex(0x232323),
            faceDarkShadow:      Palette::hex(0x121212),
            text:                $text,
            textDisabled:        Palette::hex(0x8A8A8A),
            // The engraved highlight behind disabled text has to be *darker*
            // than the face here, not lighter: the effect is a shadow, and on a
            // dark face a white one reads as a second label.
            textEmboss:          Palette::hex(0x1A1A1A),
            textDim:             Palette::hex(0xA0A0A0),
            content:             Palette::hex(0x1E1E1E),
            contentText:         $text,
            selection:           Palette::hex(0x2A5699),
            selectionText:       $white,
            desktop:             Palette::hex(0x101010),
            bar:                 $face,
            menu:                Palette::hex(0x2E2E2E),
            menuText:            $text,
            menuHighlight:       Palette::hex(0x2A5699),
            menuHighlightText:   $white,
            track:               Palette::hex(0x2A2A2A),
            progressTrough:      Palette::hex(0x2A2A2A),
            progressBar:         $blue,
            captionActive:       Palette::hex(0x1F3A66),
            captionActiveEnd:    Palette::hex(0x3C6BA5),
            captionActiveText:   $white,
            captionInactive:     Palette::hex(0x2E2E2E),
            captionInactiveEnd:  Palette::hex(0x3C3C3C),
            captionInactiveText: Palette::hex(0x9A9A9A),
            frame:               Palette::hex(0x0A0A0A),
            focus:               Palette::hex(0xE6E6E6),
            accent:              $blue,
            tooltip:             Palette::hex(0x3A3A28),
            tooltipText:         Palette::hex(0xF0F0D8),
            todayMarker:         Palette::hex(0xE05050),
            iconInfo:            Palette::hex(0x5A8CDC),
            iconWarning:         Palette::hex(0xE0B040),
            iconError:           Palette::hex(0xE05050),
            iconQuestion:        Palette::hex(0x40A0A0),
        );
    }

    /**
     * Black ground, white text, saturated accents.
     *
     * The era shipped high-contrast schemes for accessibility and they are the
     * one case where the bevels stop being decorative: on a black face a
     * conventional grey shadow disappears, so the shadow roles keep some
     * lightness and the highlight goes pure white. A control still has an edge
     * you can find.
     */
    private static function contrastPalette(): Palette
    {
        $black = Palette::hex(0x000000);
        $white = Palette::hex(0xFFFFFF);

        return new Palette(
            face:                $black,
            faceHighlight:       $white,
            faceLight:           Palette::hex(0xC8C8C8),
            faceShadow:          Palette::hex(0x707070),
            faceDarkShadow:      Palette::hex(0x383838),
            text:                $white,
            textDisabled:        Palette::hex(0x909090),
            textEmboss:          $black,
            textDim:             Palette::hex(0xC0C0C0),
            content:             $black,
            contentText:         $white,
            selection:           Palette::hex(0x0000C8),
            selectionText:       $white,
            desktop:             $black,
            bar:                 $black,
            menu:                $black,
            menuText:            $white,
            menuHighlight:       Palette::hex(0x0000C8),
            menuHighlightText:   $white,
            track:               Palette::hex(0x101010),
            progressTrough:      Palette::hex(0x101010),
            progressBar:         Palette::hex(0x00C800),
            captionActive:       Palette::hex(0x0000C8),
            captionActiveEnd:    Palette::hex(0x0000C8),
            captionActiveText:   $white,
            captionInactive:     $black,
            captionInactiveEnd:  $black,
            captionInactiveText: Palette::hex(0xC0C0C0),
            frame:               $white,
            focus:               $white,
            accent:              Palette::hex(0x00C800),
            tooltip:             $black,
            tooltipText:         $white,
            todayMarker:         Palette::hex(0xFF4040),
            iconInfo:            Palette::hex(0x4080FF),
            iconWarning:         Palette::hex(0xFFD040),
            iconError:           Palette::hex(0xFF4040),
            iconQuestion:        Palette::hex(0x40D0D0),
        );
    }

    /** Builds the metrics. */
    protected function buildMetrics(): Metrics { return new Metrics(); }

    /** Builds the chrome. */
    protected function buildChrome(Palette $palette, Metrics $metrics): Chrome
    {
        return new Win9xChrome($palette, $metrics);
    }

    /** The icon set. */
    public function iconSet(): ?string { return 'windows2000'; }

    /** Tahoma/MS Sans Serif have no X11 equivalent; Helvetica 12 stands in. */
    public function fontCandidates(): array
    {
        return [
            '-*-helvetica-medium-r-normal-*-12-*-*-*-*-*-iso8859-1',
            'fixed',
        ];
    }

    /** Windows 2000 captions are bold. */
    public function boldFontCandidates(): array
    {
        return ['-*-helvetica-bold-r-normal-*-12-*-*-*-*-*-iso8859-1'];
    }
}
