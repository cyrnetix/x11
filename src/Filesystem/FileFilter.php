<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Filesystem;

/**
 * One entry in the file dialog's type dropdown: the label the user picks and
 * the glob patterns it accepts.
 *
 * Patterns are matched with fnmatch() and case-insensitively, so a single
 * "*.png" covers PHOTO.PNG too — which is what a user picking "PNG images"
 * means, whatever the file happens to be called.
 */
final class FileFilter
{
    /** @var list<string> */
    public readonly array $patterns;

    /** @param list<string> $patterns Empty (or ['*']) accepts everything. */
    public function __construct(
        public readonly string $label,
        array $patterns = ['*'],
    ) {
        $this->patterns = array_values($patterns);
    }

    /** A filter that accepts everything, for the "All files" entry. */
    public static function all(string $label = 'All files'): self
    {
        return new self($label, ['*']);
    }

    /** Whether this filter lets every name through. */
    public function acceptsEverything(): bool
    {
        return $this->patterns === [] || $this->patterns === ['*'];
    }

    /** Whether a filename matches any of the patterns, case-insensitively. */
    public function matches(string $name): bool
    {
        if ($this->acceptsEverything()) return true;

        foreach ($this->patterns as $pattern) {
            if (fnmatch($pattern, $name, FNM_CASEFOLD)) return true;
        }
        return false;
    }
}
