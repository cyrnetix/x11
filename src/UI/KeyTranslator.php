<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI;

/**
 * Translates a raw X11 keycode + modifier state into either a single printable
 * character or a named special key ('BS', 'Left', 'Enter', ...). Keys without
 * a textual interpretation (modifier presses, unknown keycodes) return null.
 *
 * Covers a US keyboard layout — enough for the demo.
 */
final class KeyTranslator
{
    private const UNSHIFTED = [
        10 => '1', 11 => '2', 12 => '3', 13 => '4', 14 => '5',
        15 => '6', 16 => '7', 17 => '8', 18 => '9', 19 => '0',
        20 => '-', 21 => '=',
        24 => 'q', 25 => 'w', 26 => 'e', 27 => 'r', 28 => 't',
        29 => 'y', 30 => 'u', 31 => 'i', 32 => 'o', 33 => 'p',
        34 => '[', 35 => ']',
        38 => 'a', 39 => 's', 40 => 'd', 41 => 'f', 42 => 'g',
        43 => 'h', 44 => 'j', 45 => 'k', 46 => 'l',
        47 => ';', 48 => "'", 49 => '`', 51 => '\\',
        52 => 'z', 53 => 'x', 54 => 'c', 55 => 'v', 56 => 'b',
        57 => 'n', 58 => 'm',
        59 => ',', 60 => '.', 61 => '/',
        65 => ' ',
    ];

    private const SHIFTED = [
        10 => '!', 11 => '@', 12 => '#', 13 => '$', 14 => '%',
        15 => '^', 16 => '&', 17 => '*', 18 => '(', 19 => ')',
        20 => '_', 21 => '+',
        34 => '{', 35 => '}',
        47 => ':', 48 => '"', 49 => '~', 51 => '|',
        59 => '<', 60 => '>', 61 => '?',
    ];

    private const SPECIAL = [
        9   => 'Esc',
        22  => 'BS',
        23  => 'Tab',
        36  => 'Enter',
        110 => 'Home', 111 => 'Up',    112 => 'PgUp',
        113 => 'Left', 114 => 'Right',
        115 => 'End',  116 => 'Down',  117 => 'PgDn',
        118 => 'Ins',  119 => 'Del',
        // Function keys. Not translated at all before now, so an application had
        // no way to answer one — a menu could show "F5" beside Refresh and the
        // key itself could never fire.
        67 => 'F1',  68 => 'F2',  69 => 'F3',  70 => 'F4',  71 => 'F5',  72 => 'F6',
        73 => 'F7',  74 => 'F8',  75 => 'F9',  76 => 'F10', 95 => 'F11', 96 => 'F12',
    ];

    /** Navigation specials that combine with Shift to extend a selection. */
    private const SELECTION_SPECIALS = ['Left', 'Right', 'Home', 'End'];

    /**
     * Keys whose shifted form means something other than selection.
     *
     * Shift+Tab moves focus backwards, so it has to arrive distinguishable from
     * Tab — the X server sends the same keycode with the shift bit set.
     */
    private const SHIFT_AWARE_SPECIALS = ['Tab'];

    /**
     * A keycode and modifier mask as a key name, or null for a key with no name. Note `Enter`,
     * not `Return`.
     */
    public function translate(int $keycode, int $state): ?string
    {
        $shift = ($state & 0x0001) !== 0;
        $caps  = ($state & 0x0002) !== 0;
        $ctrl  = ($state & 0x0004) !== 0;

        if (isset(self::SPECIAL[$keycode])) {
            $special = self::SPECIAL[$keycode];
            // Shift + navigation key → "Shift+Left" / "Shift+End" / etc. so
            // text fields can extend the selection in handleKey().
            if ($shift && (in_array($special, self::SELECTION_SPECIALS, true)
                        || in_array($special, self::SHIFT_AWARE_SPECIALS, true))) {
                return 'Shift+' . $special;
            }
            return $special;
        }

        // Ctrl + letter → "Ctrl+X" / "Ctrl+C" / etc. Callers use these to
        // dispatch shortcuts (clipboard, undo, …) without consuming the key.
        if ($ctrl && isset(self::UNSHIFTED[$keycode])) {
            $c = self::UNSHIFTED[$keycode];
            if (ctype_alpha($c)) {
                return 'Ctrl+' . strtoupper($c);
            }
        }

        if ($shift && isset(self::SHIFTED[$keycode])) {
            return self::SHIFTED[$keycode];
        }

        if (!isset(self::UNSHIFTED[$keycode])) {
            return null;
        }

        $c = self::UNSHIFTED[$keycode];
        if (ctype_alpha($c)) {
            return ($shift xor $caps) ? strtoupper($c) : $c;
        }
        return $c;
    }
}
