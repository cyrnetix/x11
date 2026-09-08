<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Protocol;

use Cyrnetix\X11\Event\X11SetupCompleteEvent;

/**
 * Reads the connection setup reply: screens, visuals, resource id range, formats.
 *
 * Also picks the visual the window will use — it walks the screen's allowed
 * depths for a TrueColor depth-32 visual whose masks leave a spare byte, which is
 * what makes a genuinely transparent window possible, and falls back to the
 * screen's own visual when there is none.
 */
final class SetupParser
{
    /** Whether the buffer holds a whole setup reply yet - its length is in the header. */
    public function canParse(string $buffer): bool
    {
        if (strlen($buffer) < 8) {
            return false;
        }

        /** @var array<string, int> $header */
        $header = unpack('Csuccess/Cunused/vmajor/vminor/vlength', substr($buffer, 0, 8));

        return strlen($buffer) >= 8 + ($header['length'] * 4);
    }

    /**
     * @return array{0: X11SetupCompleteEvent, 1: int}  [event, bytes consumed]
     * @throws \RuntimeException when the server rejects the connection
     */
    public function parse(string $buffer): array
    {
        /** @var array<string, int> $header */
        $header = unpack('Csuccess/Cunused/vmajor/vminor/vlength', substr($buffer, 0, 8));

        if ($header['success'] !== 1) {
            throw new \RuntimeException('X11 setup rejected by server');
        }

        $totalLength = 8 + ($header['length'] * 4);
        $reply       = substr($buffer, 0, $totalLength);

        /** @var array<string, int> $fixed */
        $fixed = unpack(
            'Csuccess/Cunused/vmajor/vminor/vlength/' .
            'Vrelease/VridBase/VridMask/' .
            'VmotionBuf/vvendorLen/vmaxReq/' .
            'CnumScreens/CnumFormats/CimageByte/CbitmapBit/' .
            'CbitmapScan/Cpad',
            $reply,
        );

        // Skip fixed header (40 bytes), vendor string + padding, then pixmap formats.
        $offset  = 40 + $fixed['vendorLen'];
        $offset += (4 - ($offset % 4)) % 4;
        $offset += $fixed['numFormats'] * 8;

        /** @var array<string, int> $screen */
        $screen = unpack(
            'Vroot/Vcmap/Vwhite/Vblack/' .
            'VinputMasks/vwidth/vheight/' .
            'vmmWidth/vmmHeight/vminMaps/vmaxMaps/' .
            'VrootVisual/Cbacking/CsaveUnders/CrootDepth/CnumDepths',
            substr($reply, $offset, 40),
        );

        return [
            new X11SetupCompleteEvent(
                root:         $screen['root'],
                ridBase:      $fixed['ridBase'],
                screenWidth:  $screen['width'],
                screenHeight: $screen['height'],
                depth:        $screen['rootDepth'],
                visual:       $screen['rootVisual'],
                argbVisual:   $this->findArgbVisual($reply, $offset + 40, $screen['numDepths']),
                // Both only matter to PutImage, which hands the server raw
                // memory rather than protocol fields: it has to be in the
                // server's byte order, and it has to fit in one request.
                imageByteOrder:   $fixed['imageByte'],
                maxRequestLength: $fixed['maxReq'],
            ),
            $totalLength,
        ];
    }

    /**
     * Walk the screen's allowed-depths list for a 32-bit TrueColor visual.
     *
     * Each DEPTH is an 8-byte header (depth, pad, visual count, pad) followed by
     * 24-byte VISUALTYPEs. The masks tell us where R, G and B live; on a depth-32
     * TrueColor visual they only account for 24 bits and the remaining byte is
     * alpha, which is what makes {@see \Cyrnetix\X11\Drawing\Renderer::fillTransparent()}
     * work.
     */
    private function findArgbVisual(string $reply, int $offset, int $numDepths): int
    {
        for ($d = 0; $d < $numDepths; $d++) {
            if (strlen($reply) < $offset + 8) return 0;

            /** @var array<string, int> $header */
            $header = unpack('Cdepth/Cpad/vvisuals/Vpad2', substr($reply, $offset, 8));
            $offset += 8;

            for ($v = 0; $v < $header['visuals']; $v++) {
                if (strlen($reply) < $offset + 24) return 0;

                /** @var array<string, int> $visual */
                $visual = unpack(
                    'Vid/Cclass/Cbits/ventries/Vred/Vgreen/Vblue/Vpad',
                    substr($reply, $offset, 24),
                );
                $offset += 24;

                // class 4 = TrueColor. 24 bits of colour in a 32-bit visual
                // leaves a byte over, and that byte is the alpha channel.
                if ($header['depth'] === 32
                    && $visual['class'] === 4
                    && ($visual['red'] | $visual['green'] | $visual['blue']) === 0x00FFFFFF
                ) {
                    return $visual['id'];
                }
            }
        }

        return 0;
    }
}
