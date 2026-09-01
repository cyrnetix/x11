<?php
declare(strict_types=1);

namespace Cyrnetix\X11\UI\Widget;

use Closure;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Filesystem\FileEntry;
use Cyrnetix\X11\Filesystem\FileFilter;
use Cyrnetix\X11\Filesystem\FilePath;
use Cyrnetix\X11\Filesystem\FilePlace;
use Cyrnetix\X11\UI\SyncEventDispatcher;

/**
 * Modal file/folder picker, modelled on the Windows and GTK pickers: a
 * breadcrumb path across the top, a places sidebar on the left, a details list
 * on the right, and a name field plus type filter above the buttons.
 *
 * **It is a real top-level window**, like {@see MessageBox} — override-redirect,
 * so the window manager leaves it alone and the toolkit draws the whole frame.
 * That's what lets the user drag it off the main window entirely, and it's why
 * all its coordinates are *window-local*: this widget sits at (0, 0) of its own
 * window and its children resolve inside that.
 *
 * Unlike the message box, though, it isn't hand-drawn. A dialog with a details
 * list, a sidebar, a dropdown and an editable field can't be hand-drawn and
 * hand-hit-tested, so it's an ordinary container of ordinary widgets: every
 * existing handler, painter and theme applies to it unchanged, and adding a
 * widget to it needs no change in its handler. What makes that work with a
 * second window is that the subtree stays in the one {@see \Cyrnetix\X11\UI\WidgetTree}
 * and is painted separately, into that window's own Renderer.
 *
 * Modality has two halves:
 *
 * - The server's: showing the window grabs the pointer, so every pointer event
 *   goes to the dialog, in the dialog's coordinates.
 * - The tree's: {@see \Cyrnetix\X11\UI\WidgetTree::setModal()} restricts
 *   hit-testing to this subtree, which matters *because* of the grab — those
 *   dialog-local coordinates would otherwise happily match a main-window widget
 *   that happens to sit at the same offset.
 *
 * The caption reuses {@see \Cyrnetix\X11\Theme\Metrics::captionTabWidth()}, so on
 * a theme whose caption is a partial-width tab (BeOS) this dialog gets one too,
 * and the strip beside it is cut out of the window — the same treatment the main
 * window and the message box get.
 */
final class FileDialog extends FormWindow
{
    private FileDialogMode $mode = FileDialogMode::OpenFile;
    private string         $path = '/';

    /** @var list<FileEntry> The listing as read, before filtering. */
    private array $entries = [];

    /** @var list<FileFilter> */
    private array $filters = [];

    /** @var list<FilePlace> Parallel to the sidebar's rows. */
    private array $places = [];

    /** @var list<array{label: string, path: string}> */
    private array $crumbs = [];

    private bool $showHidden = false;
    private bool $loading    = false;

    /** Called when the dialog has changed itself and the screen is stale. */
    private ?Closure $onChanged = null;

    /** Set by the handler: the two things the dialog can't do alone. */
    private ?Closure $onNavigate = null;
    private ?Closure $onAccept   = null;


    // ---- Children -------------------------------------------------------
    private ListBox  $placesList;
    private ListView $fileList;
    private TextBox  $nameBox;
    private DropDown $filterBox;
    private Button   $upButton;
    private Button   $homeButton;
    private Button   $acceptButton;
    private Button   $cancelButton;
    private Checkbox $hiddenCheck;
    private Label    $nameLabel;

