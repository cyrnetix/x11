<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Logging;

use Monolog\Formatter\FormatterInterface;
use Monolog\Level;
use Monolog\LogRecord;

/**
 * Formats log records as color-coded single lines for ANSI-capable terminals.
 *
 * Output example:
 *   [12:00:00]  INFO      Connected to X server
 *   [12:00:00]  DEBUG     Root: 0x2a0  Window: 0x2a1
 *   [12:00:01]  ERROR     X11 Error  {code=8 major=12 minor=0}
 */
final class ColoredLineFormatter implements FormatterInterface
{
    private const ESC   = "\x1B[";
    private const RESET = "\x1B[0m";

    private const LEVEL_COLOR = [
        Level::Debug->value     => "2;36m",   // dim cyan
        Level::Info->value      => "32m",     // green
        Level::Notice->value    => "94m",     // bright blue
        Level::Warning->value   => "33m",     // yellow
        Level::Error->value     => "31m",     // red
        Level::Critical->value  => "1;31m",   // bold red
        Level::Alert->value     => "1;35m",   // bold magenta
        Level::Emergency->value => "1;97;41m",// bold white on red bg
    ];

    /** {@inheritDoc} */
    public function format(LogRecord $record): string
    {
        $colorCode = self::LEVEL_COLOR[$record->level->value] ?? "0m";
        $color     = self::ESC . $colorCode;
        $dim       = self::ESC . "2m";
        $time      = $record->datetime->format('H:i:s');
        $level     = str_pad($record->level->name, 9);
        $context   = $this->renderContext($record->context);

        return sprintf(
            "%s[%s]%s %s%s%s  %s%s%s\n",
            $dim,   $time,   self::RESET,
            $color, $level, self::RESET,
            $record->message, $context, self::RESET,
        );
    }

    /** @param array<LogRecord> $records */
    public function formatBatch(array $records): string
    {
        return implode('', array_map($this->format(...), $records));
    }

    /** @param array<string, mixed> $context */
    private function renderContext(array $context): string
    {
        if ($context === []) {
            return '';
        }

        $pairs = [];
        foreach ($context as $key => $value) {
            if (is_int($value) || is_float($value)) {
                $pairs[] = "{$key}={$value}";
            } elseif (is_bool($value)) {
                $pairs[] = "{$key}=" . ($value ? 'true' : 'false');
            } elseif (is_string($value)) {
                $pairs[] = "{$key}={$value}";
            } else {
                $pairs[] = "{$key}=" . json_encode($value);
            }
        }

        return '  ' . self::ESC . "2m{" . implode('  ', $pairs) . '}' . self::RESET;
    }
}
