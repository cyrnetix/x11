<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Handler;

use Psr\Log\LoggerInterface;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Filesystem\DirectoryLister;
use Cyrnetix\X11\Filesystem\FilePath;
use Cyrnetix\X11\UI\Painter\FileDialogPainter;
use Cyrnetix\X11\UI\Widget\FileDialog;
use Cyrnetix\X11\UI\Widget\Widget;
use Cyrnetix\X11\UI\WidgetTree;

/**
 * Behaviour for {@see FileDialog}: navigation, reading directories, and what
 * the buttons and keys mean.
 *
 * The dialog's *contents* are ordinary widgets handled by the ordinary
 * handlers — this one deliberately does not hit-test a single button. It wires
 * itself to the children through the callbacks they expose and then reacts,
 * which is why adding a widget to the dialog needs no change here.
 *
 * Two things do need hit-testing, because they aren't widgets: the breadcrumb
 * row (drawn by the painter, like a tab strip) and the caption, which is
 * draggable so a dialog can be moved off whatever it's covering.
 */
final class FileDialogHandler extends WidgetHandler
{
    /** Non-null while a caption drag is in progress: pointer offset into the dialog. */
    private ?array $dragOffset = null;

    /** Guards against a slow listing for a directory the user has already left. */
    private int $listingGeneration = 0;

    private bool $wired = false;

    /** Takes what it needs to find, paint and repaint its own kind of widget. */
    public function __construct(
        private readonly WidgetTree        $tree,
        private readonly X11Client         $client,
        private readonly DirectoryLister   $lister,
        private readonly Renderer          $renderer,
        private readonly LoggerInterface   $logger,
        private readonly FileDialogPainter $painter,
    ) {}

    /** Paints the widget when it is this handler's kind. False leaves it to the next handler. */
    public function paint(Widget $w, Renderer $r): bool
    {
        if (!$w instanceof FileDialog) return false;

        // Wiring happens on first paint rather than in the constructor: the
        // dialog is built by the application and handed to us, and this is the
        // first moment we're certain to have seen it.
        $this->wire($w);
        $this->painter->paint($w, $r);
        return true;
    }

    /**
     * Connect the two things that need the outside world: navigating into a
     * folder and accepting a file. Everything the dialog can decide by itself
     * is wired in its own constructor.
     *
     * Called from both {@see show()} and paint, and idempotent — whichever
     * happens first, the callbacks exist before anything can be clicked.
     */
    private function wire(FileDialog $dialog): void
    {
        if ($this->wired) return;
        $this->wired = true;

        // The dialog wires its own children — its buttons stay ordinary buttons
        // owned by ButtonHandler, and taking a child's single callback slot
        // would replace that child's behaviour rather than extend it. All it
        // needs from here are the three things it can't do alone: read a
        // directory, decide what accepting means, and repaint.
        $dialog->setOnNavigate(fn(string $path) => $this->navigate($dialog, $path));
        $dialog->setOnAccept(fn() => $this->accept($dialog));
        $dialog->setOnChanged(fn() => $this->client->redraw());
    }

    // -------------------------------------------------------------------------
    // Input
    // -------------------------------------------------------------------------

    /**
     * Handles press if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryPress(X11ButtonPressEvent $event): bool
    {
        $dialog = $this->find();
        if ($dialog === null || !$dialog->isVisible()) return false;

        // Buttons are widgets, so a press on one isn't ours — but we do have to
        // notice which one was pressed. That's done on release, where a click
        // is actually decided.
        // Under the pointer grab, clicks anywhere on screen arrive here in the
        // dialog's own coordinates. Ones outside it are swallowed: it's modal.
        if (!$dialog->outerRect()->contains($event->x, $event->y)) return true;

        $crumb = $dialog->hitTestCrumb($event->x, $event->y, $this->renderer);
        if ($crumb !== null) {
            $this->navigate($dialog, $crumb);
            return true;
        }

        if ($dialog->hitTestCaption($event->x, $event->y, $this->renderer)) {
            // Offset of the pointer within the window, in root coordinates, so
            // the drag can put the window wherever the pointer goes — including
            // off the main window entirely.
            $this->dragOffset = [
                $event->rootX - $this->client->getFileDialogX(),
                $event->rootY - $this->client->getFileDialogY(),
            ];
            return true;
        }

        return false;
    }

    /**
     * Handles release if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryRelease(\Cyrnetix\X11\Event\X11ButtonReleaseEvent $event): bool
    {
        if ($this->dragOffset === null) return false;

        $this->dragOffset = null;
        return true;
    }

    /**
     * Handles motion if it belongs to this widget kind. True means the event was claimed and no
     * later handler sees it.
     */
    public function tryMotion(\Cyrnetix\X11\Event\X11MotionEvent $event): bool
    {
        if ($this->dragOffset === null) return false;

        $dialog = $this->find();
        if ($dialog === null) return false;

        // A real window, so this moves the window rather than a panel inside
        // one — and nothing constrains it to the application.
        [$offsetX, $offsetY] = $this->dragOffset;

        $this->client->moveFileDialogWindow($event->rootX - $offsetX, $event->rootY - $offsetY);
        return true;
    }

