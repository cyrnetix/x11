<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use DateTimeImmutable;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\Event\DateTimeChangedEvent;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Win32-style DateTimePicker. A sunken white field shows the current date as
 * three editable segments ("MM/DD/YYYY") with one segment highlighted as
 * "active"; clicking the arrow button on the right drops down a month-grid
 * calendar so the user can pick a date by mouse instead.
 *
 *   ┌──────────────────────────────┐ ┐
 *   │ 05 / 29 / 2026             ▼ │ │ FIELD_HEIGHT
 *   └──────────────────────────────┘ ┘
 *   ┌──────────────────────────────┐
 *   │ ◀     May 2026            ▶ │   header
 *   │ Su Mo Tu We Th Fr Sa        │   day-of-week
 *   │ 26 27 28 29 30  1  2        │   6 × 7 grid (greyed for prev/next month)
 *   │  …                          │
 *   └──────────────────────────────┘
 *
 * Implements Focusable so WidgetManager can drive it from the keyboard:
 *   Left/Right        prev / next segment
 *   Up/Down           ±1 on active segment
 *   0–9               buffered digit entry, auto-advance on overflow
 *   Enter/Space/F4    toggle popup
 *   Esc               close popup
 *   PgUp/PgDn         prev / next month (popup only — see WidgetManager)
 *
 * All key dispatch lives in WidgetManager so the popup + lazy-load pattern
 * stays consistent with the other list-family widgets.
 */
final class DateTimePicker extends Widget implements Focusable
{
    public const SEG_MONTH = 0;
    public const SEG_DAY   = 1;
    public const SEG_YEAR  = 2;

    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2100;

    /** Calendar grid shape — a week is seven days in every theme. */
    public const CAL_COLS = 7;
    public const CAL_ROWS = 6;

    private DateTimeImmutable $value;
    private DateTimeImmutable $viewMonth;

    private int     $activeSegment = self::SEG_MONTH;
    private bool    $open          = false;
    private bool    $focused       = false;
    /** Which calendar nav button is currently pressed: null | 'prev' | 'next'. */
    private ?string $pressedNav    = null;

    /**
     * Digit-entry buffer. typeDigit() shifts digits in until the segment is
     * "full" (2 digits for MM/DD, 4 for YYYY), then commits + advances. Any
     * other input (arrow keys, click on a different segment) resets it.
     */
    private int $typeBuffer = 0;
    private int $typeCount  = 0;

    /** Takes position, size and the event dispatcher. */
    public function __construct(
        int $x, int $y,
        public int $width,
        private readonly SyncEventDispatcher $dispatcher,
        ?DateTimeImmutable $initial = null,
    ) {
        parent::__construct($x, $y);
        $this->value     = $initial ?? new DateTimeImmutable('today');
        $this->viewMonth = $this->firstOfMonth($this->value);
    }

    // ---- Value -----------------------------------------------------------

    /** The value. */
    public function getValue(): DateTimeImmutable { return $this->value; }

    /** Sets value. */
    public function setValue(DateTimeImmutable $v, bool $fireEvent = true): bool
    {
        // Drop time-of-day — the picker is date-only.
        $v = $v->setTime(0, 0, 0);

        $y = max(self::MIN_YEAR, min(self::MAX_YEAR, (int) $v->format('Y')));
        if ($y !== (int) $v->format('Y')) {
            $v = $v->setDate($y, (int) $v->format('n'), (int) $v->format('j'));
        }
        if ($v->format('Y-m-d') === $this->value->format('Y-m-d')) return false;

        $this->value     = $v;
        $this->viewMonth = $this->firstOfMonth($v);
        if ($fireEvent) {
            $this->dispatcher->dispatch(new DateTimeChangedEvent($this));
        }
        return true;
    }

    // ---- Segment selection ----------------------------------------------

    /** The active segment. */
    public function getActiveSegment(): int { return $this->activeSegment; }

    /** Sets active segment. */
    public function setActiveSegment(int $segment): void
    {
        $segment = max(self::SEG_MONTH, min(self::SEG_YEAR, $segment));
        if ($segment === $this->activeSegment) return;
        $this->activeSegment = $segment;
        $this->resetTypeBuffer();
    }