    /** Takes size, the event dispatcher and a renderer. */
    public function __construct(
        public int $width,
        public int $height,
        private readonly SyncEventDispatcher $dispatcher,
        private readonly ?Closure $folderIconDrawer = null,
        private readonly ?Closure $fileIconDrawer   = null,
        /**
         * Held for measuring, the way {@see WindowFrame} holds one to size a
         * caption to its title. The Size and Modified columns have to fit text
         * whose width depends on the theme's font, which only the renderer
         * knows — and it knows it late, since the metrics arrive from the
         * server asynchronously. Every relayout re-measures, so the columns
         * correct themselves once the font is really loaded.
         */
        private readonly ?Renderer $renderer = null,
    ) {
        parent::__construct(0, 0);

        $this->path = FilePath::home();

        // Every one of these is an ordinary widget with an ordinary handler;
        // sizes are corrected by layout() as soon as a theme is attached.
        $this->placesList   = new ListBox(0, 0, 100, 100, $this->dispatcher);
        $this->fileList     = new ListView(0, 0, 100, 100, $this->dispatcher);
        $this->nameBox      = new TextBox(0, 0, 100, 22);
        $this->filterBox    = new DropDown(0, 0, 100, $this->dispatcher);
        $this->upButton     = new Button('Up',     0, 0, 46, 22, $this->dispatcher);
        $this->homeButton   = new Button('Home',   0, 0, 46, 22, $this->dispatcher);
        $this->acceptButton = new Button('Open',   0, 0, 76, 22, $this->dispatcher);
        $this->cancelButton = new Button('Cancel', 0, 0, 76, 22, $this->dispatcher);
        $this->hiddenCheck  = new Checkbox('Show hidden files', 0, 0, $this->dispatcher);
        $this->nameLabel    = new Label('File name:', 0, 0);

        foreach ([
            $this->placesList, $this->fileList, $this->nameBox, $this->filterBox,
            $this->upButton, $this->homeButton, $this->acceptButton, $this->cancelButton,
            $this->hiddenCheck, $this->nameLabel,
        ] as $child) {
            $this->addChild($child);
        }

        $this->wireOwnBehaviour();
    }

    /**
     * Reactions that are purely this dialog rearranging itself. They live here
     * rather than in the handler because nothing outside is involved — no
     * filesystem, no redraw, no X server — and because a widget that only
     * behaves correctly once something has painted it is a trap.
     */
    private function wireOwnBehaviour(): void
    {
        // Clicking a row mirrors its name into the field, the way both
        // reference dialogs do, so Save shows what would be overwritten.
        $this->fileList->setOnSelectionChanged(function (?ListViewItem $item): void {
            $entry = $item?->data;
            if ($entry instanceof FileEntry && !$entry->isDir && $this->mode->hasNameField()) {
                $this->setTypedName($entry->name);
                $this->notifyChanged();
            }
        });

        // Picking a type refilters what's already been read — no re-listing. Via
        // the DropDown's own hook, not its inner list's: that slot is how the
        // popup dismisses itself.
        $this->filterBox->setOnChanged(function (): void {
            $this->repopulate();
            $this->notifyChanged();
        });

        $this->hiddenCheck->setOnToggled(function (bool $show): void {
            $this->setShowHidden($show);
            $this->notifyChanged();
        });

        // The buttons stay ordinary buttons handled by ButtonHandler — hit-testing
        // them from a handler would swallow the release that clears their pressed
        // state, and they'd stick down.
        $this->cancelButton->setOnClick(fn() => $this->finish(null));

        // The caption's close box means the same thing as Cancel, and goes
        // through the same call so the two cannot answer differently.
        $this->setOnCaptionClose(fn() => $this->finish(null));
        $this->acceptButton->setOnClick(function (): void {
            if ($this->onAccept !== null) ($this->onAccept)();
        });
        $this->upButton->setOnClick(function (): void {
            $parent = $this->parentPath();
            if ($parent !== null) $this->requestNavigate($parent);
        });
        $this->homeButton->setOnClick(fn() => $this->requestNavigate(FilePath::home()));

        $this->placesList->setOnItemClicked(function (int $index): void {
            $place = $this->placeAt($index);
            if ($place !== null) $this->requestNavigate($place->path);
        });

        // Double click: into a folder, or accept a file.
        $this->fileList->setOnItemActivated(function (ListViewItem $item): void {
            $entry = $item->data;
            if (!$entry instanceof FileEntry) return;

            if ($entry->isDir) {
                $this->requestNavigate($entry->path);
                return;
            }
            if ($this->onAccept !== null) ($this->onAccept)();
        });
    }

    /**
     * How the dialog asks to be repainted after rearranging itself.
     *
     * One slot per callback, one owner each: these children's callbacks belong
     * to the dialog, and a handler that reached in and set them again would
     * silently replace the dialog's own behaviour rather than adding to it.
     * The handler sets *this* instead.
     */
    public function setOnChanged(?Closure $cb): void { $this->onChanged = $cb; }

