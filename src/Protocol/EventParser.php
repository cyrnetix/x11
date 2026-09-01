<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Protocol;

use Cyrnetix\X11\Event\AbstractX11Event;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ErrorEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Event\X11FocusInEvent;
use Cyrnetix\X11\Event\X11FocusOutEvent;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use Cyrnetix\X11\Event\X11MapEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Event\X11SelectionClearEvent;
use Cyrnetix\X11\Event\X11SelectionNotifyEvent;
use Cyrnetix\X11\Event\X11SelectionRequestEvent;
use Cyrnetix\X11\Event\X11UnmapEvent;

/**
 * Turns the server's 32-byte event records into event objects.
 *
 * Every X11 event is exactly 32 bytes, and the first one gives the type; the rest
 * is unpacked per type. Anything unrecognised is skipped rather than fatal, so a
 * server extension sending something unexpected cannot stop the loop.
 */
final class EventParser
{
    private const EVENT_SIZE = 32;

    /**
     * Parse all complete 32-byte X11 events from the buffer.
     *
     * @return array{0: list<AbstractX11Event>, 1: int}  [events, bytes consumed]
     */
    public function parse(string $buffer): array
    {
        $events   = [];
        $consumed = 0;
        $length   = strlen($buffer);

        while ($length - $consumed >= self::EVENT_SIZE) {
            $raw   = substr($buffer, $consumed, self::EVENT_SIZE);
            $event = $this->parseOne($raw);

            if ($event !== null) {
                $events[] = $event;
            }

            $consumed += self::EVENT_SIZE;
        }

        return [$events, $consumed];
    }

    /** Parse a single 32-byte event/error packet. Returns null for unknown types. */
    public function parseOne(string $raw): ?AbstractX11Event
    {
        $type = ord($raw[0]) & 0x7f;

        return match ($type) {
            0  => $this->parseError($raw),
            2  => $this->parseKeyPress($raw),
            4  => $this->parseButtonPress($raw),
            5  => $this->parseButtonRelease($raw),
            6  => $this->parseMotion($raw),
            9  => $this->parseFocusIn($raw),
            10 => $this->parseFocusOut($raw),
            12 => $this->parseExpose($raw),
            18 => $this->parseUnmap($raw),
            19 => $this->parseMap($raw),
            22 => $this->parseConfigure($raw),
            29 => $this->parseSelectionClear($raw),
            30 => $this->parseSelectionRequest($raw),
            31 => $this->parseSelectionNotify($raw),
            default => null,
        };
    }