    /** Moves editing to the next field - day, month, year, hour. */
    public function nextSegment(): void { $this->setActiveSegment($this->activeSegment + 1); }
    /** Moves editing to the previous field. */
    public function prevSegment(): void { $this->setActiveSegment($this->activeSegment - 1); }

    // ---- Editing --------------------------------------------------------

    /**
     * Adjust the active segment by $delta. Day is clamped to the new month
     * length (e.g. May 31 → April 30 when stepping month down). Year is
     * clamped to [MIN_YEAR, MAX_YEAR].
     */
    public function step(int $delta): void
    {
        $this->resetTypeBuffer();

        $y = (int) $this->value->format('Y');
        $m = (int) $this->value->format('n');
        $d = (int) $this->value->format('j');

        switch ($this->activeSegment) {
            case self::SEG_MONTH:
                $m = max(1, min(12, $m + $delta));
                break;
            case self::SEG_DAY:
                $maxDay = $this->daysInMonth($y, $m);
                $d = max(1, min($maxDay, $d + $delta));
                break;
            case self::SEG_YEAR:
                $y = max(self::MIN_YEAR, min(self::MAX_YEAR, $y + $delta));
                break;
        }

        $d = min($d, $this->daysInMonth($y, $m));
        $this->setValue(new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d)));
    }

    /**
     * Accept a single digit '0'-'9' as input for the active segment. The
     * buffer accumulates left-to-right; on overflow the segment commits at
     * its allowed range and the active segment auto-advances. Out-of-range
     * intermediates (e.g. typing "9" in month) commit immediately and
     * advance — this is how Win behaves and avoids needing a clear key.
     */
    public function typeDigit(string $digit): void
    {
        if (strlen($digit) !== 1 || !ctype_digit($digit)) return;

        $maxDigits = $this->activeSegment === self::SEG_YEAR ? 4 : 2;
        $val       = $this->typeBuffer * 10 + (int) $digit;

        // For MM, typing the first digit > 1 cannot possibly extend to 2
        // digits (e.g. "9" must be month 9 — no two-digit month starts with
        // 9). Same logic for DD when first digit > 3. Auto-commit + advance.
        $cannotExtend = false;
        if ($this->typeCount === 0) {
            if ($this->activeSegment === self::SEG_MONTH && (int) $digit > 1) $cannotExtend = true;
            if ($this->activeSegment === self::SEG_DAY   && (int) $digit > 3) $cannotExtend = true;
        }

        $this->typeBuffer = $val;
        $this->typeCount++;

        $this->applyTyped($val);

        if ($this->typeCount >= $maxDigits || $cannotExtend) {
            $this->resetTypeBuffer();
            if ($this->activeSegment !== self::SEG_YEAR) {
                $this->nextSegment();
            }
        }
    }

    /** Commits a typed number into the field being edited, clamped to what that field allows. */
    private function applyTyped(int $val): void
    {
        $y = (int) $this->value->format('Y');
        $m = (int) $this->value->format('n');
        $d = (int) $this->value->format('j');

        switch ($this->activeSegment) {
            case self::SEG_MONTH:
                $m = max(1, min(12, $val));
                break;
            case self::SEG_DAY:
                $maxDay = $this->daysInMonth($y, $m);
                $d = max(1, min($maxDay, max(1, $val)));
                break;
            case self::SEG_YEAR:
                $y = max(self::MIN_YEAR, min(self::MAX_YEAR, $val));
                break;
        }
        $d = min($d, $this->daysInMonth($y, $m));
        $this->setValue(new DateTimeImmutable(sprintf('%04d-%02d-%02d', $y, $m, $d)));
    }

    /** Resets the type buffer. */
    private function resetTypeBuffer(): void
    {
        $this->typeBuffer = 0;
        $this->typeCount  = 0;
    }

    // ---- Calendar popup -------------------------------------------------

    /** Whether it is open. */
    public function isOpen(): bool { return $this->open; }

    /** Drops the calendar open. */
    public function open(): void
    {
        $this->open      = true;
        $this->viewMonth = $this->firstOfMonth($this->value);
    }

    /** Closes the calendar. */
    public function close(): void { $this->open = false; }

    /** The view month. */
    public function getViewMonth(): DateTimeImmutable { return $this->viewMonth; }
    /** Sets view month. */
    public function setViewMonth(DateTimeImmutable $d): void { $this->viewMonth = $this->firstOfMonth($d); }

    /** Shows the previous month. Does not change the value. */
    public function prevMonth(): void { $this->viewMonth = $this->viewMonth->modify('-1 month'); }
    /** Shows the next month. Does not change the value. */
    public function nextMonth(): void { $this->viewMonth = $this->viewMonth->modify('+1 month'); }

    /** The pressed nav. */
    public function getPressedNav(): ?string         { return $this->pressedNav; }
    /** Sets pressed nav. */
    public function setPressedNav(?string $r): void  { $this->pressedNav = $r; }

    /**
     * Top-left day shown in the 6-row grid. Includes leading days from the
     * previous month so the grid is always a full 7-column rectangle
     * starting on Sunday.
     */
    public function gridStartDay(): DateTimeImmutable
    {
        $firstDow = (int) $this->viewMonth->format('w');   // 0 = Sun .. 6 = Sat
        return $this->viewMonth->modify("-{$firstDow} days");
    }

    // ---- Hit tests ------------------------------------------------------

    /** What is at these coordinates, if anything. */
    public function hitTest(int $mx, int $my): bool
    {
        return $this->hitTestField($mx, $my)
            || ($this->open && $this->pointInCalendar($mx, $my));
    }

    /** Which field is at these coordinates, if any. */
    public function hitTestField(int $mx, int $my): bool
    {
        return $mx >= $this->x && $mx < $this->x + $this->width
            && $my >= $this->y && $my < $this->y + $this->metrics()->fieldHeight;
    }

    /** Which button is at these coordinates, if any. */
    public function hitTestButton(int $mx, int $my): bool
    {
        $m    = $this->metrics();
        $btnX = $this->x + $this->width - $m->fieldButtonWidth - 2;
        $btnY = $this->y + 2;
        return $mx >= $btnX && $mx < $btnX + $m->fieldButtonWidth
            && $my >= $btnY && $my < $btnY + $m->fieldHeight - 4;
    }

    /**
     * Map an x-coordinate inside the field's text region to a segment
     * index (0/1/2) or -1. Painter + hit-test share segmentBounds() so the
     * boxes match the visible glyphs exactly under any font.
     */
    public function hitTestSegmentInField(int $mx, int $my, Renderer $r): int
    {
        if (!$this->hitTestField($mx, $my)) return -1;
        if ($this->hitTestButton($mx, $my)) return -1;

        foreach ($this->segmentBounds($r) as $seg => [$sx, $sw]) {
            if ($mx >= $sx && $mx < $sx + $sw) return $seg;
        }
        return -1;
    }

    /**
     * Pixel x,width of each segment glyph cluster. Painter consumes these to
     * draw the active highlight; hit-test consumes the same to figure out
     * which segment was clicked. Returning width-only (no y) since segments
     * share the field's vertical baseline.
     *
     * @return array<int, array{int, int}>
     */
    public function segmentBounds(Renderer $r): array
    {
        $sep   = ' / ';
        $sepW  = $r->measureText($sep);
        $x0    = $this->x + $this->metrics()->datePickerPadding;

        $bounds = [];
        $cursor = $x0;
        foreach ([self::SEG_MONTH, self::SEG_DAY, self::SEG_YEAR] as $seg) {
            $text = $this->segmentText($seg);
            $w    = max($r->measureText($text), $r->measureText(str_repeat('0', strlen($text))));
            $bounds[$seg] = [$cursor, $w];
            $cursor += $w + $sepW;
        }
        return $bounds;
    }

    /** One field of the value, formatted as it appears in the box. */
    public function segmentText(int $seg): string
    {
        return match ($seg) {
            self::SEG_MONTH => $this->value->format('m'),
            self::SEG_DAY   => $this->value->format('d'),
            default         => $this->value->format('Y'),
        };
    }

    // ---- Calendar hit tests ---------------------------------------------

    /** @return array{int, int, int, int} [x, y, width, height] */
    public function getCalendarBounds(): array
    {
        $m = $this->metrics();
        $w = self::CAL_COLS * $m->calendarCellWidth;
        $h = $m->calendarHeaderHeight + $m->calendarDowHeight + self::CAL_ROWS * $m->calendarCellHeight;
        return [$this->x, $this->y + $m->fieldHeight - 1, $w, $h];
    }

    /** Whether a point falls inside the open calendar rather than outside it. */
    private function pointInCalendar(int $mx, int $my): bool
    {
        [$cx, $cy, $cw, $ch] = $this->getCalendarBounds();
        return $mx >= $cx && $mx < $cx + $cw && $my >= $cy && $my < $cy + $ch;
    }

    /** Which calendar prev is at these coordinates, if any. */
    public function hitTestCalendarPrev(int $mx, int $my): bool
    {
        if (!$this->open) return false;
        $m         = $this->metrics();
        [$cx, $cy] = $this->getCalendarBounds();
        return $mx >= $cx + 2 && $mx < $cx + 2 + $m->calendarNavButtonWidth
            && $my >= $cy + 2 && $my < $cy + 2 + $m->calendarHeaderHeight - 4;
    }

    /** Which calendar next is at these coordinates, if any. */
    public function hitTestCalendarNext(int $mx, int $my): bool
    {
        if (!$this->open) return false;
        $m              = $this->metrics();
        [$cx, $cy, $cw] = $this->getCalendarBounds();
        return $mx >= $cx + $cw - $m->calendarNavButtonWidth - 2 && $mx < $cx + $cw - 2
            && $my >= $cy + 2 && $my < $cy + 2 + $m->calendarHeaderHeight - 4;
    }

    /**
     * Returns the date corresponding to a click on the day grid, or null
     * when the click isn't on a day cell. The returned DateTimeImmutable
     * may be in the previous or next month (grayed days) — caller decides
     * how to handle that (typically: also select, navigating viewMonth).
     */
    public function hitTestCalendarDay(int $mx, int $my): ?DateTimeImmutable
    {
        if (!$this->open) return null;
        $m         = $this->metrics();
        [$cx, $cy] = $this->getCalendarBounds();
        $gridY     = $cy + $m->calendarHeaderHeight + $m->calendarDowHeight;

        if ($my < $gridY) return null;
        $row = intdiv($my - $gridY, $m->calendarCellHeight);
        $col = intdiv($mx - $cx,    $m->calendarCellWidth);
        if ($row < 0 || $row >= self::CAL_ROWS) return null;
        if ($col < 0 || $col >= self::CAL_COLS) return null;

        $offset = $row * self::CAL_COLS + $col;
        return $this->gridStartDay()->modify("+{$offset} days");
    }

    // ---- Helpers --------------------------------------------------------

    /** The first day of the month this date is in. */
    private function firstOfMonth(DateTimeImmutable $d): DateTimeImmutable
    {
        return $d->setDate((int) $d->format('Y'), (int) $d->format('n'), 1)->setTime(0, 0, 0);
    }

    /** How many days that month has, leap years included. */
    private function daysInMonth(int $year, int $month): int
    {
        return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    }

    // ---- Focusable ------------------------------------------------------

    /** Whether it is focused. */
    public function isFocused(): bool                       { return $this->focused; }

    /** No disabled state, so always. */
    public function canTakeFocus(): bool { return true; }
    /** Sets which widget has keyboard focus. Null clears it. */
    public function setFocused(bool $focused): void
    {
        $this->focused = $focused;
        if (!$focused) $this->resetTypeBuffer();
    }
    /** The focusable widget at these coordinates, if any. */
    public function hitTestForFocus(int $mx, int $my): bool { return $this->hitTestField($mx, $my); }
    /** {@inheritDoc} */
    public function handleKey(string $key): bool            { return false; }
    /** The copy. */
    public function copy(): ?string                         { return $this->value->format('Y-m-d'); }
    /** {@inheritDoc} */
    public function paste(string $text): void
    {
        try {
            $this->setValue(new DateTimeImmutable($text));
        } catch (\Throwable) {
            // Silently ignore unparseable paste — matches TextBox's
            // tolerance for clipboard junk.
        }
    }
}