    /**
     * Reading a directory and deciding what "accept" does need the filesystem
     * and the server, so the handler supplies them; the dialog decides *when*.
     */
    public function setOnNavigate(?Closure $cb): void { $this->onNavigate = $cb; }
    /**
     * What accept does. **One closure**: setting it replaces whatever was there, rather than
     * adding to it.
     */
    public function setOnAccept(?Closure $cb): void   { $this->onAccept   = $cb; }

    /** Asks the owner to read a directory. The dialog does no I/O itself. */
    public function requestNavigate(string $path): void
    {
        if ($this->onNavigate !== null) ($this->onNavigate)($path);
    }

    /** Tells the owner something on screen is now stale. */
    private function notifyChanged(): void
    {
        if ($this->onChanged !== null) ($this->onChanged)();
    }

    // -------------------------------------------------------------------------
    // Opening and closing
    // -------------------------------------------------------------------------

    /**
     * @param list<FileFilter> $filters Offered in the type dropdown; the first
     *        is selected. Ignored in folder mode, which has nothing to filter.
     */
    public function open(
        FileDialogMode $mode,
        ?string        $startPath = null,
        array          $filters   = [],
        ?string        $title     = null,
        string         $suggestedName = '',
    ): void {
        $this->mode = $mode;
        $this->setTitle($title ?? $mode->defaultTitle());
        $this->setVisible(true);
        $this->filters = $filters === [] ? [FileFilter::all()] : array_values($filters);

        $this->acceptButton->setLabel($mode->acceptLabel());
        $this->nameBox->setText($suggestedName);

        $this->filterBox->clearItems();
        foreach ($this->filters as $filter) {
            $this->filterBox->addItem($filter->label);
        }
        $this->filterBox->setSelectedIndex(0);

        // A start path that points at a file opens its directory with the name
        // filled in — what every picker does when handed "the last file used".
        $start = FilePath::normalise($startPath ?? $this->path);
        if (is_file($start)) {
            if ($mode->hasNameField() && $suggestedName === '') {
                $this->nameBox->setText(FilePath::name($start));
            }
            $start = FilePath::parent($start) ?? FilePath::home();
        }
        if (!is_dir($start)) $start = FilePath::home();

        $this->path = $start;
        $this->setEntries([]);
        $this->loading = true;
        $this->layout();
    }

    /** Hides the dialog and forgets what it was showing. */
    public function close(): void
    {
        $this->setVisible(false);
        $this->loading = false;
        $this->filterBox->close();
    }

    /** Fire the result and close. $path null means the user cancelled. */
    public function finish(?string $path): void
    {
        $mode = $this->mode;
        $this->close();
        $this->dispatcher->dispatch(
            new \Cyrnetix\X11\UI\Event\FileDialogClosedEvent($this, $mode, $path)
        );
    }

    /** The mode. */
    public function getMode(): FileDialogMode { return $this->mode; }
    /** The path. */
    public function getPath(): string        { return $this->path; }
    /** Whether it is loading. */
    public function isLoading(): bool        { return $this->loading; }

    // -------------------------------------------------------------------------
    // Contents
    // -------------------------------------------------------------------------

    /** Where the listing goes once the lister resolves. */
    public function setPath(string $path): void
    {
        $this->path    = FilePath::normalise($path);
        $this->loading = true;
        $this->setEntries([]);
    }

    /** @param list<FileEntry> $entries */
    public function setEntries(array $entries): void
    {
        $this->entries = array_values($entries);
        $this->loading = false;
        $this->crumbs  = FilePath::segments($this->path);
        $this->repopulate();
        $this->syncSidebarSelection();
    }

    /** @param list<FilePlace> $places */
    public function setPlaces(array $places): void
    {
        $this->places = array_values($places);

        $this->placesList->clearItems();
        foreach ($this->places as $place) {
            $this->placesList->addItem($place->label);
        }
        $this->syncSidebarSelection();
    }

