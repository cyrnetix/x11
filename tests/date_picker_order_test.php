<?php
declare(strict_types=1);

/**
 * A date picker reads in the order its application asks for.
 *
 * "10 / 07 / 2026" is the seventh of October in the US order the control
 * defaulted to and the tenth of July to most of Europe, with nothing in the
 * field to say which. The order has to reach the drawing, the hit-testing, the
 * arrow keys and the typing alike — one of them left on the old order puts the
 * caret on a different number from the one being drawn highlighted.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\DateTimePicker;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-52s %-24s %s\n", $what, is_array($got) ? json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$ui       = new SyncEventDispatcher(new ListenerRegistry());
$renderer = new Renderer();   // no font loaded: the 6px-per-glyph estimate
$field    = static fn(DateTimePicker $p): string => implode($p->getSeparator(),
    array_map(static fn(int $seg): string => $p->segmentText($seg), $p->getFieldOrder()));
$leftToRight = static function (DateTimePicker $p) use ($renderer): array {
    $bounds = $p->segmentBounds($renderer);
    asort($bounds);
    return array_keys($bounds);
};

echo "the default\n";
$us = new DateTimePicker(0, 0, 140, $ui, new DateTimeImmutable('2026-10-07'));
$check('reads month, day, year',                   $field($us), '10 / 07 / 2026');
$check('starts on the month',                      $us->getActiveSegment(), DateTimePicker::SEG_MONTH);
$us->nextSegment();
$check('and Right goes to the day',                $us->getActiveSegment(), DateTimePicker::SEG_DAY);

echo "ISO\n";
$iso = new DateTimePicker(0, 0, 140, $ui, new DateTimeImmutable('2026-10-07'));
$iso->setFieldOrder(DateTimePicker::ORDER_ISO, '-');
$check('reads year, month, day',                   $field($iso), '2026-10-07');
$check('laid out in that order',                   $leftToRight($iso), DateTimePicker::ORDER_ISO);
$check('and starts on the year',                   $iso->getActiveSegment(), DateTimePicker::SEG_YEAR);

$iso->nextSegment();
$check('Right goes to the segment shown next',     $iso->getActiveSegment(), DateTimePicker::SEG_MONTH);
$iso->nextSegment();
$iso->nextSegment();
$check('and stops at the last one',                $iso->getActiveSegment(), DateTimePicker::SEG_DAY);
$iso->prevSegment();
$check('Left goes back',                           $iso->getActiveSegment(), DateTimePicker::SEG_MONTH);

// Typing a whole date runs left to right, advancing as each segment fills.
$iso->setActiveSegment(DateTimePicker::SEG_YEAR);
foreach (str_split('20260915') as $digit) $iso->typeDigit($digit);
$check('typing 20260915 is the 15th of September', $iso->getValue()->format('Y-m-d'), '2026-09-15');

echo "European\n";
$eu = new DateTimePicker(0, 0, 140, $ui, new DateTimeImmutable('2026-10-07'));
$eu->setFieldOrder(DateTimePicker::ORDER_EUROPEAN);
$check('reads day, month, year',                   $field($eu), '07 / 10 / 2026');
foreach (str_split('15092026') as $digit) $eu->typeDigit($digit);
$check('typing 15092026 is the 15th of September', $eu->getValue()->format('Y-m-d'), '2026-09-15');

try {
    $eu->setFieldOrder([DateTimePicker::SEG_DAY, DateTimePicker::SEG_DAY, DateTimePicker::SEG_YEAR]);
    $refused = false;
} catch (InvalidArgumentException) {
    $refused = true;
}
$check('an order missing a segment is refused',    $refused, true);

exit($fail === 0 ? 0 : 1);
