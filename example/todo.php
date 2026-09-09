<?php
declare(strict_types=1);

/**
 * A to-do list that opens and saves real files.
 *
 * What this example is for:
 *
 *  - **The file picker**, in both modes. It is a modal top-level window built out
 *    of ordinary widgets, so it is themed like everything else — and the
 *    application only ever sees one event, {@see FileDialogClosedEvent}, whose
 *    `path` is null when the user cancelled. There is no separate "cancelled"
 *    event to forget about.
 *  - **A checkable list.** Ticking a row is a `ListView` selection plus a keypress,
 *    not a bespoke widget, which is how far the toolkit's parts stretch.
 *  - **A menu bar with real accelerators.** Ctrl+O, Ctrl+S and Ctrl+N are wired to
 *    the same closures the menu items call.
 *  - **Reading and writing without blocking.** The list is small, so this uses
 *    plain `file_get_contents`; the note where it happens says what to do instead
 *    when the file might be large.
 *
 * Run it with:  php example/todo.php [--theme=cde]
 */
require dirname(__DIR__) . '/vendor/autoload.php';

// The list and its file format live in their own file and know nothing about
// X11, which is what lets tests/todo_test.php drive them without an X server.
require __DIR__ . '/lib/TaskList.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Example\TaskList;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\IconName;
use Cyrnetix\X11\Drawing\IconRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use Cyrnetix\X11\Filesystem\DirectoryLister;
use Cyrnetix\X11\Filesystem\FileFilter;
use Cyrnetix\X11\Filesystem\FilePlaces;
use Cyrnetix\X11\Handler\ConfigureHandler;
use Cyrnetix\X11\Handler\ExposeHandler;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\FileDialogClosedEvent;
use Cyrnetix\X11\UI\Handler as H;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter as P;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\FileDialog;
use Cyrnetix\X11\UI\Widget\FileDialogMode;
use Cyrnetix\X11\UI\Widget\Label;
use Cyrnetix\X11\UI\Widget\ListView;
use Cyrnetix\X11\UI\Widget\ListViewItem;
use Cyrnetix\X11\UI\Widget\Menu;
use Cyrnetix\X11\UI\Widget\MenuBar;
use Cyrnetix\X11\UI\Widget\MenuItem;
use Cyrnetix\X11\UI\Widget\MessageBox;
use Cyrnetix\X11\UI\Widget\MessageBoxFlags;
use Cyrnetix\X11\UI\Widget\StatusBar;
use Cyrnetix\X11\UI\Widget\StatusBarPane;
use Cyrnetix\X11\UI\Widget\TextBox;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use function React\Promise\resolve;

// ---- wiring ---------------------------------------------------------------

$themeId = 'win9x';
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--theme=')) $themeId = substr($arg, 8);
}

$logger = new Logger('todo');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Warning));

$themes = new ThemeManager(
    new Win9xTheme(), new Win31Theme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme(),
);
// "id" or "id:variant".
[$themeId, $themeVariant] = array_pad(explode(':', $themeId, 2), 2, null);
$themes->select($themeId, $themeVariant);

$registry = new ListenerRegistry();
$renderer = new Renderer();
$client   = new X11Client(Loop::get(), new AsyncEventDispatcher($registry, $logger), $logger, $renderer, $themes);
$ui       = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());
$icons    = new IconRegistry(themes: $themes, logger: $logger);

$tasks = new TaskList();
$tree  = new WidgetTree($themes);
$frame = new WindowFrame(460, 380, 'To-do', drawsChrome: true);
$box   = new MessageBox($ui, $themes);

$menuBar = new MenuBar(0, 0, 460);
$list    = new ListView(0, 0, 420, 240, $ui);
$list->addColumn('Done', 50);
$list->addColumn('Task', 340);

$entry  = new TextBox(0, 0, 260, $themes->metrics()->fieldHeight, dispatcher: $ui);
$addBtn = new Button('Add',    0, 0, 70, $themes->metrics()->dialogButtonHeight, $ui);
$delBtn = new Button('Remove', 0, 0, 70, $themes->metrics()->dialogButtonHeight, $ui);
$hint   = new Label('Space or double-click ticks a task', 0, 0);

$status = new StatusBar(0, 0, 460);
$status->addPane(new StatusBarPane('No file'));
$status->addPane(new StatusBarPane('0 left', 90));

foreach ([$menuBar, $list, $entry, $addBtn, $delBtn, $hint, $status] as $child) {
    $frame->addChild($child);
}

// ---- the file picker -----------------------------------------------------
// Its own top-level window, created up front and shown when needed. Modal: while
// it is up it grabs the pointer, so every click routes to it.
$fileDialog = new FileDialog(
    width:  560,
    height: 400,
    dispatcher:       $ui,
    folderIconDrawer: $icons->iconDrawer(IconName::Folder),
    fileIconDrawer:   $icons->iconDrawer(IconName::File),
    renderer:         $renderer,
);
$fileDialog->setPlaces((new FilePlaces())->all());