    /** @return list<FileEntry> Rows that pass the mode, hidden and filter rules. */
    public function visibleEntries(): array
    {
        $filter = $this->currentFilter();

        return array_values(array_filter($this->entries, function (FileEntry $e) use ($filter): bool {
            if ($e->isHidden() && !$this->showHidden)   return false;
            if ($this->mode->picksFolder() && !$e->isDir) return false;
            // A filter narrows which *files* are offered; hiding folders would
            // make their contents unreachable.
            return $e->isDir || $filter === null || $filter->matches($e->name);
        }));
    }

    /** Rebuild the details list from the current filter/hidden state. */
    public function repopulate(): void
    {
        $selected = $this->getSelectedEntry()?->path;

        $this->fileList->clearItems();

        foreach ($this->visibleEntries() as $entry) {
            $this->fileList->addItem(new ListViewItem(
                values: [
                    $entry->name,
                    $entry->isDir ? '' : FilePath::formatSize($entry->size),
                    FilePath::formatTime($entry->mtime),
                ],
                iconDrawer: $entry->isDir ? $this->folderIconDrawer : $this->fileIconDrawer,
                data:       $entry,
                // Folders lead in both sort directions, and the size column
                // compares byte counts rather than "1.2 MB" as text.
                sortGroup:  $entry->isDir ? 0 : 1,
                sortKeys:   [1 => $entry->isDir ? '' : (string) $entry->size],
            ));
        }

        // Keep the user's row selected across a refilter when it survived.
        if ($selected !== null) {
            foreach ($this->fileList->getItems() as $i => $item) {
                if ($item->data instanceof FileEntry && $item->data->path === $selected) {
                    $this->fileList->setSelectedIndex($i);
                    break;
                }
            }
        }
    }

    /** The selected entry. */
    public function getSelectedEntry(): ?FileEntry
    {
        $item = $this->fileList->getSelectedItem();
        return $item?->data instanceof FileEntry ? $item->data : null;
    }

    /** The typed name. */
    public function getTypedName(): string
    {
        return trim($this->nameBox->getText());
    }

    /** Sets typed name. */
    public function setTypedName(string $name): void
    {
        $this->nameBox->setText($name);
    }

    /** The current filter. */
    public function currentFilter(): ?FileFilter
    {
        if (!$this->mode->hasFilterField()) return null;
        return $this->filters[$this->filterBox->getSelectedIndex()] ?? null;
    }

    /** Whether it is showing hidden. */
    public function isShowingHidden(): bool { return $this->showHidden; }

    /** Sets show hidden. */
    public function setShowHidden(bool $show): void
    {
        if ($show === $this->showHidden) return;
        $this->showHidden = $show;
        $this->hiddenCheck->setChecked($show);
        $this->repopulate();
    }

    /**
     * The path the accept button would return, or null when there's nothing
     * valid to accept — which is also what greys the button out.
     *
     * Navigating into a folder is *not* accepting: the caller checks for a
     * directory first (see the handler), because a double-click on a folder in
     * open mode means "go there", not "give me this".
     */
    public function acceptPath(): ?string
    {
        $selected = $this->getSelectedEntry();

        if ($this->mode->picksFolder()) {
            // A highlighted folder wins; otherwise the folder we're looking at,
            // which is what makes "open this directory" one click.
            return $selected !== null && $selected->isDir ? $selected->path : $this->path;
        }

        $typed = $this->getTypedName();
        if ($typed !== '') {
            $candidate = FilePath::join($this->path, $typed);
            if ($this->mode->isSave()) {
                // Saving only needs somewhere to put it.
                $parent = FilePath::parent($candidate);
                return $parent !== null && is_dir($parent) ? $candidate : null;
            }
            return is_file($candidate) ? $candidate : null;
        }

        if ($selected === null || $selected->isDir) return null;
        return $selected->readable || $this->mode->isSave() ? $selected->path : null;
    }

    /** Whether it can accept. */
    public function canAccept(): bool { return $this->acceptPath() !== null; }

