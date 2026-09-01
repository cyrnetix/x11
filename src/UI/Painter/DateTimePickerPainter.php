<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use DateTimeImmutable;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\Direction;
use Cyrnetix\X11\Theme\Surface;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\DateTimePicker;

/**
 * Date picker, in two phases like the menu bar:
 *   - paintField()    draws the closed control (three segments in a drop field).
 *   - paintCalendar() is called after the rest of the tree so the popup overlays
 *                     everything else.
 */
final class DateTimePickerPainter
{
    private const DOW_LABELS = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    private const MONTH_NAMES = [
        1 => 'January',   2 => 'February', 3  => 'March',    4  => 'April',
        5 => 'May',       6 => 'June',     7  => 'July',     8  => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes) {}

    /** Paints the field. */
    public function paintField(DateTimePicker $dtp, Renderer $r): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();
        $palette = $this->themes->palette();

        $outer   = Rect::of($dtp->x, $dtp->y, $dtp->width, $m->fieldHeight);
        $content = $chrome->dropField($r, $outer, $dtp->isOpen(), editable: true);

        $baseline = $r->baselineYForRect($outer->y, $outer->height);
        $bounds   = $dtp->segmentBounds($r);
        $active   = $dtp->getActiveSegment();
        $hasFocus = $dtp->isFocused();
        $sep      = ' / ';

        foreach ([DateTimePicker::SEG_MONTH, DateTimePicker::SEG_DAY, DateTimePicker::SEG_YEAR] as $seg) {
            [$sx, $sw] = $bounds[$seg];

            // The active segment reads as selected, but only while the picker
            // actually holds focus — otherwise it's plain text on the field.
            if ($seg === $active && $hasFocus) {
                $chrome->fill($r, Rect::of($sx, $content->y, $sw, $content->height), Surface::Selection);
                $style = TextStyle::Selected;
            } else {
                $style = TextStyle::Content;
            }

            $chrome->text($r, $dtp->segmentText($seg), $sx, $baseline, $style);

            if ($seg !== DateTimePicker::SEG_YEAR) {
                $chrome->text($r, $sep, $sx + $sw, $baseline, TextStyle::Content);
            }
        }
    }

    /** Paints the calendar. */
    public function paintCalendar(DateTimePicker $dtp, Renderer $r): void
    {
        if (!$dtp->isOpen()) return;

        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $popup = Rect::fromArray($dtp->getCalendarBounds());
        $chrome->popupFrame($r, $popup);

        $header = Rect::of($popup->x + 1, $popup->y + 1, $popup->width - 2, $m->calendarHeaderHeight - 1);
        $dowRow = Rect::of(
            $popup->x + 1,
            $popup->y + $m->calendarHeaderHeight,
            DateTimePicker::CAL_COLS * $m->calendarCellWidth - 2,
            $m->calendarDowHeight,
        );

        $this->paintHeader($dtp, $r, $header);
        $this->paintDayOfWeekRow($r, $dowRow);
        $this->paintGrid($dtp, $r, $popup->x, $dowRow->bottom() + 1);

        // Rules under the header and the day-of-week row.
        $chrome->separator($r, Rect::of($popup->x + 1, $header->bottom() + 1, $popup->width - 2, 1), true);
        $chrome->separator($r, Rect::of($popup->x + 1, $dowRow->bottom() + 1, $popup->width - 2, 1), true);
    }

    /** Header: prev / "Month YYYY" / next. */
    private function paintHeader(DateTimePicker $dtp, Renderer $r, Rect $header): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $chrome->fill($r, $header, Surface::Face);

        $btnW    = $m->calendarNavButtonWidth;
        $btnH    = $header->height - 3;
        $btnY    = $header->y + 1;
        $pressed = $dtp->getPressedNav();

        $prev = Rect::of($header->x + 1, $btnY, $btnW, $btnH);
        $next = Rect::of($header->right() - $btnW, $btnY, $btnW, $btnH);

        foreach ([[$prev, Direction::Left, 'prev'], [$next, Direction::Right, 'next']] as [$rect, $dir, $id]) {
            $down = $pressed === $id;
            $chrome->button($r, $rect, $down ? ControlState::Pressed : ControlState::Normal);

            $shift = $down ? $m->pressOffset : 0;
            $chrome->arrow($r, $rect->shift($shift, $shift), $dir, $this->themes->palette()->text);
        }

        $view  = $dtp->getViewMonth();
        $label = self::MONTH_NAMES[(int) $view->format('n')] . ' ' . $view->format('Y');

        $chrome->text(
            $r,
            $label,
            $header->x + intdiv($header->width - $r->measureText($label), 2),
            $r->baselineYForRect($header->y, $header->height),
            TextStyle::Normal,
        );
    }

    /** Paints the day of week row. */
    private function paintDayOfWeekRow(Renderer $r, Rect $row): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $chrome->fill($r, $row, Surface::Content);

        $baseline = $r->baselineYForRect($row->y, $row->height);
        for ($col = 0; $col < DateTimePicker::CAL_COLS; $col++) {
            $label = self::DOW_LABELS[$col];
            $cellX = $row->x - 1 + $col * $m->calendarCellWidth;

            $chrome->text(
                $r,
                $label,
                $cellX + intdiv($m->calendarCellWidth - $r->measureText($label), 2),
                $baseline,
                TextStyle::Content,
            );
        }
    }

    /** Paints the grid. */
    private function paintGrid(DateTimePicker $dtp, Renderer $r, int $gridX, int $gridY): void
    {
        $chrome  = $this->themes->chrome();
        $m       = $this->themes->metrics();
        $palette = $this->themes->palette();

        $start    = $dtp->gridStartDay();
        $viewM    = (int) $dtp->getViewMonth()->format('n');
        $selected = $dtp->getValue()->format('Y-m-d');
        $today    = (new DateTimeImmutable('today'))->format('Y-m-d');

        for ($row = 0; $row < DateTimePicker::CAL_ROWS; $row++) {
            for ($col = 0; $col < DateTimePicker::CAL_COLS; $col++) {
                $offset = $row * DateTimePicker::CAL_COLS + $col;
                $day    = $start->modify("+{$offset} days");

                $cell = Rect::of(
                    $gridX + $col * $m->calendarCellWidth,
                    $gridY + $row * $m->calendarCellHeight,
                    $m->calendarCellWidth,
                    $m->calendarCellHeight,
                );

                $isSelected = $day->format('Y-m-d') === $selected;
                $inMonth    = (int) $day->format('n') === $viewM;

                if ($isSelected) {
                    $chrome->fill($r, $cell, Surface::Selection);
                    $style = TextStyle::Selected;
                } else {
                    $style = $inMonth ? TextStyle::Content : TextStyle::Dim;
                }

                $label = $day->format('j');
                $chrome->text(
                    $r,
                    $label,
                    $cell->x + intdiv($cell->width - $r->measureText($label), 2),
                    $r->baselineYForRect($cell->y, $cell->height),
                    $style,
                );

                // "Today" keeps its marker ring whether or not it's selected.
                if ($day->format('Y-m-d') === $today) {
                    $r->setForeground(...$palette->todayMarker);
                    $r->drawRect($cell->x, $cell->y, $cell->width - 1, $cell->height - 1);
                }
            }
        }
    }
}
