<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Example;

/**
 * The calculator's state machine.
 *
 * Kept separate from the widgets on purpose: it has no idea it is being driven
 * by buttons, which is what lets the keyboard drive it identically.
 */
final class Calculator
{
    private string $entry = '0';
    private ?float $left = null;
    private ?string $op = null;
    /** True once an operator was pressed, so the next digit starts a new number. */
    private bool $fresh = true;

    /** What the display shows. */
    public function display(): string
    {
        return $this->entry;
    }

    /** One key, whichever way it arrived: a digit, an operator, `=`, `C` or a dot. */
    public function press(string $key): void
    {
        if ($key === 'C') {
            $this->entry = '0';
            $this->left  = null;
            $this->op    = null;
            $this->fresh = true;

            return;
        }

        if ($key === '±') {
            $this->entry = str_starts_with($this->entry, '-')
                ? substr($this->entry, 1)
                : '-' . $this->entry;

            return;
        }

        if (ctype_digit($key) || $key === '.') {
            if ($this->fresh) {
                $this->entry = $key === '.' ? '0.' : $key;
                $this->fresh = false;

                return;
            }
            // One decimal point, and never a leading run of zeros.
            if ($key === '.' && str_contains($this->entry, '.')) return;
            if ($this->entry === '0' && $key !== '.')            $this->entry = '';

            $this->entry .= $key;

            return;
        }

        if (in_array($key, ['+', '-', '*', '/'], true)) {
            $this->resolve();
            $this->op    = $key;
            $this->fresh = true;

            return;
        }

        if ($key === '=') {
            $this->resolve();
            $this->op = null;
        }
    }

    /** Apply the pending operator, if there is one, and make the result the new left operand. */
    private function resolve(): void
    {
        $right = (float) $this->entry;

        if ($this->op === null || $this->left === null) {
            $this->left = $right;

            return;
        }

        $result = match ($this->op) {
            '+' => $this->left + $right,
            '-' => $this->left - $right,
            '*' => $this->left * $right,
            // Division by zero says so rather than throwing: a calculator that
            // dies on a keypress is worse than one that shows an error.
            '/' => $right === 0.0 ? null : $this->left / $right,
        };

        if ($result === null) {
            $this->entry = 'Error';
            $this->left  = null;
            $this->fresh = true;

            return;
        }

        $this->left  = $result;
        $this->entry = $this->format($result);
    }

    /** A float as a calculator shows it: no trailing zeros, no exponent for ordinary sizes. */
    private function format(float $value): string
    {
        if ($value === floor($value) && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
    }
}