$fileDialogHandler = new H\FileDialogHandler(
    $tree, $client, new DirectoryLister(), $renderer, $logger,
    new P\FileDialogPainter($themes), new DoubleClickDetector(),
);

$filters = [
    new FileFilter('To-do lists', ['*.md', '*.txt']),
    FileFilter::all(),
];

// ---- what the screen says ------------------------------------------------

$refresh = static function () use ($tasks, $list, $status, $client): void {
    $selected = $list->getSelectedIndex();

    $list->clearItems();
    foreach ($tasks->all() as $task) {
        $list->addItem(new ListViewItem([$task->done ? 'x' : '', $task->text]));
    }

    // Keep the selection where it was, clamped, so ticking a row does not lose
    // your place in the list.
    $count = count($tasks->all());
    if ($count > 0) $list->setSelectedIndex(max(0, min($count - 1, $selected)));

    $status->setPaneText(0, ($tasks->path() ?? 'No file') . ($tasks->isDirty() ? ' *' : ''));
    $status->setPaneText(1, sprintf('%d left', $tasks->remaining()));

    $client->redraw();
};

/** Puts a message up in the middle of the window. */
$say = static function (string $text, string $title, int $flags) use ($box, $client): void {
    $box->show($text, $title, $flags);
    $client->showDialogWindow(
        $client->getWindowX() + intdiv($client->getWindowWidth()  - MessageBox::WIDTH,  2),
        $client->getWindowY() + intdiv($client->getWindowHeight() - MessageBox::HEIGHT, 2),
    );
    $client->redrawDialog();
};

// ---- what the buttons and the menu do ------------------------------------

$addTask = static function () use ($tasks, $entry, $refresh): void {
    if ($tasks->add($entry->getText())) {
        $entry->setText('');
        $refresh();
    }
};

$removeTask = static function () use ($tasks, $list, $refresh): void {
    if ($tasks->remove($list->getSelectedIndex())) $refresh();
};

$toggleTask = static function () use ($tasks, $list, $refresh): void {
    if ($tasks->toggle($list->getSelectedIndex())) $refresh();
};

$addBtn->setOnClick($addTask);
$delBtn->setOnClick($removeTask);
$list->setOnItemActivated($toggleTask); // A double-click ticks it.

$openPicker = static function (FileDialogMode $mode) use ($fileDialog, $fileDialogHandler, $filters, $tasks): void {
    $fileDialog->open(
        $mode,
        $tasks->path() !== null ? dirname($tasks->path()) : getcwd(),
        $filters,
        suggestedName: $mode->isSave() ? basename($tasks->path() ?? 'todo.md') : '',
    );
    $fileDialogHandler->show($fileDialog);
};

$newList = static function () use ($tasks, $refresh): void {
    $tasks->reset();
    $refresh();
};
$openList = static fn() => $openPicker(FileDialogMode::OpenFile);
$saveList = static fn() => $openPicker(FileDialogMode::SaveFile);

$menuBar->addMenu(
    (new Menu('File'))
        ->addItem(new MenuItem('New',      'Ctrl+N', $newList))
        ->addItem(new MenuItem('Open...',  'Ctrl+O', $openList))
        ->addItem(new MenuItem('Save As...', 'Ctrl+S', $saveList))
        ->addSeparator()
        ->addItem(new MenuItem('Quit', 'Ctrl+Q', static fn() => $client->closeWindow())),
);

// One event for both modes, and its first question is always the same one:
// was anything chosen at all?
$uiRegistry->addListener(
    FileDialogClosedEvent::class,
    static function (FileDialogClosedEvent $e) use ($tasks, $refresh, $say, $logger): void {
        if ($e->wasCancelled()) {
            $logger->info('picker cancelled');

            return;
        }

        $error = $e->mode->isSave()
            ? $tasks->save($e->path)
            : $tasks->load($e->path);

        if ($error !== null) {
            $say($error, $e->mode->isSave() ? 'Could not save' : 'Could not open',
                MessageBoxFlags::BUTTONS_OK | MessageBoxFlags::ICON_WARNING);
        }

        $refresh();
    },
);

// ---- handlers ------------------------------------------------------------
// Order is policy. The modal file dialog goes first, so while it is up it
// swallows what happens outside it; the menu bar next, so an open menu takes
// every click; then the scrollbar, before the list it belongs to.
$doubleClick = new DoubleClickDetector();

$handlers = [
    $fileDialogHandler,
    new H\MenuBarHandler($tree, $client, $renderer, new P\MenuBarPainter($themes)),
    new H\WindowFrameHandler($tree, $client, $ui, new DoubleClickDetector(), $renderer, new P\WindowFramePainter($themes)),
    new H\ScrollBarHandler($tree, $client, new P\ScrollBarPainter($themes)),
    new H\EditableTextHandler($tree, $client, $renderer),
    new H\ButtonHandler($tree, $client, $logger, new P\ButtonPainter($themes)),
    new H\ListViewHandler($tree, $client, $doubleClick, new P\ListViewPainter($themes)),
    new H\TextBoxHandler(new P\TextBoxPainter($themes)),
    new H\LabelHandler(new P\LabelPainter($themes)),
    new H\StatusBarHandler(new P\StatusBarPainter($themes)),
];