    /** Directory the selection would navigate into, or null. */
    public function navigationTarget(): ?string
    {
        $selected = $this->getSelectedEntry();
        if ($selected !== null && $selected->isDir) return $selected->path;

        // A typed path that happens to be a directory navigates too — how a
        // user pastes a location into the name field.
        $typed = $this->getTypedName();
        if ($typed === '') return null;

        $candidate = FilePath::join($this->path, $typed);
        return is_dir($candidate) ? $candidate : null;
    }

    /** The parent path. */
    public function parentPath(): ?string { return FilePath::parent($this->path); }

    /** @return list<FilePlace> */
    public function getPlaces(): array { return $this->places; }

    /** The sidebar place at this index, if there is one. */
    public function placeAt(int $index): ?FilePlace { return $this->places[$index] ?? null; }

    /**
     * Highlight the sidebar row for the directory we're in, and clear it when
     * we've navigated somewhere that isn't one of the shortcuts — a stale
     * highlight claims we're somewhere we aren't.
     */
    private function syncSidebarSelection(): void
    {
        foreach ($this->places as $i => $place) {
            if ($place->path === $this->path) {
                $this->placesList->setSelectedIndex($i);
                return;
            }
        }
        $this->placesList->setSelectedIndex(-1);
    }

    // ---- Children accessors (the handler drives these) ------------------
    /** The places list. */
    public function getPlacesList(): ListBox   { return $this->placesList; }
    /** The file list. */
    public function getFileList(): ListView    { return $this->fileList; }
    /** The name box. */
    public function getNameBox(): TextBox      { return $this->nameBox; }
    /** The filter box. */
    public function getFilterBox(): DropDown   { return $this->filterBox; }
    /** The up button. */
    public function getUpButton(): Button      { return $this->upButton; }
    /** The home button. */
    public function getHomeButton(): Button    { return $this->homeButton; }
    /** The accept button. */
    public function getAcceptButton(): Button  { return $this->acceptButton; }
    /** The cancel button. */
    public function getCancelButton(): Button  { return $this->cancelButton; }
    /** The hidden check. */
    public function getHiddenCheck(): Checkbox { return $this->hiddenCheck; }
    /** The name label. */
    public function getNameLabel(): Label      { return $this->nameLabel; }

    // -------------------------------------------------------------------------
    // Container: overlay-only, so hit-tests and painting put it in front
    // -------------------------------------------------------------------------

    /**
     * Nothing at all while hidden — the subtree lives in the main widget tree,
     * so leaving it reachable would let a click in the *main* window match a
     * dialog widget that happens to sit at the same offset.
     *
     * Rows that don't apply to this mode aren't laid out, so they mustn't be
     * painted or clicked either.
     */
    public function getVisibleChildren(): array
    {
        if (!$this->visible) return [];

        return array_values(array_filter($this->children, fn(Widget $c): bool => match (true) {
            $c === $this->nameBox || $c === $this->nameLabel => $this->mode->hasNameField(),
            $c === $this->filterBox                          => $this->mode->hasFilterField(),
            default                                          => true,
        }));
    }

    /** The dialog's padding is a metric, unlike a plain form's. */
    protected function contentPadding(): int
    {
        return $this->metrics()->fileDialogPadding;
    }

    /**
     * The size this dialog wants, from the theme's metrics, never larger than
     * the screen it has to fit on.
     *
     * @return array{int, int}
     */
    public function preferredSize(int $screenW, int $screenH): array
    {
        $m = $this->metrics();

        return [
            max(320, min($m->fileDialogWidth,  $screenW - 2 * $m->fileDialogPadding)),
            max(240, min($m->fileDialogHeight, $screenH - 2 * $m->fileDialogPadding)),
        ];
    }