    /**
     * Handles key if it belongs to this widget kind. True means the event was claimed and no later
     * handler sees it.
     */
    public function tryKey(string $key): bool
    {
        $dialog = $this->find();
        if ($dialog === null || !$dialog->isVisible()) return false;

        switch ($key) {
            case 'Escape':
                $this->cancel($dialog);
                return true;

            case 'Enter':
                // Enter on a folder goes into it — otherwise typing a directory
                // name and pressing Enter would silently do nothing.
                $target = $dialog->navigationTarget();
                if ($target !== null) {
                    $this->navigate($dialog, $target);
                    return true;
                }
                $this->accept($dialog);
                return true;

            case 'BackSpace':
                // Only when the name field isn't where the typing is going.
                if ($this->tree->getFocused() === $dialog->getNameBox()) return false;

                $parent = $dialog->parentPath();
                if ($parent !== null) $this->navigate($dialog, $parent);
                return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * Read $path and show it. The listing is async, so a generation counter
     * decides whether a reply that lands late still matters — clicking through
     * three folders quickly must not have the first one's contents arrive last
     * and win.
     */
    public function navigate(FileDialog $dialog, string $path): void
    {
        $target = FilePath::normalise($path);

        $dialog->setPath($target);
        $dialog->layout();

        $generation = ++$this->listingGeneration;
        $this->client->setBusyCursor(true);
        $this->client->redraw();

        $this->lister->list($target)->then(function (array $entries) use ($dialog, $generation, $target): void {
            $this->client->setBusyCursor(false);

            if ($generation !== $this->listingGeneration) {
                $this->logger->debug('File dialog: stale listing dropped', ['path' => $target]);
                return;
            }

            $dialog->setEntries($entries);
            $dialog->layout();
            $this->client->redraw();
        });
    }

    /**
     * Open the dialog, take input, and read its starting directory.
     *
     * The container box comes from whoever last laid the dialog out (the app's
     * layout pass), falling back to the window itself the first time.
     */
    public function show(FileDialog $dialog): void
    {
        $this->wire($dialog);

        [$width, $height] = $dialog->preferredSize(
            $this->client->getScreenWidth(),
            $this->client->getScreenHeight(),
        );
        $dialog->setSize($width, $height);

        // Centred over the application, the way the message box is.
        $x = $this->client->getWindowX() + intdiv($this->client->getWindowWidth()  - $width,  2);
        $y = $this->client->getWindowY() + intdiv($this->client->getWindowHeight() - $height, 2);

        // Input belongs to the dialog until it closes: the grab makes the server
        // route every pointer event here, and the modal makes the tree agree.
        $this->tree->setModal($dialog);
        $this->tree->setFocused(null);
        $this->client->showFileDialogWindow(max(0, $x), max(0, $y), $width, $height);

        $this->navigate($dialog, $dialog->getPath());
    }

    /**
     * Re-size and re-lay-out for a theme whose dialog metrics differ, while it's
     * open. The window is a real one, so the *server* has to be told too.
     */
    public function refreshLayout(FileDialog $dialog): void
    {
        if (!$dialog->isVisible()) return;

        [$width, $height] = $dialog->preferredSize(
            $this->client->getScreenWidth(),
            $this->client->getScreenHeight(),
        );
        $dialog->setSize($width, $height);
        $this->client->resizeFileDialogWindow($width, $height);
        $this->client->redrawFileDialog();
    }

    /**
     * What pressing Open or Save means here - which depends on the mode and on what is
     * selected.
     */
    private function accept(FileDialog $dialog): void
    {
        // A folder under the cursor in a file-picking mode means "go there".
        if (!$dialog->getMode()->picksFolder()) {
            $target = $dialog->navigationTarget();
            if ($target !== null) {
                $this->navigate($dialog, $target);
                return;
            }
        }

        $path = $dialog->acceptPath();
        if ($path === null) {
            $this->logger->debug('File dialog: nothing to accept yet');
            return;
        }

        $this->logger->info('File dialog accepted', [
            'mode' => $dialog->getMode()->value,
            'path' => $path,
        ]);

        $this->dismiss($dialog, $path);
    }

    /** Closes the dialog with no choice made. */
    private function cancel(FileDialog $dialog): void
    {
        $this->logger->info('File dialog cancelled');
        $this->dismiss($dialog, null);
    }

    /** Hand input back to the application, then report the result. */
    private function dismiss(FileDialog $dialog, ?string $path): void
    {
        $this->tree->setModal(null);
        $this->tree->setFocused(null);
        $this->dragOffset = null;
        $this->client->hideFileDialogWindow();

        // finish() dispatches, so the listener sees a dialog that is already
        // closed and input that is already released — free to open another.
        $dialog->finish($path);
        $this->client->redraw();
    }

    /** The find. */
    private function find(): ?FileDialog
    {
        $found = $this->tree->findFirst(static fn(Widget $w): bool => $w instanceof FileDialog);
        return $found instanceof FileDialog ? $found : null;
    }
}
