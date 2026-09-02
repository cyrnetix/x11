<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

/** Turns a keycode into a key name and hands it to the widget layer. */
final class KeyPressHandler
{
    // Standard Linux/X11 keycodes for a US keyboard layout.
    private const KEY_NAMES = [
        9  => 'Esc',   10 => '1',  11 => '2',  12 => '3',  13 => '4',
        14 => '5',     15 => '6',  16 => '7',  17 => '8',  18 => '9',
        19 => '0',     20 => '-',  21 => '=',  22 => 'BS', 23 => 'Tab',
        24 => 'q',     25 => 'w',  26 => 'e',  27 => 'r',  28 => 't',
        29 => 'y',     30 => 'u',  31 => 'i',  32 => 'o',  33 => 'p',
        34 => '[',     35 => ']',  36 => 'Enter',
        37 => 'Ctrl',  38 => 'a',  39 => 's',  40 => 'd',  41 => 'f',
        42 => 'g',     43 => 'h',  44 => 'j',  45 => 'k',  46 => 'l',
        47 => ';',     48 => "'",  49 => '`',
        50 => 'Shift', 51 => '\\', 52 => 'z',  53 => 'x',  54 => 'c',
        55 => 'v',     56 => 'b',  57 => 'n',  58 => 'm',  59 => ',',
        60 => '.',     61 => '/',  62 => 'Shift',
        64 => 'Alt',   65 => 'Space',
        66 => 'CapsLk',67 => 'F1', 68 => 'F2', 69 => 'F3', 70 => 'F4',
        71 => 'F5',    72 => 'F6', 73 => 'F7', 74 => 'F8', 75 => 'F9',
        76 => 'F10',   95 => 'F11',96 => 'F12',
        110 => 'Home', 111 => 'Up',   112 => 'PgUp',
        113 => 'Left', 114 => 'Right',
        115 => 'End',  116 => 'Down', 117 => 'PgDn',
        118 => 'Ins',  119 => 'Del',
    ];

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly X11Client $client,
        private readonly LoggerInterface $logger,
    ) {}

    /** Handles one event. Registered as a PSR-14 listener, so it is called by the dispatcher. */
    public function __invoke(X11KeyPressEvent $event): PromiseInterface
    {
        $name = self::KEY_NAMES[$event->keycode] ?? "k{$event->keycode}";
        $mods = $this->formatMods($event->state);

        $this->logger->info('Key pressed', array_filter([
            'key'      => $name,
            'keycode'  => $event->keycode,
            'mods'     => $mods ?: null,
        ]));

        $this->client->updateKey($name, $event->state);
        $this->client->redraw();

        return resolve(null);
    }

    /** A modifier mask as readable names, for the log. */
    private function formatMods(int $state): string
    {
        $parts = [];
        if ($state & 0x0001) $parts[] = 'Shift';
        if ($state & 0x0004) $parts[] = 'Ctrl';
        if ($state & 0x0008) $parts[] = 'Alt';
        if ($state & 0x0040) $parts[] = 'Meta';

        return implode('+', $parts);
    }
}