    /**
     * Breadcrumb buttons across the nav row.
     *
     * The rightmost crumbs win when the path is too long to fit: those are the
     * ones that say where you are. When any were dropped the row opens with an
     * ellipsis crumb that climbs one level above the first one shown.
     *
     * @return list<array{label: string, path: string, x: int, width: int}>
     */
    public function crumbBounds(Renderer $r): array
    {
        if ($this->crumbs === []) return [];

        $m      = $this->metrics();
        $pad    = $m->fileDialogCrumbPadding;
        $gap    = $m->fileDialogCrumbGap;
        [$left, $top, $avail, $rowH] = $this->crumbStrip();

        $widths = [];
        foreach ($this->crumbs as $i => $crumb) {
            $widths[$i] = 2 * $pad + max(8, $r->measureText($crumb['label']));
        }

        $count     = count($this->crumbs);
        $ellipsisW = 2 * $pad + $r->measureText('...');

        // Drop crumbs from the left until what's left fits — including the
        // overflow crumb, which has to come out of the same budget. The
        // deepest crumb is the one that says where you are, so it's the last
        // thing to go.
        $first = 0;
        while ($first < $count - 1) {
            $need = 0;
            for ($i = $first; $i < $count; $i++) {
                $need += $widths[$i] + ($i > $first ? $gap : 0);
            }
            if ($first > 0) $need += $ellipsisW + $gap;
            if ($need <= $avail) break;
            $first++;
        }

        $bounds = [];
        $x      = $left;

        // With only the deepest crumb left there may still be no room for the
        // overflow marker; the crumb itself wins.
        $showEllipsis = $first > 0 && $widths[$count - 1] + $ellipsisW + $gap <= $avail;

        if ($showEllipsis) {
            $up = FilePath::parent($this->crumbs[$first]['path']);
            $bounds[] = [
                'label' => '...',
                'path'  => $up ?? '/',
                'x'     => $x,
                'width' => $ellipsisW,
            ];
            $x += $ellipsisW + $gap;
        }

        for ($i = $first; $i < $count; $i++) {
            $bounds[] = [
                'label' => $this->crumbs[$i]['label'],
                'path'  => $this->crumbs[$i]['path'],
                'x'     => $x,
                // The last crumb is never dropped, so a strip too narrow for it
                // clips it rather than showing nothing.
                'width' => min($widths[$i], max(8, $left + $avail - $x)),
            ];
            $x += $widths[$i] + $gap;
        }

        return $bounds;
    }

    /** @return array{int, int, int, int} [x, y, width, height] of the crumb strip. */
    public function crumbStrip(): array
    {
        $m    = $this->metrics();
        $rowH = $m->fieldHeight;
        $left = $this->x + $this->contentOffsetX()
              + 2 * ($m->fileDialogNavWidth + $m->fileDialogCrumbGap) + $m->fileDialogGap;

        return [
            $left,
            $this->y + $this->contentOffsetY(),
            max(20, $this->x + $this->width - $this->contentOffsetX() - $left),
            $rowH,
        ];
    }

    /** Which crumb is under the cursor, as a path to navigate to. */
    public function hitTestCrumb(int $mx, int $my, Renderer $r): ?string
    {
        [, $top, , $rowH] = $this->crumbStrip();
        if ($my < $top || $my >= $top + $rowH) return null;

        foreach ($this->crumbBounds($r) as $crumb) {
            if ($mx >= $crumb['x'] && $mx < $crumb['x'] + $crumb['width']) return $crumb['path'];
        }
        return null;
    }

    /** Which crumb is the one we're in, so the painter can press it in. */
    public function isCurrentCrumb(string $path): bool
    {
        return $path === $this->path;
    }

    /** {@inheritDoc} */
    public function relayout(): void
    {
        $this->layout();
    }

