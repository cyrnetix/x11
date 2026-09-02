<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Example;


/**
 * One task: what it says, and whether it is done.
 */
final class Task
{
    /** A task starts outstanding unless it is being read back from a file. */
    public function __construct(
        public string $text,
        public bool   $done = false,
    ) {}
}

/**
 * The list and its file format.
 *
 * Deliberately separate from the widgets, and deliberately a format you can edit
 * in any text editor: `- [ ] text` and `- [x] text`, which is ordinary Markdown.
 * A format nobody but this program can read makes a poor example.
 */
final class TaskList
{
    /** @var list<Task> */
    private array $tasks = [];

    private ?string $path = null;
    private bool $dirty = false;

    /** @return list<Task> */
    public function all(): array { return $this->tasks; }

    /** The file this list came from or was last written to, if any. */
    public function path(): ?string { return $this->path; }

    /** Whether there are unsaved changes. */
    public function isDirty(): bool { return $this->dirty; }

    /** How many tasks are still to do. */
    public function remaining(): int
    {
        return count(array_filter($this->tasks, static fn(Task $t): bool => !$t->done));
    }

    /** Appends a task. Blank text is ignored rather than adding an empty row. */
    public function add(string $text): bool
    {
        $text = trim($text);
        if ($text === '') return false;

        $this->tasks[] = new Task($text);
        $this->dirty = true;

        return true;
    }

    /** Ticks or unticks task $index. */
    public function toggle(int $index): bool
    {
        if (!isset($this->tasks[$index])) return false;

        $this->tasks[$index]->done = !$this->tasks[$index]->done;
        $this->dirty = true;

        return true;
    }

    /** Removes task $index. */
    public function remove(int $index): bool
    {
        if (!isset($this->tasks[$index])) return false;

        array_splice($this->tasks, $index, 1);
        $this->dirty = true;

        return true;
    }

    /** Empties the list and forgets which file it came from. */
    public function reset(): void
    {
        $this->tasks = [];
        $this->path  = null;
        $this->dirty = false;
    }

    /**
     * Reads a list from a file. Returns an error message, or null on success.
     *
     * Blocking, because a to-do list is a few hundred bytes. For anything that
     * might be large, react/filesystem is the non-blocking route — the same one
     * {@see DirectoryLister} takes when it is given an adapter — because this is
     * the UI thread and a blocking read is a frozen window.
     */
    public function load(string $path): ?string
    {
        if (!is_file($path))     return 'There is no file at that path.';
        if (!is_readable($path)) return 'That file cannot be read.';

        $body = file_get_contents($path);
        if ($body === false) return 'That file could not be read.';

        $this->tasks = [];
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // `- [x] text`, `- [ ] text`, or a bare line, which counts as to do.
            if (preg_match('/^[-*]\s*\[( |x|X)\]\s*(.*)$/', $line, $m) === 1) {
                $this->tasks[] = new Task($m[2], strtolower($m[1]) === 'x');
                continue;
            }

            $this->tasks[] = new Task(ltrim($line, "-* \t"));
        }

        $this->path  = $path;
        $this->dirty = false;

        return null;
    }

    /** Writes the list out. Returns an error message, or null on success. */
    public function save(string $path): ?string
    {
        $lines = array_map(
            static fn(Task $t): string => sprintf('- [%s] %s', $t->done ? 'x' : ' ', $t->text),
            $this->tasks,
        );

        // A directory that has gone away between choosing the path and writing to
        // it is the ordinary case worth reporting, not an exception worth throwing.
        $dir = dirname($path);
        if (!is_dir($dir))     return 'That folder no longer exists.';
        if (!is_writable($dir)) return 'That folder cannot be written to.';

        if (file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            return 'The file could not be written.';
        }

        $this->path  = $path;
        $this->dirty = false;

        return null;
    }
}