    /** Another client took a selection we owned. */
    private function parseSelectionClear(string $raw): X11SelectionClearEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cpad/vseq/Vtime/Vowner/Vselection', $raw);
        return new X11SelectionClearEvent(
            time:      $e['time'],
            owner:     $e['owner'],
            selection: $e['selection'],
        );
    }

    /** Someone wants the selection we own converted into a property. */
    private function parseSelectionRequest(string $raw): X11SelectionRequestEvent
    {
        /** @var array<string, int> $e */
        $e = unpack(
            'Ctype/Cpad/vseq/Vtime/Vowner/Vrequestor/Vselection/Vtarget/Vproperty',
            $raw,
        );
        return new X11SelectionRequestEvent(
            time:      $e['time'],
            owner:     $e['owner'],
            requestor: $e['requestor'],
            selection: $e['selection'],
            target:    $e['target'],
            property:  $e['property'],
        );
    }

    /** A conversion we asked for is ready, or was refused. */
    private function parseSelectionNotify(string $raw): X11SelectionNotifyEvent
    {
        /** @var array<string, int> $e */
        $e = unpack(
            'Ctype/Cpad/vseq/Vtime/Vrequestor/Vselection/Vtarget/Vproperty',
            $raw,
        );
        return new X11SelectionNotifyEvent(
            time:      $e['time'],
            requestor: $e['requestor'],
            selection: $e['selection'],
            target:    $e['target'],
            property:  $e['property'],
        );
    }

    /** An error record: the code, the failing request and its sequence number. */
    private function parseError(string $raw): X11ErrorEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Ccode/vseq/Vbad_value/vminor/Cmajor', $raw);

        return new X11ErrorEvent(
            errorCode:   $e['code'],
            majorOpcode: $e['major'],
            minorOpcode: $e['minor'],
            badValue:    $e['bad_value'],
            sequence:    $e['seq'],
        );
    }

    /** A damaged rectangle, plus how many more are still queued. */
    private function parseExpose(string $raw): X11ExposeEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cpad/vseq/Vwindow/vx/vy/vwidth/vheight/vcount', $raw);

        return new X11ExposeEvent(
            windowId: $e['window'],
            x:        $e['x'],
            y:        $e['y'],
            width:    $e['width'],
            height:   $e['height'],
            count:    $e['count'],
        );
    }

    /** Pointer movement. Root coordinates come before window coordinates in this record. */
    private function parseMotion(string $raw): X11MotionEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cdetail/vseq/Vtime/Vroot/Vevent/Vchild/srootX/srootY/sx/sy/vstate/Csame/Cpad', $raw);

        return new X11MotionEvent(
            windowId: $e['event'],
            rootX:    $e['rootX'],
            rootY:    $e['rootY'],
            x:        $e['x'],
            y:        $e['y'],
            time:     $e['time'],
            state:    $e['state'],
        );
    }

    /** A key going down, as a keycode and a modifier mask. */
    private function parseKeyPress(string $raw): X11KeyPressEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Ckeycode/vseq/Vtime/Vroot/Vevent/Vchild/srootX/srootY/sx/sy/vstate/Csame/Cpad', $raw);

        return new X11KeyPressEvent(
            windowId: $e['event'],
            keycode:  $e['keycode'],
            x:        $e['x'],
            y:        $e['y'],
            rootX:    $e['rootX'],
            rootY:    $e['rootY'],
            time:     $e['time'],
            state:    $e['state'],
        );
    }

    /** A button going down, in both window and root coordinates. */
    private function parseButtonPress(string $raw): X11ButtonPressEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cbutton/vseq/Vtime/Vroot/Vevent/Vchild/srootX/srootY/sx/sy/vstate/Csame/Cpad', $raw);

        return new X11ButtonPressEvent(
            windowId: $e['event'],
            button:   $e['button'],
            x:        $e['x'],
            y:        $e['y'],
            rootX:    $e['rootX'],
            rootY:    $e['rootY'],
            time:     $e['time'],
            state:    $e['state'],
        );
    }

    /** A button coming up. */
    private function parseButtonRelease(string $raw): X11ButtonReleaseEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cbutton/vseq/Vtime/Vroot/Vevent/Vchild/srootX/srootY/sx/sy/vstate/Csame/Cpad', $raw);

        return new X11ButtonReleaseEvent(
            windowId: $e['event'],
            button:   $e['button'],
            x:        $e['x'],
            y:        $e['y'],
            rootX:    $e['rootX'],
            rootY:    $e['rootY'],
            time:     $e['time'],
            state:    $e['state'],
        );
    }

    /** The window gained focus. */
    private function parseFocusIn(string $raw): X11FocusInEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cdetail/vseq/Vevent/Cmode', $raw);

        return new X11FocusInEvent(
            windowId: $e['event'],
            detail:   $e['detail'],
            mode:     $e['mode'],
        );
    }

    /** The window lost focus. */
    private function parseFocusOut(string $raw): X11FocusOutEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cdetail/vseq/Vevent/Cmode', $raw);

        return new X11FocusOutEvent(
            windowId: $e['event'],
            detail:   $e['detail'],
            mode:     $e['mode'],
        );
    }

    /** The window was withdrawn. */
    private function parseUnmap(string $raw): X11UnmapEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cpad/vseq/Vevent/Vwindow/Cfrom_configure', $raw);

        return new X11UnmapEvent(
            windowId:      $e['window'],
            fromConfigure: (bool) $e['from_configure'],
        );
    }

    /** The window went on screen. */
    private function parseMap(string $raw): X11MapEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cpad/vseq/Vevent/Vwindow/Coverride', $raw);

        return new X11MapEvent(
            windowId:         $e['window'],
            overrideRedirect: (bool) $e['override'],
        );
    }

    /** The window's new position and size, as the server sees them. */
    private function parseConfigure(string $raw): X11ConfigureEvent
    {
        /** @var array<string, int> $e */
        $e = unpack('Ctype/Cpad/vseq/Vevent/Vwindow/Vabove/sx/sy/vwidth/vheight/vborder/Coverride', $raw);

        return new X11ConfigureEvent(
            windowId:         $e['window'],
            x:                $e['x'],
            y:                $e['y'],
            width:            $e['width'],
            height:           $e['height'],
            borderWidth:      $e['border'],
            overrideRedirect: (bool) $e['override'],
        );
    }
}
