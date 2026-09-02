<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

/**
 * Immutable pixel rectangle — the currency between painters (which compute
 * layout) and {@see \Cyrnetix\X11\Theme\Chrome} (which knows how a theme draws
 * a given surface).
 *
 * Coordinates are inclusive-left / exclusive-right in the usual X11 sense:
 * a Rect at x=0 width=10 covers columns 0..9, so {@see right()} returns 9.
 */
final class Rect
{
    /** Takes position and size. */
    public function __construct(
        public readonly int $x,
        public readonly int $y,
        public readonly int $width,
        public readonly int $height,
    ) {}

    /** A rectangle from a position and a size. */
    public static function of(int $x, int $y, int $width, int $height): self
    {
        return new self($x, $y, $width, $height);
    }

    /** @param array{0:int,1:int,2:int,3:int} $a  [x, y, w, h] */
    public static function fromArray(array $a): self
    {
        return new self($a[0], $a[1], $a[2], $a[3]);
    }

    /** Last pixel column inside the rect. */
    public function right(): int { return $this->x + $this->width - 1; }

    /** Last pixel row inside the rect. */
    public function bottom(): int { return $this->y + $this->height - 1; }

    /** The center X. */
    public function centerX(): int { return $this->x + intdiv($this->width, 2); }
    /** The center Y. */
    public function centerY(): int { return $this->y + intdiv($this->height, 2); }

    /** Whether it is empty. */
    public function isEmpty(): bool { return $this->width <= 0 || $this->height <= 0; }

    /** Shrink by $d on every side (negative grows). */
    public function inset(int $d): self
    {
        return new self($this->x + $d, $this->y + $d, $this->width - 2 * $d, $this->height - 2 * $d);
    }

    /** Shrink by $dx left+right and $dy top+bottom. */
    public function insetXY(int $dx, int $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->width - 2 * $dx, $this->height - 2 * $dy);
    }

    /** Shrink each edge independently. */
    public function insetEach(int $left, int $top, int $right, int $bottom): self
    {
        return new self(
            $this->x + $left,
            $this->y + $top,
            $this->width  - $left - $right,
            $this->height - $top  - $bottom,
        );
    }

    /** The same rectangle moved by (dx, dy). */
    public function shift(int $dx, int $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->width, $this->height);
    }

    /** A copy with width changed. This object is immutable. */
    public function withWidth(int $width): self  { return new self($this->x, $this->y, $width, $this->height); }
    /** A copy with height changed. This object is immutable. */
    public function withHeight(int $height): self { return new self($this->x, $this->y, $this->width, $height); }

    /** Top slice of $height pixels. */
    public function topSlice(int $height): self
    {
        return new self($this->x, $this->y, $this->width, min($height, $this->height));
    }

    /** Bottom slice of $height pixels. */
    public function bottomSlice(int $height): self
    {
        $h = min($height, $this->height);
        return new self($this->x, $this->y + $this->height - $h, $this->width, $h);
    }

    /** Left slice of $width pixels. */
    public function leftSlice(int $width): self
    {
        return new self($this->x, $this->y, min($width, $this->width), $this->height);
    }

    /** Right slice of $width pixels. */
    public function rightSlice(int $width): self
    {
        $w = min($width, $this->width);
        return new self($this->x + $this->width - $w, $this->y, $w, $this->height);
    }

    /** A $size × $size box centred inside this rect. */
    public function centeredSquare(int $size): self
    {
        return new self(
            $this->x + intdiv($this->width  - $size, 2),
            $this->y + intdiv($this->height - $size, 2),
            $size,
            $size,
        );
    }

    /**
     * The smallest rectangle covering both. An empty operand is ignored, so
     * folding a list of rects can start from nothing.
     */
    public function union(self $other): self
    {
        if ($other->isEmpty()) return $this;
        if ($this->isEmpty())  return $other;

        $x = min($this->x, $other->x);
        $y = min($this->y, $other->y);

        return new self(
            $x,
            $y,
            max($this->right(), $other->right()) - $x + 1,
            max($this->bottom(), $other->bottom()) - $y + 1,
        );
    }

    /**
     * The overlap of the two, which may be empty.
     *
     * For nesting clips: an inner clip can only ever narrow the one already in
     * force, never widen it back out.
     */
    public function intersect(self $other): self
    {
        $x = max($this->x, $other->x);
        $y = max($this->y, $other->y);

        return new self(
            $x,
            $y,
            max(0, min($this->right(),  $other->right())  - $x + 1),
            max(0, min($this->bottom(), $other->bottom()) - $y + 1),
        );
    }

    /** Grow by $d on every side — room for a focus ring or a shadow. */
    public function grow(int $d): self
    {
        return new self($this->x - $d, $this->y - $d, $this->width + 2 * $d, $this->height + 2 * $d);
    }

    /** Whether the point falls inside, with the right and bottom edges exclusive. */
    public function contains(int $px, int $py): bool
    {
        return $px >= $this->x && $px < $this->x + $this->width
            && $py >= $this->y && $py < $this->y + $this->height;
    }

    /** @return array{int, int, int, int} [x, y, width, height] */
    public function toArray(): array
    {
        return [$this->x, $this->y, $this->width, $this->height];
    }
}