    /**
     * Place every child. Driven bottom-up: the button row and the name row have
     * fixed heights, and the lists take whatever is left — so a theme with
     * taller fields eats into the listing instead of overflowing the dialog.
     */
    public function layout(): void
    {
        $m       = $this->metrics();
        $gap     = $m->fileDialogGap;
        $rowH    = $m->fieldHeight;
        $btnH    = $m->dialogButtonHeight;
        $navW    = $m->fileDialogNavWidth;
        $innerW  = max(80, $this->width  - 2 * $this->contentOffsetX());
        $innerH  = max(80, $this->height - $this->contentOffsetY() - $m->edge - $m->fileDialogPadding);

        // ---- Nav row -----------------------------------------------------
        $this->upButton->relX   = 0;
        $this->upButton->relY   = 0;
        $this->homeButton->relX = $navW + $m->fileDialogCrumbGap;
        $this->homeButton->relY = 0;

        // ---- Bottom row --------------------------------------------------
        $bottomY = $innerH - $btnH;
        $btnW    = $m->fileDialogButtonWidth;

        $this->acceptButton->relX = $innerW - $btnW;
        $this->acceptButton->relY = $bottomY;
        $this->cancelButton->relX = $innerW - 2 * $btnW - $m->dialogButtonGap;
        $this->cancelButton->relY = $bottomY;

        $this->hiddenCheck->relX = 0;
        $this->hiddenCheck->relY = $bottomY + intdiv($btnH - $m->checkBoxSize, 2);

        // ---- Name + filter row (absent in folder mode) -------------------
        $nameRowY = $bottomY - $gap - $rowH;
        $hasName  = $this->mode->hasNameField();

        if ($hasName) {
            $labelW   = 74;
            $filterW  = $this->mode->hasFilterField() ? 150 : 0;
            $this->nameLabel->relX = 0;
            $this->nameLabel->relY = $nameRowY;

            $this->nameBox->relX = $labelW;
            $this->nameBox->relY = $nameRowY;
            $this->nameBox->width  = max(60, $innerW - $labelW - ($filterW > 0 ? $filterW + $gap : 0));
            $this->nameBox->height = $rowH;

            if ($filterW > 0) {
                $this->filterBox->relX  = $innerW - $filterW;
                $this->filterBox->relY  = $nameRowY;
                $this->filterBox->width = $filterW;
            }
        }

        // ---- Lists fill what's left --------------------------------------
        $listTop = $rowH + $gap;
        $listH   = max(60, ($hasName ? $nameRowY : $bottomY) - $gap - $listTop);
        $placesW = min($m->fileDialogPlacesWidth, max(60, intdiv($innerW, 3)));

        $this->placesList->moveTo(0, $listTop);
        $this->placesList->resize($placesW, $listH);

        $this->fileList->relX = $placesW + $gap;
        $this->fileList->relY = $listTop;
        $this->fileList->setSize(max(120, $innerW - $placesW - $gap), $listH);

        $this->applyColumns();

        // Children hold absolute coords, so re-resolve them all now that the
        // relative ones moved. Same as WindowFrame after a theme switch.
        foreach ($this->children as $child) {
            $this->resolveCoords($child);
        }

        $this->placesList->relayout();
        $this->fileList->relayout();
        $this->filterBox->relayout();
    }

    /**
     * Name takes the slack; Size and Modified take what their content needs.
     *
     * Both of those have a known widest value — a formatted size never exceeds
     * "999.9 GB" and the date format is fixed-width — so they're measured
     * rather than guessed. The metric is a floor, which lets a theme reserve
     * more without ever being able to clip: a number that fits Windows' font
     * clipped Mac OS 9's wider one.
     */
    private function applyColumns(): void
    {
        $m       = $this->metrics();
        $columns = $this->fileList->getColumns();

        if ($columns === []) {
            $this->fileList->addColumn('Name', 100);
            $this->fileList->addColumn('Size', $m->fileDialogSizeColumn);
            $this->fileList->addColumn('Modified', $m->fileDialogDateColumn);
            $columns = $this->fileList->getColumns();
        }

        // The painter insets each cell by the padding and keeps 2px of slack on
        // the right (see ListViewPainter), so a column needs both plus a little
        // to render its widest value untouched.
        $slack = 2 * $m->listViewCellPadding + 4;
        $sizeW = max($m->fileDialogSizeColumn, $this->measure('999.9 GB') + $slack);
        $dateW = max($m->fileDialogDateColumn, $this->measure('0000-00-00 00:00') + $slack);

        // Asked, not re-derived: the list is the one that decides how much room
        // its rows have, and a disagreement here shows up as a horizontal
        // scrollbar over a couple of pixels of overshoot.
        $avail = $this->fileList->viewportWidth();

        $columns[0]->width = max(80, $avail - $sizeW - $dateW);
        $columns[1]->width = $sizeW;
        $columns[2]->width = $dateW;
    }

    /** Text width in the live font, or a monospaced estimate before one loads. */
    private function measure(string $text): int
    {
        return $this->renderer?->measureText($text) ?? 6 * strlen($text);
    }
}
