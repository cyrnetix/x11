<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Painter;

use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Theme\ControlState;
use Cyrnetix\X11\Theme\TextStyle;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\UI\Widget\FileDialog;

/**
 * The file dialog's own chrome: the body panel, the caption and the breadcrumb
 * row. Everything else inside it is a real widget painted by its own painter,
 * which is the whole point of building the dialog out of widgets.
 *
 * The caption goes through {@see CaptionPainter}, so each
 * theme draws the caption it draws for a window — including BeOS's partial-width
 * tab. The strip beside such a tab is deliberately left unpainted: this dialog
 * sits inside the window, so what shows through there is the application, which
 * is exactly right.
 */
final class FileDialogPainter
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
    public function paint(FileDialog $dialog, Renderer $r): void
    {
        if (!$dialog->isVisible()) return;

        $chrome = $this->themes->chrome();

        // The strip beside a tab caption: a real hole where the window has an
        // alpha channel, the theme's painted stand-in where it hasn't. Same as
        // the main window and the message box.
        $surround = $dialog->captionSurroundRect($r);
        if (!$surround->isEmpty()) {
            if ($r->isTranslucent()) {
                $r->fillTransparent($surround->x, $surround->y, $surround->width, $surround->height);
            } else {
                $chrome->captionSurround($r, $surround);
            }
        }

        // Body first, then the caption over its top edge — a tab caption
        // overlaps by a row and has to win.
        $chrome->dialogFrame($r, $dialog->bodyRect());

        // A FileDialog is a FormWindow, so it has the same caption furniture and
        // draws it through the same painter as the main window.
        $this->caption->paint(
            $r,
            $dialog->captionRect($r),
            $dialog->captionTitleRect($r),
            $dialog->getTitle(),
            $dialog->captionButtons($r),
            active: true,
            pressed: $dialog->getPressedButton(),
            hovered: $dialog->getHoveredButton(),
        );

        $this->paintCrumbs($dialog, $r);
        $this->paintListState($dialog, $r);
    }

    /**
     * Breadcrumb buttons. The crumb for the directory we're in is drawn
     * pressed, which is how both reference dialogs show where you are without
     * needing a separate label.
     */
    private function paintCrumbs(FileDialog $dialog, Renderer $r): void
    {
        $chrome = $this->themes->chrome();
        [, $top, , $rowH] = $dialog->crumbStrip();

        foreach ($dialog->crumbBounds($r) as $crumb) {
            $rect    = Rect::of($crumb['x'], $top, $crumb['width'], $rowH);
            $current = $dialog->isCurrentCrumb($crumb['path']);

            $chrome->button($r, $rect, $current ? ControlState::Pressed : ControlState::Normal);

            $shift = $current ? $this->themes->metrics()->pressOffset : 0;
            $chrome->text(
                $r,
                $crumb['label'],
                $rect->x + intdiv($rect->width - $r->measureText($crumb['label']), 2) + $shift,
                $r->baselineYForRect($rect->y, $rect->height) + $shift,
                TextStyle::Normal,
            );
        }
    }

    /**
     * A word in the middle of the file list when there's nothing in it. An
     * empty white box gives the user no way to tell "still reading" from
     * "nothing here" from "your filter excluded everything" — and those need
     * different reactions.
     */
    private function paintListState(FileDialog $dialog, Renderer $r): void
    {
        if ($dialog->getFileList()->getItems() !== []) return;

        $message = match (true) {
            $dialog->isLoading()                       => 'Reading folder...',
            $dialog->currentFilter()?->acceptsEverything() === false
                                                       => 'No matching files',
            $dialog->getMode()->picksFolder()          => 'No folders here',
            default                                    => 'This folder is empty',
        };

        $list = $dialog->getFileList();
        $m    = $this->themes->metrics();
        $band = Rect::of(
            $list->x + $m->listViewBorder,
            $list->y + $m->listViewBorder + $m->listViewHeaderHeight,
            max(0, $list->width  - 2 * $m->listViewBorder - $m->scrollBarThickness),
            max(0, $list->height - 2 * $m->listViewBorder - $m->listViewHeaderHeight),
        );
        if ($band->isEmpty()) return;

        $this->themes->chrome()->text(
            $r,
            $message,
            $band->x + max(0, intdiv($band->width - $r->measureText($message), 2)),
            $r->baselineYForRect($band->y, min($band->height, 2 * $m->listViewRowHeight)),
            TextStyle::Dim,
        );
    }
}
