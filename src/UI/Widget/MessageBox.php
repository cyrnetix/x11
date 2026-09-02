<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Theme\CaptionLayout;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\Metrics;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Event\MessageBoxClosedEvent;
use Cyrnetix\X11\UI\MessageBoxFlags;
use Cyrnetix\X11\UI\MessageBoxResult;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * A modal message box: an icon, a wrapped message and up to three buttons.
 *
 * A real top-level X11 window, created once at setup, so its outer size is fixed
 * and the message is wrapped to fit rather than the box growing to fit the
 * message. Hand-drawn rather than assembled from widgets, which is why it has its
 * own painter and its own hit-testing.
 */
final class MessageBox
{
    // The dialog is a real X11 window created once at setup, so its outer size
    // stays fixed across themes; everything inside it comes from the metrics.
    public const WIDTH  = 220;
    public const HEIGHT = 100;

    // ---- State -----------------------------------------------------------
    private bool   $visible    = false;
    private string $title      = '';
    private string $text       = '';
    private int    $flags      = 0;
    private int    $pressedBtn = -1;

    /** @var list<array{label: string, result: MessageBoxResult}> */
    private array $dialogButtons = [];

    /** Takes the event dispatcher and the theme manager. */
    public function __construct(
        private readonly SyncEventDispatcher $dispatcher,
        /** Live theme source; the dialog isn't in the widget tree so it's passed in. */
        private readonly ?ThemeManager $themes = null,
    ) {}

    /** Measurements of the live theme; toolkit defaults when unthemed. */
    public function metrics(): Metrics
    {
        return $this->themes?->metrics() ?? Metrics::defaults();
    }

    /**
     * The dialog's caption. Normally spans the window inside its border; with a
     * tab-style theme it's only as wide as its title and sits flush in the
     * corner, exactly as a window's does.
     */
    public function captionRect(Renderer $r): Rect
    {
        $m      = $this->metrics();
        $height = $m->captionFitsTitle ? $m->captionHeight : $m->dialogTitleHeight;

        // No caption buttons on a dialog — closing happens through its buttons.
        $wanted = $m->captionTabWidth($r->measureText($this->title), 0);

        if ($wanted === 0) {
            return Rect::of($m->edge, $m->edge, self::WIDTH - 2 * $m->edge, $height);
        }

        return Rect::of(0, 0, min(self::WIDTH, max(40, $wanted)), $height);
    }

    /**
     * The part of the dialog its border frames. A tab overlaps the body's top
     * border by a row, so nothing is drawn above or beside the tab.
     */
    public function bodyRect(Renderer $r): Rect
    {
        if (!$this->metrics()->captionFitsTitle) {
            return Rect::of(0, 0, self::WIDTH, self::HEIGHT);
        }

        $top = max(0, $this->captionRect($r)->height - 1);

        return Rect::of(0, $top, self::WIDTH, self::HEIGHT - $top);
    }

    /**
     * The band the title gets.
     *
     * The whole caption: a message box has no buttons on its title bar. It is
     * still asked for, because {@see \Cyrnetix\X11\UI\Painter\CaptionPainter}
     * paints the drag texture into this rectangle and the title clears its own
     * patch out of that — the same three passes every other caption gets.
     */
    public function captionTitleRect(Renderer $r): Rect
    {
        return CaptionLayout::titleRect($this->metrics(), $this->captionRect($r), []);
    }

    /** The strip a tab-style caption leaves beside itself, if any. */
    public function captionSurroundRect(Renderer $r): Rect
    {
        if (!$this->metrics()->captionFitsTitle) return Rect::of(0, 0, 0, 0);

        $caption = $this->captionRect($r);
        if ($caption->right() >= self::WIDTH - 1) return Rect::of(0, 0, 0, 0);

        return Rect::of(
            $caption->right() + 1,
            0,
            self::WIDTH - $caption->right() - 1,
            max(0, $caption->height - 1),
        );
    }

    /**
     * Rectangles the dialog window actually occupies, for the SHAPE extension —
     * the same treatment the main window gets, so the space beside a tab is cut
     * away rather than painted.
     *
     * @return list<array{int, int, int, int}>
     */
    public function shapeRects(Renderer $r): array
    {
        if ($this->captionSurroundRect($r)->isEmpty()) return [];

        $caption = $this->captionRect($r);
        $body    = $this->bodyRect($r);

        return [
            [$caption->x, $caption->y, $caption->width, $caption->height],
            [$body->x, $body->y, $body->width, $body->height],
        ];
    }