$manager = new WidgetManager(
    $client, $renderer, $tree, new P\MessageBoxPainter($themes), new KeyTranslator(), $logger,
    $handlers,
    new H\TreeViewHandler($tree, $client, new DoubleClickDetector(), new P\TreeViewPainter($themes)),
);
$client->setWidgetManager($manager);
$manager->addChild($frame);
$manager->setDialog($box);
$manager->setFileDialog($fileDialog);
$manager->register($registry);

// ---- keyboard ------------------------------------------------------------
// Accelerators, and Space to tick the selected task. Priority 0 runs before the
// widget manager's own key handling, which sits at -10.
$keys = new KeyTranslator();

$registry->addListener(
    X11KeyPressEvent::class,
    static function (X11KeyPressEvent $e) use (
        $keys, $newList, $openList, $saveList, $toggleTask, $addTask, $client, $fileDialog,
        $entry, $tree
    ): PromiseInterface {
        // While the picker is up it owns the keyboard.
        if ($fileDialog->isVisible()) return resolve(null);

        match ($keys->translate($e->keycode, $e->state)) {
            'Ctrl+N' => $newList(),
            'Ctrl+O' => $openList(),
            'Ctrl+S' => $saveList(),
            'Ctrl+Q' => $client->closeWindow(),
            // Enter in the field adds the task. TextBox raises no "submitted"
            // event, so this is where Enter means something.
            'Enter'  => $tree->getFocused() === $entry ? $addTask() : null,
            // Space only ticks a task when the text field does not have focus,
            // or typing a space in the field would tick something instead.
            ' '      => $tree->getFocused() === $entry ? null : $toggleTask(),
            default  => null,
        };

        return resolve(null);
    },
);

// ---- layout --------------------------------------------------------------
// Derived from the frame's content box and the theme's own metrics, so a theme
// with a taller caption or a thicker border needs no changes here.
$layout = static function (int $width, int $height) use (
    $frame, $menuBar, $list, $entry, $addBtn, $delBtn, $hint, $status, $themes
): void {
    $frame->setSize($width, $height);

    $m     = $themes->metrics();
    $pad   = 8;
    $gap   = 6;
    $inner = $width - 2 * $frame->contentOffsetX();
    $usable = $height - $frame->contentOffsetY() - $m->edge;

    $menuBar->relX = 0;
    $menuBar->relY = 0;
    $menuBar->setSize($inner);

    $status->relX = 0;
    $status->relY = $usable - $m->statusBarHeight;
    $status->setSize($inner);

    $row = $status->relY - $m->dialogButtonHeight - $gap;

    $entry->relX   = $pad;
    $entry->relY   = $row;
    $entry->width  = max(80, $inner - 2 * $pad - 2 * (70 + $gap));
    $entry->height = $m->fieldHeight;

    $addBtn->relX = $entry->relX + $entry->width + $gap;
    $addBtn->relY = $row;
    $delBtn->relX = $addBtn->relX + $addBtn->width + $gap;
    $delBtn->relY = $row;

    $hint->relX = $pad;
    $hint->relY = $row - 20;

    $top = $m->menuBarHeight + $pad;
    $list->relX = $pad;
    $list->relY = $top;
    $list->setSize(max(120, $inner - 2 * $pad), max(80, $hint->relY - $gap - $top));

    $frame->relayout();
};

// Closing is the application's decision, not the toolkit's: the caption's close
// button only *asks*, by dispatching WindowCloseRequestedEvent, so that an app
// can prompt about unsaved work first. Answer it or the button does nothing —
// which is exactly what every example here did until it was pointed out.
$uiRegistry->addListener(
    WindowCloseRequestedEvent::class,
    static function (WindowCloseRequestedEvent $e) use ($client): void {
        $client->closeWindow();
    },
);

$registry->addListener(X11ExposeEvent::class,    new ExposeHandler($client, $logger));
$registry->addListener(X11ConfigureEvent::class, new ConfigureHandler($client, $logger));
$registry->addListener(
    X11ConfigureEvent::class,
    static function (X11ConfigureEvent $e) use ($client, $frame, $layout, $renderer): PromiseInterface {
        if ($e->windowId === $client->getWindowId()) {
            $layout($e->width, $e->height);
            $client->setWindowShape($frame->shapeRects($renderer));
            $client->redraw();
        }

        return resolve(null);
    },
);

$tasks->add('Read example/app.php');
$tasks->add('Try F2 in example/calculator.php');
$tasks->add('Open this list from File > Open');
$layout(460, 380);
$refresh();

$client->setWindowTitle('To-do');
$client->setWindowSize(460, 380);
$client->setDecorated(false);
$client->connect();

Loop::run();
