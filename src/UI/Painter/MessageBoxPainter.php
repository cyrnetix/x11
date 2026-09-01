<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Drawing\TextClip;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\MessageIcon;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\MessageBoxFlags;
use Cyrnetix\X11\UI\Widget\MessageBox;

/**
 * Modal message box. Always draws at (0, 0) — the renderer it's handed is
 * initialised against the dialog window, whose outer size is fixed; everything
 * inside comes from the theme (caption height, button row, severity glyph).
 */
final class MessageBoxPainter
{
    private readonly CaptionPainter $caption;

    /**
     * Painters hold no state; the theme manager is read per call, which is what lets a live theme
     * switch work without re-wiring anything.
     */
    public function __construct(private readonly ThemeManager $themes)
    {
        $this->caption = new CaptionPainter($themes);
    }

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(MessageBox $dialog, Renderer $renderer): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        // The border frames the body, which a tab-style caption overlaps by a
        // row — so with such a theme nothing is drawn above or beside the tab.
        $chrome->dialogFrame($renderer, $dialog->bodyRect($renderer));

        // Space beside a partial-width caption. The window is shaped so this is
        // normally cut away; painting it is the fallback for a server with no
        // SHAPE extension.
        $surround = $dialog->captionSurroundRect($renderer);
        if (!$surround->isEmpty()) {
            // A real hole where the window has an alpha channel; the theme's
            // painted stand-in where it doesn't.
            if ($renderer->isTranslucent()) {
                $renderer->fillTransparent($surround->x, $surround->y, $surround->width, $surround->height);
            } else {
                $chrome->captionSurround($renderer, $surround);
            }
        }

        // The same caption painter as the window frame and the other dialogs.
        // A message box carries no buttons on it: it is modal and its own OK or
        // Cancel is the way out, so a close box would be a third answer to a
        // question that already has two.
        $caption = $dialog->captionRect($renderer);
        $this->caption->paint(
            $renderer,
            $caption,
            $dialog->captionTitleRect($renderer),
            $dialog->getTitle(),
        );

        // Severity glyph, then the message beside it.
        $iconFlag = $dialog->getFlags() & MessageBoxFlags::MASK_ICON;
        $hasIcon  = $iconFlag !== MessageBoxFlags::ICON_NONE;
        $iconSize = 2 * $m->dialogIconRadius;

        $iconBox = Rect::of(8, $caption->bottom() + 10, $iconSize, $iconSize);
        if ($hasIcon) {
            $chrome->messageIcon($renderer, $iconBox, $this->iconFor($iconFlag));
        }

        // The message wraps. The dialog is a real X11 window created once at
        // setup, so its size is a pair of constants and cannot grow to fit —
        // which means the text has to fit the box rather than the other way
        // round. Drawn as one line it simply ran off the edge and the server
        // clipped it at the window border, losing the end of the sentence
        // without a mark to say so.
        $textX  = $hasIcon ? $iconBox->x + $iconSize + 8 : 8;
        // MessageBox::WIDTH, not a $width property — the dialog has none, because
        // its size is a constant rather than something a layout can move.
        $avail  = max(20, MessageBox::WIDTH - $textX - 8);
        $band   = max(1, $this->buttonRowTop($dialog) - $caption->bottom() - 12);
        $line   = max(1, $renderer->fontAscent() + $renderer->fontDescent() + 1);
        $lines  = $this->wrap($dialog->getText(), $avail, $renderer, max(1, intdiv($band, $line)));

        // Centred in the band, so one line still sits beside the icon.
        $blockH = count($lines) * $line;
        $textY  = $caption->bottom() + 12 + intdiv($band - $blockH, 2) + $renderer->fontAscent();

        foreach ($lines as $i => $text) {
            $chrome->text($renderer, $text, $textX, $textY + $i * $line, TextStyle::Normal);
        }

        $this->paintButtons($renderer, $dialog);
    }

    /**
     * Greedy word wrap to $width, at most $maxLines lines.
     *
     * A word longer than the line — a table name, a path — is not broken up: it
     * gets a line of its own and is trimmed to fit, because half a name split
     * across two lines reads as two names.
     *
     * A message longer than $maxLines is cut and **marked** with an ellipsis.
     * Dropping the rest in silence is the bug this method exists to fix; doing
     * it at the line boundary instead of the window border would be the same
     * bug one step further in.
     *
     * @return list<string>
     */
    private function wrap(string $text, int $width, Renderer $r, int $maxLines): array
    {
        $words = preg_split('/\s+/', trim($text)) ?: [];
        if ($words === [] || $words === ['']) return [''];

        $lines = [];
        $line  = '';

        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;

            if ($line !== '' && $r->measureText($candidate) > $width) {
                $lines[] = $line;
                $line    = $word;
                continue;
            }

            $line = $candidate;
        }
        if ($line !== '') $lines[] = $line;

        foreach ($lines as $i => $one) {
            $lines[$i] = TextClip::toWidth($one, $width, $r);
        }

        if (count($lines) <= $maxLines) return $lines;

        // Three dots rather than U+2026: the toolkit draws with an iso8859-1
        // core font, which has no glyph for the ellipsis character.
        $kept = array_slice($lines, 0, $maxLines);
        $last = count($kept) - 1;
        $room = max(0, $width - $r->measureText(' ...'));

        $kept[$last] = TextClip::toWidth($kept[$last], $room, $r) . ' ...';

        return $kept;
    }

    /** Top of the button row, which is where the message has to stop. */
    private function buttonRowTop(MessageBox $dialog): int
    {
        [, $y] = $dialog->buttonRowBounds();

        return $y;
    }

    /** Which severity glyph a flag asks for. */
    private function iconFor(int $flag): MessageIcon
    {
        return match ($flag) {
            MessageBoxFlags::ICON_ERROR    => MessageIcon::Error,
            MessageBoxFlags::ICON_WARNING  => MessageIcon::Warning,
            MessageBoxFlags::ICON_QUESTION => MessageIcon::Question,
            default                        => MessageIcon::Information,
        };
    }

    /** Paints the buttons. */
    private function paintButtons(Renderer $renderer, MessageBox $dialog): void
    {
        $chrome = $this->themes->chrome();
        $m      = $this->themes->metrics();

        $buttons = $dialog->getDialogButtons();
        if ($buttons === []) return;

        [$startX, $btnY, $btnW, $btnH] = $dialog->buttonRowBounds();
        $pressed = $dialog->getPressedBtn();

        foreach ($buttons as $i => ['label' => $label]) {
            $rect = Rect::of($startX + $i * ($btnW + $m->dialogButtonGap), $btnY, $btnW, $btnH);

            // First button is the dialog's default action, which some themes
            // ring differently.
            $state = match (true) {
                $i === $pressed => ControlState::Pressed,
                $i === 0        => ControlState::Default,
                default         => ControlState::Normal,
            };
            $chrome->button($renderer, $rect, $state);

            $shift = $state->isPressed() ? $m->pressOffset : 0;
            $chrome->text(
                $renderer,
                $label,
                $rect->x + intdiv($rect->width - $renderer->measureText($label), 2) + $shift,
                $renderer->baselineYForRect($rect->y, $rect->height) + $shift,
                TextStyle::Normal,
            );
        }
    }
}