    /**
     * Button row geometry in window-local coordinates, shared by the painter
     * and {@see hitTestButton()}.
     *
     * @return array{int, int, int, int} [startX, y, buttonWidth, buttonHeight]
     */
    public function buttonRowBounds(): array
    {
        $m = $this->metrics();
        $n = count($this->dialogButtons);

        $totalW = $n * $m->dialogButtonWidth + max(0, $n - 1) * $m->dialogButtonGap;
        $startX = intdiv(self::WIDTH - $totalW, 2);
        $y      = self::HEIGHT - $m->dialogButtonHeight - 10;

        return [$startX, $y, $m->dialogButtonWidth, $m->dialogButtonHeight];
    }

    // ---- Public API (position managed externally by X11Client) ----------

    /** Puts a message, a title and a set of buttons up. */
    public function show(string $text, string $title, int $flags): void
    {
        $this->text          = $text;
        $this->title         = $title;
        $this->flags         = $flags;
        $this->visible       = true;
        $this->pressedBtn    = -1;
        $this->dialogButtons = $this->buildButtons($flags & MessageBoxFlags::MASK_BUTTONS);
    }

    /** Takes the dialog down. */
    public function hide(): void      { $this->visible = false; }
    /** Whether it is visible. */
    public function isVisible(): bool { return $this->visible; }

    /** The title. */
    public function getTitle(): string { return $this->title; }
    /** The text. */
    public function getText(): string  { return $this->text; }
    /** The flags. */
    public function getFlags(): int    { return $this->flags; }
    /** The pressed btn. */
    public function getPressedBtn(): int { return $this->pressedBtn; }

    /** @return list<array{label: string, result: MessageBoxResult}> */
    public function getDialogButtons(): array { return $this->dialogButtons; }

    /**
     * Hit-test using window-local coordinates (origin = dialog window top-left).
     * Returns button index 0-based, or -1.
     */
    public function hitTestButton(int $mx, int $my): int
    {
        $n = count($this->dialogButtons);
        if ($n === 0) return -1;

        $gap = $this->metrics()->dialogButtonGap;
        [$startX, $btnY, $btnW, $btnH] = $this->buttonRowBounds();

        for ($i = 0; $i < $n; $i++) {
            $bx = $startX + $i * ($btnW + $gap);
            if ($mx >= $bx && $mx < $bx + $btnW
             && $my >= $btnY && $my < $btnY + $btnH) {
                return $i;
            }
        }

        return -1;
    }

    /** Marks button $idx as held down, for the press to read as a press. */
    public function pressButton(int $idx): void
    {
        $this->pressedBtn = $idx;
    }

    /** Release: if $isClick, fires MessageBoxClosedEvent and hides. */
    public function releaseButton(int $idx, bool $isClick): void
    {
        $this->pressedBtn = -1;

        if (!$isClick) return;

        $result = $this->dialogButtons[$idx]['result'] ?? null;
        if ($result === null) return;

        $this->hide();
        $this->dispatcher->dispatch(new MessageBoxClosedEvent($this, $result));
    }

    // ---- Helpers ---------------------------------------------------------

    /** @return list<array{label: string, result: MessageBoxResult}> */
    private function buildButtons(int $buttonSet): array
    {
        return match ($buttonSet) {
            MessageBoxFlags::BUTTONS_OK_CANCEL => [
                ['label' => 'OK',     'result' => MessageBoxResult::Ok],
                ['label' => 'Cancel', 'result' => MessageBoxResult::Cancel],
            ],
            MessageBoxFlags::BUTTONS_YES_NO => [
                ['label' => 'Yes', 'result' => MessageBoxResult::Yes],
                ['label' => 'No',  'result' => MessageBoxResult::No],
            ],
            MessageBoxFlags::BUTTONS_YES_NO_CANCEL => [
                ['label' => 'Yes',    'result' => MessageBoxResult::Yes],
                ['label' => 'No',     'result' => MessageBoxResult::No],
                ['label' => 'Cancel', 'result' => MessageBoxResult::Cancel],
            ],
            default => [
                ['label' => 'OK', 'result' => MessageBoxResult::Ok],
            ],
        };
    }
}
