<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// The Canvas tab's geometry, which is a plain class with no idea it is being
// drawn by a widget — see example/lib/ and tests/wirecube_test.php.
require __DIR__ . '/lib/WireCube.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\IconName;
use Cyrnetix\X11\Drawing\IconRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Filesystem\DirectoryLister;
use Cyrnetix\X11\Filesystem\FileFilter;
use Cyrnetix\X11\Filesystem\FilePlaces;
use Cyrnetix\X11\Filesystem\FilesystemTreeProvider;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ErrorEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Event\X11FocusInEvent;
use Cyrnetix\X11\Event\X11FocusOutEvent;
use Cyrnetix\X11\Event\X11KeyPressEvent;
use Cyrnetix\X11\Event\X11MapEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Event\X11SelectionClearEvent;
use Cyrnetix\X11\Event\X11SelectionNotifyEvent;
use Cyrnetix\X11\Event\X11SelectionRequestEvent;
use Cyrnetix\X11\Event\X11UnmapEvent;
use Cyrnetix\X11\Handler\ButtonPressHandler;
use Cyrnetix\X11\Handler\ConfigureHandler;
use Cyrnetix\X11\Handler\ErrorHandler;
use Cyrnetix\X11\Handler\ExposeHandler;
use Cyrnetix\X11\Handler\FocusHandler;
use Cyrnetix\X11\Handler\KeyPressHandler;
use Cyrnetix\X11\Handler\MapHandler;
use Cyrnetix\X11\Handler\MotionHandler;
use Cyrnetix\X11\Handler\UnmapHandler;
use Cyrnetix\X11\Logging\ColoredLineFormatter;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Fluent\FluentTheme;
use Cyrnetix\X11\Theme\Material\MaterialTheme;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\Theme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win31\Win31Theme;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Event\ButtonClickedEvent;
use Cyrnetix\X11\UI\Event\CheckboxToggledEvent;
use Cyrnetix\X11\UI\Event\ComboBoxChangedEvent;
use Cyrnetix\X11\UI\Event\DateTimeChangedEvent;
use Cyrnetix\X11\UI\Event\DropDownChangedEvent;
use Cyrnetix\X11\UI\Event\ListSelectionChangedEvent;
use Cyrnetix\X11\UI\Event\ListViewItemActivatedEvent;
use Cyrnetix\X11\UI\Event\ListViewSelectionChangedEvent;
use Cyrnetix\X11\UI\Event\ProgressBarChangedEvent;
use Cyrnetix\X11\UI\Event\ToolbarButtonClickedEvent;
use Cyrnetix\X11\UI\Event\TrackbarChangedEvent;
use Cyrnetix\X11\UI\Event\FileDialogClosedEvent;
use Cyrnetix\X11\UI\Event\MessageBoxClosedEvent;
use Cyrnetix\X11\UI\Event\RadioToggledEvent;
use Cyrnetix\X11\UI\Event\ScrollChangedEvent;
use Cyrnetix\X11\UI\Event\TextSelectionChangedEvent;
use Cyrnetix\X11\UI\Event\TreeNodeSelectedEvent;
use Cyrnetix\X11\UI\Event\UpDownChangedEvent;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\MessageBoxFlags;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\Example\WireCube;
use Cyrnetix\X11\UI\Painter\ButtonPainter;
use Cyrnetix\X11\UI\Painter\CanvasPainter;
use Cyrnetix\X11\UI\Painter\CheckboxPainter;
use Cyrnetix\X11\UI\Painter\ComboBoxPainter;
use Cyrnetix\X11\UI\Painter\DateTimePickerPainter;
use Cyrnetix\X11\UI\Painter\DropDownPainter;
use Cyrnetix\X11\UI\Painter\GroupBoxPainter;
use Cyrnetix\X11\UI\Painter\LabelPainter;
use Cyrnetix\X11\UI\Painter\ListBoxPainter;
use Cyrnetix\X11\UI\Painter\ListViewPainter;
use Cyrnetix\X11\UI\Painter\ProgressBarPainter;
use Cyrnetix\X11\UI\Painter\RebarPainter;
use Cyrnetix\X11\UI\Painter\ToolbarPainter;
use Cyrnetix\X11\UI\Painter\TrackbarPainter;
use Cyrnetix\X11\UI\Painter\MenuBarPainter;
use Cyrnetix\X11\UI\Painter\FileDialogPainter;
use Cyrnetix\X11\UI\Painter\MessageBoxPainter;
use Cyrnetix\X11\UI\Painter\RadioButtonPainter;
use Cyrnetix\X11\UI\Painter\ScrollBarPainter;
use Cyrnetix\X11\UI\Painter\StatusBarPainter;
use Cyrnetix\X11\UI\Painter\TabViewPainter;
use Cyrnetix\X11\UI\Painter\TextBoxPainter;
use Cyrnetix\X11\UI\Painter\TextViewPainter;
use Cyrnetix\X11\UI\Painter\TreeViewPainter;
use Cyrnetix\X11\UI\Painter\UpDownPainter;
use Cyrnetix\X11\UI\Painter\WindowFramePainter;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\WidgetTree;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\Canvas;
use Cyrnetix\X11\UI\Widget\Checkbox;
use Cyrnetix\X11\UI\Widget\ComboBox;
use Cyrnetix\X11\UI\Widget\DateTimePicker;
use Cyrnetix\X11\UI\Widget\DropDown;
use Cyrnetix\X11\UI\Widget\GroupBox;
use Cyrnetix\X11\UI\Widget\Label;
use Cyrnetix\X11\UI\Widget\ListBox;
use Cyrnetix\X11\UI\Widget\ListView;
use Cyrnetix\X11\UI\Widget\ProgressBar;
use Cyrnetix\X11\UI\Widget\Rebar;
use Cyrnetix\X11\UI\Widget\RebarBand;
use Cyrnetix\X11\UI\Widget\Toolbar;
use Cyrnetix\X11\UI\Widget\ToolbarItem;
use Cyrnetix\X11\UI\Widget\Trackbar;
use Cyrnetix\X11\UI\Widget\ListViewItem;
use Cyrnetix\X11\UI\Widget\Menu;
use Cyrnetix\X11\UI\Widget\MenuBar;
use Cyrnetix\X11\UI\Widget\MenuItem;
use Cyrnetix\X11\UI\Widget\FileDialog;
use Cyrnetix\X11\UI\Widget\FileDialogMode;
use Cyrnetix\X11\UI\Widget\MessageBox;
use Cyrnetix\X11\UI\Widget\RadioButton;
use Cyrnetix\X11\UI\Widget\ScrollBar;
use Cyrnetix\X11\UI\Widget\ScrollOrientation;
use Cyrnetix\X11\UI\Widget\StatusBar;
use Cyrnetix\X11\UI\Widget\StatusBarPane;
use Cyrnetix\X11\UI\Widget\TabView;
use Cyrnetix\X11\UI\Widget\TextBox;
use Cyrnetix\X11\UI\Widget\TextView;
use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeView;
use Cyrnetix\X11\UI\Widget\UpDown;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetManager;
use function React\Promise\resolve;

// --- Logger setup -----------------------------------------------------------

$handler = new StreamHandler('php://stdout', Level::Debug);
$handler->setFormatter(new ColoredLineFormatter());

$logger = new Logger('x11');
$logger->pushHandler($handler);

// --- Themes -----------------------------------------------------------------
// The drawing layer is entirely separate from the widget logic: painters ask the
// active theme for chrome + colours, and widgets ask it for their measurements.
// Registration order is also menu order; the first one is the default.

$themes = new ThemeManager(
    new Win9xTheme(),
    new Win31Theme(),
    new PlatinumTheme(),
    new CdeTheme(),
    new BeOsTheme(),
    new FluentTheme(),
    new MaterialTheme(),
);

// --theme=<id> or --theme=<id>:<variant> (or X11_THEME) picks the starting
// look. The variant is optional and separated by a colon — `win9x:dark`,
// `fluent:light` — because a theme and its colours are one choice to a user
// even though they are two to the manager.
// --decorations=wm hands the title bar back to the window manager.
$requestedTheme   = getenv('X11_THEME') ?: null;
$requestedVariant = null;
$themedFrame    = (getenv('X11_DECORATIONS') ?: 'theme') !== 'wm';
$initialTab     = 0;
$initialPicker  = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--theme=')) {
        $requestedTheme = substr($arg, 8);
    }
    if (str_starts_with($arg, '--decorations=')) {
        $themedFrame = substr($arg, 14) !== 'wm';
    }
    if (str_starts_with($arg, '--tab=')) {
        $initialTab = (int) substr($arg, 6);
    }
    // --file-dialog=open|save|folder opens the picker on startup, which is how
    // to eyeball one mode's layout across all four themes without clicking.
    if (str_starts_with($arg, '--file-dialog=')) {
        $initialPicker = match (substr($arg, 14)) {
            'save'   => FileDialogMode::SaveFile,
            'folder' => FileDialogMode::OpenFolder,
            default  => FileDialogMode::OpenFile,
        };
    }
}
if ($requestedTheme !== null) {
    // "id" or "id:variant". Splitting here rather than in the argument loop
    // keeps X11_THEME and --theme= on exactly the same footing.
    [$requestedTheme, $requestedVariant] = array_pad(explode(':', $requestedTheme, 2), 2, null);

    $themes->select($requestedTheme, $requestedVariant);

    if (!$themes->has($requestedTheme)) {
        $logger->warning('Unknown theme, keeping the default', [
            'requested' => $requestedTheme,
            'available' => $themes->ids(),
        ]);
    } elseif ($requestedVariant !== null && $themes->currentVariant() !== $requestedVariant) {
        // Selecting is deliberately forgiving about a variant it does not know,
        // so this is the only place that can tell the user it was ignored.
        $logger->warning('Unknown variant, using the theme default', [
            'requested' => $requestedVariant,
            'available' => array_keys($themes->variants()),
        ]);
    }
}

// --- Wiring -----------------------------------------------------------------

$loop = Factory::create();

$registry   = new ListenerRegistry();
$dispatcher = new AsyncEventDispatcher($registry, $logger);
$renderer   = new Renderer();
$client     = new X11Client($loop, $dispatcher, $logger, $renderer, $themes);

// When the toolkit draws the caption, the window manager is asked not to. The
// hint is read at map time, so this has to happen before connect().
$client->setWindowTitle('PHP X11 Toolkit');
// The live debug readout is off unless asked for: it paints in raw window
// coordinates over whatever the app drew there. This demo wants it.
$client->setOverlayVisible(true);

$client->setWindowSize(620, 460);

// --- UI layer ---------------------------------------------------------------

$uiRegistry   = new ListenerRegistry();
$uiDispatcher = new SyncEventDispatcher($uiRegistry);

$messageBox = new MessageBox($uiDispatcher, $themes);

// --- Widget handlers --------------------------------------------------------
// Each handler owns its own capture state + painter, exposes try{Press,
// Release,Motion,Key} for input and paint() for drawing. WidgetManager walks
// them in this exact order; order is policy (see WidgetManager class doc).

$widgetTree    = new WidgetTree($themes);
$doubleClick   = new DoubleClickDetector();

// Shared icon registry. It follows the live theme: each theme names an icon set
// under resource/themes/ (Windows 2000 .ico files, Mac OS 9 .png ones) and the
// registry picks the loader by extension, so switching themes swaps the icons
// too. Themes without a set of their own fall back to windows2000.
// themesRoot and loaders default to what the package ships, so an app that just
// wants the bundled sets needs neither. Both are still arguments: point
// themesRoot at your own directory to ship different artwork, and add a loader
// to introduce a format the shipped sets don't use.
$iconRegistry = new IconRegistry(
    themes:     $themes,
    defaultSet: 'windows2000',
    logger:     $logger,
    iconSize:   $themes->metrics()->treeIconSize,
);

$treeViewHandler = new Cyrnetix\X11\UI\Handler\TreeViewHandler(
    $widgetTree, $client, $doubleClick, new TreeViewPainter($themes)
);

$windowFrame = new WindowFrame(620, 460, 'PHP X11 Toolkit', drawsChrome: $themedFrame);

// The file dialog is a real top-level window — override-redirect, drawn by the
// toolkit, draggable anywhere on screen — but built out of ordinary widgets, so
// every handler below applies to its contents unchanged. It joins the widget
// tree as its own root (its coordinates are local to its own window) and is
// painted separately, into that window's Renderer.
$fileDialog = new FileDialog(
    width:  560,
    height: 400,
    dispatcher:        $uiDispatcher,
    folderIconDrawer:  $iconRegistry->iconDrawer(IconName::Folder),
    fileIconDrawer:    $iconRegistry->iconDrawer(IconName::File),
    renderer:          $renderer,
);
$fileDialog->setPlaces((new FilePlaces())->all());

$fileDialogHandler = new Cyrnetix\X11\UI\Handler\FileDialogHandler(
    $widgetTree, $client, new DirectoryLister(), $renderer, $logger,
    new FileDialogPainter($themes), $doubleClick,
);

$handlers = [
    // The modal file dialog goes first: while it's open it swallows motion
    // outside itself, so nothing behind it lights up under the cursor. Presses
    // inside it fall through to the widgets it's built from.
    $fileDialogHandler,

    // Tooltip observes all motion + press to drive its dwell timer, but
    // returns false from every try* so downstream handlers still run.
    // First in the list = sees every event regardless of who consumes.
    new Cyrnetix\X11\UI\Handler\TooltipHandler($widgetTree, $client),

    // The window frame claims the caption + border before anything else looks
    // at the click; its hit areas sit outside the client area, so nothing else
    // competes for them.
    new Cyrnetix\X11\UI\Handler\WindowFrameHandler(
        $widgetTree, $client, $uiDispatcher, new DoubleClickDetector(), $renderer, new WindowFramePainter($themes)
    ),

    // MenuBar + DateTimePicker still have purely painter-driven popups —
    // they capture clicks themselves so they run before everything.
    new Cyrnetix\X11\UI\Handler\MenuBarHandler($widgetTree, $client, $renderer, new MenuBarPainter($themes)),
    new Cyrnetix\X11\UI\Handler\DateTimePickerHandler($widgetTree, $client, $renderer, new DateTimePickerPainter($themes)),

    // ScrollBar + ListBox handlers run BEFORE DropDown/ComboBox so that
    // when a popup is open, clicks INSIDE the popup (on the inner
    // ListBox or its scrollbar) reach the real widget's handlers. The
    // popup-owner handlers only catch field clicks + outside-click
    // dismissal — neither of which the scrollbar/listbox handlers
    // would have matched.
    new Cyrnetix\X11\UI\Handler\ScrollBarHandler($widgetTree, $client, new ScrollBarPainter($themes)),
    new Cyrnetix\X11\UI\Handler\ListBoxHandler($widgetTree, $client, new ListBoxPainter($themes)),

    new Cyrnetix\X11\UI\Handler\DropDownHandler($widgetTree, $client, new DropDownPainter($themes)),
    new Cyrnetix\X11\UI\Handler\ComboBoxHandler($widgetTree, $client, new ComboBoxPainter($themes)),

    // EditableText drag-to-select reacts to whichever EditableText was
    // just focused by the WidgetManager focus block.
    new Cyrnetix\X11\UI\Handler\EditableTextHandler($widgetTree, $client, $renderer),

    // Standard interactive widgets.
    new Cyrnetix\X11\UI\Handler\TabViewHandler($widgetTree, $client, $logger, $renderer, new TabViewPainter($themes)),
    new Cyrnetix\X11\UI\Handler\TrackbarHandler($widgetTree, $client, new TrackbarPainter($themes)),
    new Cyrnetix\X11\UI\Handler\UpDownHandler($widgetTree, $client, new UpDownPainter($themes)),
    new Cyrnetix\X11\UI\Handler\ToolbarHandler($widgetTree, $client, new ToolbarPainter($themes, $iconRegistry)),
    new Cyrnetix\X11\UI\Handler\ButtonHandler($widgetTree, $client, $logger, new ButtonPainter($themes)),
    new Cyrnetix\X11\UI\Handler\CheckboxHandler($widgetTree, $client, $renderer, $logger, new CheckboxPainter($themes)),
    new Cyrnetix\X11\UI\Handler\RadioButtonHandler($widgetTree, $client, $renderer, $logger, new RadioButtonPainter($themes)),
    new Cyrnetix\X11\UI\Handler\ListViewHandler($widgetTree, $client, $doubleClick, new ListViewPainter($themes)),
    new Cyrnetix\X11\UI\Handler\CanvasHandler($widgetTree, $client, new CanvasPainter($themes)),
    $treeViewHandler,

    // Display-only handlers (paint() only, all try* return false).
    // ProgressBarHandler also runs its marquee timer here — not interactive.
    new Cyrnetix\X11\UI\Handler\ProgressBarHandler($widgetTree, $client, new ProgressBarPainter($themes)),
    new Cyrnetix\X11\UI\Handler\RebarHandler(new RebarPainter($themes)),
    new Cyrnetix\X11\UI\Handler\TextViewHandler($widgetTree, $client, new TextViewPainter($themes)),
    new Cyrnetix\X11\UI\Handler\TextBoxHandler(new TextBoxPainter($themes)),
    new Cyrnetix\X11\UI\Handler\LabelHandler(new LabelPainter($themes)),
    new Cyrnetix\X11\UI\Handler\GroupBoxHandler(new GroupBoxPainter($themes)),
    new Cyrnetix\X11\UI\Handler\StatusBarHandler(new StatusBarPainter($themes)),
    new Cyrnetix\X11\UI\Handler\SeparatorHandler(new Cyrnetix\X11\UI\Painter\SeparatorPainter($themes)),
];

$widgetManager = new WidgetManager(
    $client,
    $renderer,
    $widgetTree,
    new MessageBoxPainter($themes),
    new KeyTranslator(),
    $logger,
    $handlers,
    $treeViewHandler,
);
$client->setWidgetManager($widgetManager);
$widgetManager->setDialog($messageBox);

// --- Menu bar at the very top -------------------------------------------
// Sample icon drawers: the painter calls these with (Renderer, x, y, size=14).
$saveIcon = static function ($r, $x, $y, $size): void {
    $r->setForeground(  0,   0, 128); $r->fillRect($x,     $y,     14, 14);
    $r->setForeground(220, 220, 220); $r->fillRect($x + 2, $y + 1,  4,  4);
    $r->setForeground(255, 255, 255); $r->fillRect($x + 2, $y + 8, 10,  5);
    $r->setForeground(  0,   0,   0); $r->drawLines([[$x + 3, $y + 10, $x + 10, $y + 10]]);
};
$openIcon = static function ($r, $x, $y, $size): void {
    $r->setForeground(120,  90,  20); $r->fillRect($x + 1, $y + 3, 12,  9);
    $r->setForeground(255, 210,  60); $r->fillRect($x,     $y + 4, 14,  9);
    $r->setForeground(255, 230, 130); $r->fillRect($x + 1, $y + 2,  5,  2);
};
$cutIcon = static function ($r, $x, $y, $size): void {
    $r->setForeground( 64,  64,  64);
    $r->drawLines([
        [$x + 2, $y + 2,  $x + 11, $y + 11],
        [$x + 11, $y + 2, $x + 2,  $y + 11],
    ]);
    $r->setForeground(200,  60,  60); $r->fillCircle($x + 3,  $y + 11, 2);
    $r->setForeground( 60,  60, 200); $r->fillCircle($x + 10, $y + 11, 2);
};

$recent = (new Menu())
    ->addItem(new MenuItem('readme.txt',   onClick: static fn() => $logger->info('Open recent', ['file' => 'readme.txt'])))
    ->addItem(new MenuItem('config.json',  onClick: static fn() => $logger->info('Open recent', ['file' => 'config.json'])))
    ->addItem(new MenuItem('main.cpp',     onClick: static fn() => $logger->info('Open recent', ['file' => 'main.cpp'])));

// The three modes the file dialog is configurable between, wired to the menu.
// Each one hands the dialog its filters and start directory, then the handler
// takes input and reads the folder.
$fileFilters = [
    new FileFilter('PHP source', ['*.php']),
    new FileFilter('Text and markdown', ['*.txt', '*.md']),
    new FileFilter('Images', ['*.png', '*.jpg', '*.jpeg', '*.gif', '*.bmp', '*.ico']),
    FileFilter::all(),
];

$openPicker = static function (FileDialogMode $mode) use ($fileDialog, $fileDialogHandler, $fileFilters): void {
    $fileDialog->open(
        $mode,
        dirname(__DIR__),
        $mode->hasFilterField() ? $fileFilters : [],
        suggestedName: $mode->isSave() ? 'untitled.txt' : '',
    );
    $fileDialogHandler->show($fileDialog);
};

$openFile   = static fn() => $openPicker(FileDialogMode::OpenFile);
$saveFile   = static fn() => $openPicker(FileDialogMode::SaveFile);
$openFolder = static fn() => $openPicker(FileDialogMode::OpenFolder);

$fileMenu = (new Menu('File'))
    ->addItem(new MenuItem('New',           'Ctrl+N', static fn() => $logger->info('Menu: New')))
    ->addItem(new MenuItem('Open...',       'Ctrl+O', $openFile,   iconDrawer: $openIcon))
    ->addItem(new MenuItem('Open Folder...', null,    $openFolder, iconDrawer: $openIcon))
    ->addItem(new MenuItem('Save As...',    'Ctrl+S', $saveFile,   iconDrawer: $saveIcon))
    ->addSeparator()
    ->addItem(new MenuItem('Recent',        submenu: $recent))
    ->addSeparator()
    ->addItem(new MenuItem('Exit',          'Alt+F4', static fn() => $logger->info('Menu: Exit')));

$editMenu = (new Menu('Edit'))
    ->addItem(new MenuItem('Cut',          'Ctrl+X', static fn() => $logger->info('Menu: Cut'),    iconDrawer: $cutIcon))
    ->addItem(new MenuItem('Copy',         'Ctrl+C', static fn() => $logger->info('Menu: Copy')))
    ->addItem(new MenuItem('Paste',        'Ctrl+V', static fn() => $logger->info('Menu: Paste'),  enabled: false))
    ->addSeparator()
    ->addItem(new MenuItem('Select All',   'Ctrl+A', static fn() => $logger->info('Menu: Select All')));

$helpMenu = (new Menu('Help'))
    ->addItem(new MenuItem('Word Wrap',    null,     static fn() => $logger->info('Menu: Toggle wrap'), checked: true))
    ->addSeparator()
    ->addItem(new MenuItem('About...',     'F1',     static fn() => $logger->info('Menu: About')));

// View → one item per registered theme, and for a theme with more than one
// colour variant, a submenu of those. Clicking an item only calls
// ThemeManager::select(); the onChange listener registered further down does the
// relayout + repaint, so nothing here knows how a switch is applied.
//
// The variants hang under their theme rather than in a list of their own
// because that is the shape of the choice: "Windows 9x, dark" is one decision
// made in two steps, and a flat menu would have to repeat Dark once per era.
$viewMenu     = new Menu('View');
$themeItems   = [];
$variantItems = [];

foreach ($themes->all() as $themeId => $theme) {
    $variants = $theme->variants();

    if (count($variants) < 2) {
        $item = new MenuItem(
            $theme->name(),
            onClick: static function () use ($themes, $themeId): void { $themes->select($themeId); },
            checked: $themeId === $themes->currentId(),
        );
        $themeItems[$themeId] = $item;
        $viewMenu->addItem($item);

        continue;
    }

    $submenu = new Menu($theme->name());
    foreach ($variants as $variantId => $variantName) {
        $variantItem = new MenuItem(
            $variantName,
            onClick: static function () use ($themes, $themeId, $variantId): void {
                $themes->select($themeId, $variantId);
            },
            checked: $themeId === $themes->currentId() && $variantId === $themes->currentVariant(),
        );

        $variantItems[$themeId . ':' . $variantId] = $variantItem;
        $submenu->addItem($variantItem);
    }

    $item = new MenuItem($theme->name(), submenu: $submenu, checked: $themeId === $themes->currentId());
    $themeItems[$themeId] = $item;
    $viewMenu->addItem($item);
}

// Everything lives inside the frame, so the caption's height comes out of the
// client area automatically — app coordinates stay relative to the content.
$widgetManager->addChild($windowFrame);

$menuBar = new MenuBar(0, 0, 620);
$menuBar->addMenu($fileMenu);
$menuBar->addMenu($editMenu);
$menuBar->addMenu($viewMenu);
$menuBar->addMenu($helpMenu);
$windowFrame->addChild($menuBar);

// --- Status bar pinned to the bottom -----------------------------------
$statusBar = new StatusBar(0, 460 - $themes->metrics()->statusBarHeight, 620);
$statusBar->addPane(new StatusBarPane('Ready'));                     // stretches
$statusBar->addPane(new StatusBarPane('Ln 1, Col 1', 100));
$statusBar->addPane(new StatusBarPane('X=0 Y=0',      90));
$windowFrame->addChild($statusBar);

// --- Tab view, shifted down to clear the menu bar + overlay text, and
//     shortened to leave room for the status bar at the bottom ----------
$tabView = new TabView(10, 104, 600, 320);

$general = $tabView->addTab('General');
$options = $tabView->addTab('Options');
$about   = $tabView->addTab('About');

$actionsGroup = new GroupBox('Actions', 12, 12, 320, 88);
$general->addChild($actionsGroup);
$actionsGroup->addChild(new Label('Choose an action:', 2, 2));
$actionsGroup->addChild(new Button('OK',      2, 26, 80, 24, $uiDispatcher));
$actionsGroup->addChild(new Button('Cancel', 94, 26, 80, 24, $uiDispatcher));
$actionsGroup->addChild(new Checkbox('Auto-close', 192, 30, $uiDispatcher));

$settingsGroup = new GroupBox('Settings', 12, 12, 320, 92);
$options->addChild($settingsGroup);
$settingsGroup->addChild(new Label('Name:', 2, 2, lineHeight: 22));
$settingsGroup->addChild(new TextBox(48, 2, 240, 22, dispatcher: $uiDispatcher));
$settingsGroup->addChild(new Button('Apply', 48, 36, 80, 24, $uiDispatcher));

$about->addChild(new Label('PHP X11 Demo  v0.1', 14, 14));

// Radio buttons share a group implicitly through their parent container —
// drop them in the same GroupBox and they form a mutually-exclusive set.
$themeGroup = new GroupBox('Appearance', 12, 44, 320, 52);
$about->addChild($themeGroup);
$themeGroup->addChild(new RadioButton('Classic',   2, 6, $uiDispatcher, true));
$themeGroup->addChild(new RadioButton('Modern',  104, 6, $uiDispatcher));
$themeGroup->addChild(new RadioButton('Custom',  206, 6, $uiDispatcher));

// A read-only multi-line view: scrolls both ways, selects by the line, and
// Ctrl+C takes the selection (or the whole thing) to the clipboard.
$aboutText = new TextView(12, 104, 380, 120, $uiDispatcher, implode("\n", [
    'PHP X11 Toolkit',
    '',
    'A from-scratch X11 client: no Xlib, no xcb - pack() and unpack() over a',
    'Unix-domain socket, driven by the ReactPHP event loop.',
    '',
    'Click a line to select it, drag to select more, Ctrl+A for all of it,',
    'then Ctrl+C. This line is deliberately long enough to need the horizontal',
    'scrollbar that appears below when the widest line overflows the viewport.',
]));
$about->addChild($aboutText);

// ---- Files (ListBox) demo tab ---------------------------------------------
$files    = $tabView->addTab('Files');
$fileList = new ListBox(12, 12, 300, 140, $uiDispatcher);
foreach ([
    'Program.cpp',
    'Main.cpp',
    'Utils.cpp',
    'Renderer.cpp',
    'EventHandler.cpp',
    'NetworkClient.cpp',
    'Database.cpp',
    'FileIO.cpp',
    'Settings.ini',
    'Readme.txt',
    'License.txt',
] as $name) {
    $fileList->addItem($name);
}
$files->addChild($fileList);

// ---- DropDown + ComboBox demo tab -----------------------------------------
$more = $tabView->addTab('More');
$fieldH   = $themes->metrics()->fieldHeight;
$rowPitch = $fieldH + 14;
$rowY     = 14;

$more->addChild(new Label('Country:', 12, $rowY, lineHeight: $fieldH));
$countryDd = new DropDown(96, $rowY, 220, $uiDispatcher, maxVisibleItems: 4);
foreach ([
    'Netherlands', 'United States', 'Germany', 'Japan', 'Brazil',
    'France', 'Italy', 'Spain', 'United Kingdom', 'Canada',
    'Australia', 'New Zealand', 'Sweden', 'Norway', 'Denmark',
] as $country) {
    $countryDd->addItem($country);
}
$more->addChild($countryDd);

// Editable combobox — type freely OR pick a suggestion.
$rowY += $rowPitch;
$more->addChild(new Label('Search:', 12, $rowY, lineHeight: $fieldH));
$searchCb = new ComboBox(96, $rowY, 220, $uiDispatcher, maxVisibleItems: 4);
foreach ([
    'readme.txt', 'package.json', 'main.cpp', 'index.html',
    'styles.css', 'config.yaml', 'Dockerfile', 'Makefile',
    'composer.json', 'setup.py',
] as $file) {
    $searchCb->addItem($file);
}
$more->addChild($searchCb);

// Up-Down spinner buddy-linked to a narrow numeric TextBox.
$rowY += $rowPitch;
$spinH  = $themes->metrics()->upDownHeight;
$more->addChild(new Label('Qty:', 12, $rowY, lineHeight: $spinH));
$qtyBox     = new TextBox(96, $rowY, 60, $spinH, dispatcher: $uiDispatcher);
$qtySpinner = new UpDown(158, $rowY, min: 0, max: 99, value: 5, step: 1, dispatcher: $uiDispatcher, buddy: $qtyBox);
$more->addChild($qtyBox);
$more->addChild($qtySpinner);

$uiRegistry->addListener(UpDownChangedEvent::class, static function (UpDownChangedEvent $e) use ($logger): void {
    $logger->info('UpDown changed', ['value' => $e->upDown->getValue()]);
});

// Date picker — segments + dropdown calendar. Initial value is today.
$rowY += $rowPitch;
$more->addChild(new Label('Date:', 12, $rowY, lineHeight: $fieldH));
$datePicker = new DateTimePicker(96, $rowY, 220, $uiDispatcher);
$more->addChild($datePicker);

$uiRegistry->addListener(DateTimeChangedEvent::class, static function (DateTimeChangedEvent $e) use ($logger): void {
    $logger->info('DateTimePicker changed', ['value' => $e->picker->getValue()->format('Y-m-d')]);
});

// ---- Progress demo tab ----------------------------------------------------
// Determinate bar driven by an UpDown spinner + a marquee bar whose handler
// ticks its offset automatically. ProgressBarHandler lazily starts a 20fps
// periodic timer on the first marquee bar it paints; the timer self-cancels
// when no marquee bars are alive.
$progressTab = $tabView->addTab('Progress');

$spinH = $themes->metrics()->upDownHeight;
$progressTab->addChild(new Label('Percent:', 12, 14, lineHeight: $spinH));
$progressInput = new TextBox(96, 14, 60, $spinH, dispatcher: $uiDispatcher);
$progressInput->setText('25');
$progressSpinner = new UpDown(158, 14, min: 0, max: 100, value: 25, step: 1, dispatcher: $uiDispatcher, buddy: $progressInput);
$progressTab->addChild($progressInput);
$progressTab->addChild($progressSpinner);

$progressTab->addChild(new Label('Progress:', 12, 56, lineHeight: 20));
$progressBar = new ProgressBar(96, 56, 300, 20, $uiDispatcher, min: 0, max: 100, value: 25);
$progressTab->addChild($progressBar);

$progressTab->addChild(new Label('Working:', 12, 88, lineHeight: 20));
$marqueeBar = new ProgressBar(96, 88, 300, 20, $uiDispatcher);
$marqueeBar->setMarquee(true);
$progressTab->addChild($marqueeBar);

$uiRegistry->addListener(UpDownChangedEvent::class, static function (UpDownChangedEvent $e) use ($progressSpinner, $progressBar): void {
    if ($e->upDown === $progressSpinner) {
        $progressBar->setValue($e->upDown->getValue());
    }
});

$uiRegistry->addListener(ProgressBarChangedEvent::class, static function (ProgressBarChangedEvent $e) use ($logger): void {
    $logger->info('ProgressBar changed', ['value' => $e->bar->getValue()]);
});

// ---- Tree demo tab (real filesystem) --------------------------------------
$tree = $tabView->addTab('Tree');

// Drawer closures wrapping the registry — feed straight into
// TreeNode / ListViewItem iconDrawer slots.
$folderIcon     = $iconRegistry->iconDrawer(IconName::Folder);
$folderOpenIcon = $iconRegistry->iconDrawer(IconName::FolderOpen);
$fileIcon       = $iconRegistry->iconDrawer(IconName::File);

// Filesystem-backed provider. scandir is fast — the 0.35s timer is so the
// busy mouse cursor is visibly toggled during the load.
$fsProvider = new FilesystemTreeProvider(
    loop:            $loop,
    folderIcon:      $folderIcon,
    folderOpenIcon:  $folderOpenIcon,
    fileIcon:        $fileIcon,
    simulatedDelay:  0.35,
);

// Explorer-style rebar across the top: row 1 = navigation toolbar +
// address bar + search; row 2 = an edit toolbar. The TreeView + ListView
// sit below.
//
// Tab content area is ~340x124; rebar takes 2 rows (~50px) at the top.

$navToolbar = new Toolbar(0, 0, $uiDispatcher);
$navToolbar
    ->addItem(ToolbarItem::button('back',    IconName::Folder,    'Back',    static fn() => $logger->info('Nav: back')))
    ->addItem(ToolbarItem::button('forward', IconName::FolderOpen, 'Forward', static fn() => $logger->info('Nav: forward')))
    ->addItem(ToolbarItem::button('up',      IconName::Folder,    'Up',      static fn() => $logger->info('Nav: up')))
    ->addItem(ToolbarItem::separator())
    ->addItem(ToolbarItem::button('refresh', IconName::Find,      'Refresh', static fn() => $logger->info('Nav: refresh')));

$addressBox = new TextBox(0, 0, 100, 20, dispatcher: $uiDispatcher);
$searchBox  = new TextBox(0, 0,  70, 20, dispatcher: $uiDispatcher);

$editToolbar = new Toolbar(0, 0, $uiDispatcher);
$editToolbar
    ->addItem(ToolbarItem::button('cut',    IconName::ConfigSettings, 'Cut',    static fn() => $logger->info('Edit: cut')))
    ->addItem(ToolbarItem::button('copy',   IconName::TextDocument,   'Copy',   static fn() => $logger->info('Edit: copy')))
    ->addItem(ToolbarItem::button('paste',  IconName::TextDocument,   'Paste',  static fn() => $logger->info('Edit: paste')))
    ->addItem(ToolbarItem::separator())
    ->addItem(ToolbarItem::button('delete', IconName::RecycleBinFull, 'Delete', static fn() => $logger->info('Edit: delete')));

$rebar = new Rebar(8, 6, 580);   // width is re-set from the page in $applyLayout
// width: 0 = hug the grip + caption + child, so band sizes follow the theme.
$rebar->addBand(new RebarBand(child: $navToolbar, width: 0));
$rebar->addBand(new RebarBand(
    child:        $addressBox,
    caption:      'Address:',
    captionWidth: 50,
    fillWidth:    true,
));
$rebar->addBand(new RebarBand(
    child:        $searchBox,
    caption:      'Find:',
    captionWidth: 28,
    width:        0,
));
$rebar->newRow();
$rebar->addBand(new RebarBand(child: $editToolbar, width: 0));
$tree->addChild($rebar);

// Tree + list sit below the rebar; $applyLayout re-derives both from the page's
// usable height, since the rebar's own height moves with the theme.
$treeView = new TreeView(8, 6 + $rebar->height + 6, 180, 120, $uiDispatcher, $fsProvider);

$homeDir = getenv('HOME') ?: '/';
$treeView->addRoot(new TreeNode(
    label:              $homeDir,
    iconDrawer:         $folderIcon,
    loaded:             false,            // children come from the provider
    data:               $homeDir,
    expandedIconDrawer: $folderOpenIcon,
));
$tree->addChild($treeView);

$listView = new ListView(196, 6 + $rebar->height + 6, 380, 120, $uiDispatcher);
$listView->addColumn('Name', 200);
$listView->addColumn('Size',  80);
$listView->addColumn('Type',  80);
$tree->addChild($listView);

// Whenever a tree node is selected (clicked OR auto-selected after expand),
// repopulate the ListView with that node's currently-loaded children.
$uiRegistry->addListener(TreeNodeSelectedEvent::class, static function (TreeNodeSelectedEvent $e) use ($listView): void {
    $listView->clearItems();
    foreach ($e->node->getChildren() as $child) {
        $size = '';
        if ($child->loaded && is_string($child->data) && @is_file($child->data)) {
            $bytes = @filesize($child->data);
            if ($bytes !== false) {
                $size = $bytes >= 1024 * 1024
                    ? sprintf('%.1f MB', $bytes / 1048576)
                    : ($bytes >= 1024 ? sprintf('%.1f KB', $bytes / 1024) : (string) $bytes);
            }
        }
        $listView->addItem(new ListViewItem(
            values:     [$child->label, $size, $child->loaded ? 'File' : 'Folder'],
            iconDrawer: $child->iconDrawer,
            data:       $child->data,
        ));
    }
});

$uiRegistry->addListener(ListViewSelectionChangedEvent::class, static function (ListViewSelectionChangedEvent $e) use ($logger): void {
    $item = $e->item;
    $logger->info('ListView selection', [
        'name' => $item?->values[0] ?? null,
        'data' => $item?->data,
    ]);
});

// Double-click a folder in the file list → navigate the tree to it. The
// ListView's rows are always the currently-selected TreeNode's children,
// so we just look for a child whose data (full path) matches the activated
// item's data and ask the WidgetManager to select it.
$uiRegistry->addListener(ListViewItemActivatedEvent::class, static function (ListViewItemActivatedEvent $e) use ($treeView, $widgetManager, $logger): void {
    $logger->info('ListView activated', ['data' => $e->item->data]);

    $current = $treeView->getSelected();
    if ($current === null) return;

    foreach ($current->getChildren() as $child) {
        if ($child->data === $e->item->data) {
            // Only navigate into directories; activating a file is a no-op
            // for now (would be the place to open it in an editor).
            if (!$child->loaded) {
                $widgetManager->selectTreeNode($treeView, $child);
            }
            return;
        }
    }
});

// ---- Toolbar demo tab -----------------------------------------------------
// Flat Win2k-style toolbar built from real .ico system icons. Two groups
// separated by a thin groove, plus a few toggle buttons that stay sunken
// while checked.
$tbTab = $tabView->addTab('Toolbar');
$toolbar = new Toolbar(12, 14, $uiDispatcher);
$toolbar
    ->addItem(ToolbarItem::button('open',   IconName::Folder,         'Open'))
    ->addItem(ToolbarItem::button('save',   IconName::TextDocument,   'Save'))
    ->addItem(ToolbarItem::separator())
    ->addItem(ToolbarItem::button('cut',    IconName::ConfigSettings, 'Cut'))
    ->addItem(ToolbarItem::button('copy',   IconName::TextDocument,   'Copy'))
    ->addItem(ToolbarItem::button('paste',  IconName::TextDocument,   'Paste'))
    ->addItem(ToolbarItem::separator())
    ->addItem(ToolbarItem::button('find',   IconName::Find,           'Find'))
    ->addItem(ToolbarItem::button('help',   IconName::Help,           'Help'))
    ->addItem(ToolbarItem::separator())
    ->addItem(ToolbarItem::toggle('bold',   IconName::TextDocument,   'Bold'))
    ->addItem(ToolbarItem::toggle('italic', IconName::TextDocument,   'Italic'));
$tbTab->addChild($toolbar);

// Sample second toolbar showing the natural-width property — the
// Rebar coming next will lean on this for band sizing.
$navbar = new Toolbar(12, 56, $uiDispatcher);
$navbar
    ->addItem(ToolbarItem::button('back',     IconName::Folder,    'Back'))
    ->addItem(ToolbarItem::button('forward',  IconName::FolderOpen, 'Forward'))
    ->addItem(ToolbarItem::separator())
    ->addItem(ToolbarItem::button('up',       IconName::Folder,    'Up'))
    ->addItem(ToolbarItem::button('home',     IconName::MyDocuments, 'Home'));
$tbTab->addChild($navbar);

$uiRegistry->addListener(ToolbarButtonClickedEvent::class, static function (ToolbarButtonClickedEvent $e) use ($logger): void {
    $logger->info('Toolbar click', [
        'id'      => $e->item->id,
        'checked' => $e->item->isToggle ? $e->item->checked : null,
    ]);
});

// ---- Scroll demo tab ------------------------------------------------------
$scroll  = $tabView->addTab('Scroll');
$sbW = $themes->metrics()->scrollBarThickness;

$scroll->addChild(new Label('Scroll bars + trackbars', 12, 12));
$scroll->addChild(new Label('drag the thumbs, click the track, roll the wheel', 12, 32));

$vScroll = new ScrollBar(ScrollOrientation::Vertical,   360, 60, $sbW, 140, $uiDispatcher, 0, 100, 10);
$hScroll = new ScrollBar(ScrollOrientation::Horizontal,  12, 60, 320,  $sbW, $uiDispatcher, 0, 100, 10);
$scroll->addChild($vScroll);
$scroll->addChild($hScroll);

// Horizontal trackbar with ticks every 10 values.
$hTrack = new Trackbar(12, 100, 320, 36, $uiDispatcher, ScrollOrientation::Horizontal,
    min: 0, max: 100, value: 30, step: 1, pageSize: 10, tickFrequency: 10);
$scroll->addChild($hTrack);

// Vertical trackbar beside it. Ticks every 25 so they don't clutter.
$vTrack = new Trackbar(400, 60, 36, 140, $uiDispatcher, ScrollOrientation::Vertical,
    min: 0, max: 100, value: 70, step: 1, pageSize: 10, tickFrequency: 25);
$scroll->addChild($vTrack);

$uiRegistry->addListener(TrackbarChangedEvent::class, static function (TrackbarChangedEvent $e) use ($logger): void {
    $logger->info('Trackbar changed', [
        'orientation' => $e->trackbar->isHorizontal() ? 'H' : 'V',
        'value'       => $e->trackbar->getValue(),
    ]);
});

// ---- Canvas demo tab -------------------------------------------------------
// A framebuffer and the software renderer that fills it. The application
// computes every pixel and PutImage moves them across in one request, which is
// all a mid-90s Windows game did between draining the message queue and
// blitting its DIB section.
//
// Two things this tab is here to show. The cube's colours are the *picture* and
// so are the app's to choose, while the well around it is the theme's — switch
// era from the View menu and only the frame changes. And the animation repaints
// nothing but the canvas: 280x190 costs 3.3 ms of PHP and 207 kB a frame, where
// a full window repaint would be all of that plus every other widget, twenty
// times a second.
// The picture's own colours. Deliberately literals, and deliberately not from
// the palette: `src/Theme` owns how the *window* looks, and a drawing that
// changed colour when the era did would be a bug rather than a feature.
const CUBE_PAPER = [ 16,  16,  32];
const CUBE_INK   = [120, 255, 160];

$canvasTabIndex = count($tabView->getChildren());
$canvasTab      = $tabView->addTab('Canvas');

$canvasTab->addChild(new Label('A software-rendered wireframe, blitted with PutImage', 12, 12));
$canvasTab->addChild(new Label('the well is the theme\'s; the pixels inside it are the app\'s', 12, 32));

$cubeCanvas = new Canvas(12, 56, 284, 194, $uiDispatcher, paper: CUBE_PAPER);
$canvasTab->addChild($cubeCanvas);

$cube = new WireCube();

/** One frame: clear the paper, project the cube, stroke its twelve edges. */
$drawCube = static function () use ($cubeCanvas, $cube): void {
    $cubeCanvas->clear(CUBE_PAPER);

    foreach ($cube->project($cubeCanvas->imageWidth(), $cubeCanvas->imageHeight()) as [$x0, $y0, $x1, $y1]) {
        $cubeCanvas->drawLine($x0, $y0, $x1, $y1, CUBE_INK);
    }
};
$drawCube();

// No paint callback here: this canvas is a display surface, and the animation
// below clears the image every frame, so anything drawn on it would last 50 ms.
// `example/paint.php` is the same widget with a pointer wired to it.

$windowFrame->addChild($tabView);

// Its own root, not a child of the frame: it lives in a separate X11 window, so
// its coordinates start at that window's origin.
$widgetManager->addChild($fileDialog);
$widgetManager->setFileDialog($fileDialog);

$tabView->setActiveIndex($initialTab);
$widgetManager->register($registry);

// --- UI event listeners -----------------------------------------------------

$uiRegistry->addListener(ButtonClickedEvent::class, static function (ButtonClickedEvent $e) use ($logger, $messageBox, $client): void {
    $logger->info('Button clicked', ['label' => $e->button->getLabel()]);

    $messageBox->show(
        'You clicked: ' . $e->button->getLabel(),
        'Message',
        MessageBoxFlags::BUTTONS_OK | MessageBoxFlags::ICON_INFO,
    );

    // Centre the dialog over the main window.
    $x = $client->getWindowX() + intdiv($client->getWindowWidth()  - MessageBox::WIDTH,  2);
    $y = $client->getWindowY() + intdiv($client->getWindowHeight() - MessageBox::HEIGHT, 2);
    $client->showDialogWindow($x, $y);
    $client->redrawDialog();
});

// The frame handler only *asks* to close — the app decides. Here that's simply
// tearing the window down, but this is where a save prompt would live.
$uiRegistry->addListener(WindowCloseRequestedEvent::class, static function (WindowCloseRequestedEvent $e) use ($client, $logger): void {
    $logger->info('Close requested from the caption');
    $client->closeWindow();
});

$uiRegistry->addListener(MessageBoxClosedEvent::class, static function (MessageBoxClosedEvent $e) use ($logger): void {
    $logger->info('MessageBox closed', ['result' => $e->result->name]);
});

// One listener answers all three modes: the event says which one it was and
// carries a null path when the user cancelled, so there's no separate
// "cancelled" case to forget about.
$uiRegistry->addListener(FileDialogClosedEvent::class,
    static function (FileDialogClosedEvent $e) use ($logger, $statusBar, $client): void {
        if ($e->wasCancelled()) {
            $logger->info('File dialog cancelled', ['mode' => $e->mode->value]);
            $statusBar->setPaneText(0, 'Cancelled');
            $client->redraw();
            return;
        }

        $logger->info('File dialog result', ['mode' => $e->mode->value, 'path' => $e->path]);

        $statusBar->setPaneText(0, match ($e->mode) {
            FileDialogMode::OpenFile   => 'Opened '   . $e->path,
            FileDialogMode::SaveFile   => 'Saving to ' . $e->path,
            FileDialogMode::OpenFolder => 'Folder '   . $e->path,
        });
        $client->redraw();
    });

$uiRegistry->addListener(CheckboxToggledEvent::class, static function (CheckboxToggledEvent $e) use ($logger): void {
    $logger->info('Checkbox toggled', [
        'label'   => $e->checkbox->label,
        'checked' => $e->checkbox->isChecked(),
    ]);
});

$uiRegistry->addListener(RadioToggledEvent::class, static function (RadioToggledEvent $e) use ($logger): void {
    $logger->info('Radio toggled', [
        'label'   => $e->radio->label,
        'checked' => $e->radio->isChecked(),
    ]);
});

$uiRegistry->addListener(ScrollChangedEvent::class, static function (ScrollChangedEvent $e) use ($logger): void {
    $logger->info('Scrolled', [
        'axis'  => $e->scrollBar->orientation->name,
        'value' => $e->scrollBar->getValue(),
    ]);
});

$uiRegistry->addListener(ListSelectionChangedEvent::class, static function (ListSelectionChangedEvent $e) use ($logger): void {
    $logger->info('List selection', [
        'index' => $e->list->getSelectedIndex(),
        'item'  => $e->list->getSelectedItem(),
    ]);
});

$uiRegistry->addListener(DropDownChangedEvent::class, static function (DropDownChangedEvent $e) use ($logger): void {
    $logger->info('DropDown selection', [
        'index' => $e->dropDown->getSelectedIndex(),
        'item'  => $e->dropDown->getSelectedItem(),
    ]);
});

$uiRegistry->addListener(ComboBoxChangedEvent::class, static function (ComboBoxChangedEvent $e) use ($logger): void {
    $logger->info('ComboBox changed', [
        'source' => $e->fromList ? 'list' : 'typed',
        'text'   => $e->comboBox->getText(),
        'index'  => $e->comboBox->getSelectedIndex(),
    ]);
});

$uiRegistry->addListener(TreeNodeSelectedEvent::class, static function (TreeNodeSelectedEvent $e) use ($logger): void {
    $logger->info('Tree node selected', ['label' => $e->node->label]);
});

// Mirror the focused text widget's caret + selection state into the status
// bar's middle pane. Updates on click, drag, arrow keys, typing, etc.
$uiRegistry->addListener(TextSelectionChangedEvent::class, static function (TextSelectionChangedEvent $e) use ($statusBar): void {
    $w = $e->widget;
    if ($w->hasSelection()) {
        $n = $w->getSelectionEnd() - $w->getSelectionStart();
        $statusBar->setPaneText(1, $n === 1 ? '1 char selected' : "{$n} chars selected");
    } else {
        $col = $w->getCursor() + 1;
        $statusBar->setPaneText(1, "Ln 1, Col {$col}");
    }
});

// --- X11 event handlers -----------------------------------------------------

$focusHandler = new FocusHandler($client, $logger);

$registry->addListener(X11ErrorEvent::class,        new ErrorHandler($logger));
$registry->addListener(X11ExposeEvent::class,        new ExposeHandler($client, $logger));
$registry->addListener(X11MotionEvent::class,        new MotionHandler($client));
$registry->addListener(X11ButtonPressEvent::class,   new ButtonPressHandler($logger));
$registry->addListener(X11ButtonReleaseEvent::class, static fn() => resolve(null));
$registry->addListener(X11KeyPressEvent::class,      new KeyPressHandler($client, $logger));
$registry->addListener(X11ConfigureEvent::class,     new ConfigureHandler($client, $logger));

// Stretch the menu bar full-width, anchor the status bar to the bottom edge, and
// resize the tab panel + Explorer-style panes to fill the space in between.
//
// A theme switch reruns this too: a theme is free to change the status bar's
// height, so the app-level layout has to be re-derived rather than baked in.
$applyLayout = static function (int $winW, int $winH) use (
    $themes, $client, $renderer, $windowFrame, $tabView, $menuBar, $statusBar, $rebar,
    $treeView, $listView
): void {
    $margin = 10;

    // The frame owns the outer size; everything else lays out inside its
    // content area, which is the whole window when the WM is decorating.
    $windowFrame->setSize($winW, $winH);
    [$width, $height] = $windowFrame->contentSize();

    $m       = $themes->metrics();
    $statusH = $m->statusBarHeight;

    $menuBar->setSize($width);
    // Child coordinates are relative to the frame's content area, so set relY
    // and let the frame resolve absolutes at the end.
    $statusBar->relY = $height - $statusH;
    $statusBar->setSize($width);

    // The tab panel fills everything between the overlay readout and the status
    // bar. tabTop clears the menu bar plus the four debug lines the client draws.
    $tabTop  = $m->menuBarHeight + 84;
    $tabView->relX = $margin;
    $tabView->relY = $tabTop;
    $tabView->setSize(
        max(120, $width  - 2 * $margin),
        max(120, $height - $tabTop - $statusH - $margin),
    );

    // ---- Tree tab: rebar across the top, tree + list filling the rest ----
    // Everything is derived from the page's usable box, because both the tab
    // chrome and the rebar's own height move with the theme.
    $pageW = $tabView->width  - 2 * $tabView->contentOffsetX();
    $pageH = $tabView->height - $tabView->contentOffsetY() - $m->tabBorder;

    $rebar->relX = 8;
    $rebar->relY = 6;
    $rebar->setSize(max(80, $pageW - 16));

    $paneTop = $rebar->relY + $rebar->height + 6;
    $paneH   = max(60, $pageH - $paneTop - 8);
    $treeW   = max(120, intdiv($pageW - 24, 3));

    $treeView->relX = 8;
    $treeView->relY = $paneTop;
    $treeView->setSize($treeW, $paneH);

    $listView->relX = 8 + $treeW + 8;
    $listView->relY = $paneTop;
    $listView->setSize(max(120, $pageW - $listView->relX - 8), $paneH);

    // Name takes whatever Size and Type leave, so the grid fits its pane. A
    // ListView whose columns are wider than its viewport grows a horizontal
    // scrollbar — which is right when the data is genuinely wider, and just
    // noise when it's a few pixels of layout slack.
    $lvColumns = $listView->getColumns();
    $lvColumns[0]->width = max(
        80,
        $listView->viewportWidth() - $lvColumns[1]->width - $lvColumns[2]->width,
    );
    $listView->relayout();

    // Re-resolve every child's absolute position against the (possibly moved)
    // content origin, then keep the debug overlay clear of the caption.
    $windowFrame->relayout();

    // A tab-style caption (BeOS) doesn't fill the window's top edge, so cut the
    // leftover strip out of the window entirely — the desktop shows through it
    // and clicks pass to whatever is behind. Themes with a full-width caption
    // report nothing to cut and the window stays a plain rectangle.
    $client->setWindowShape($windowFrame->shapeRects($renderer));

    $content = $windowFrame->contentRect();
    $client->setOverlayOrigin($content->x + $margin, $content->y + $m->menuBarHeight + 18);
};

$pickerShown = false;
$registry->addListener(X11ConfigureEvent::class, static function (X11ConfigureEvent $e) use (
    $client, $applyLayout, $initialPicker, $openPicker, &$pickerShown
): \React\Promise\PromiseInterface {
    if ($e->windowId !== $client->getWindowId()) {
        return resolve(null);
    }

    $applyLayout($e->width, $e->height);
    $client->redraw();

    // --file-dialog=: open once, after the first configure has given the frame
    // its real size (and so the dialog a box to centre in).
    if ($initialPicker !== null && !$pickerShown) {
        $pickerShown = true;
        $openPicker($initialPicker);
    }
    return resolve(null);
});

// --- Theme switching --------------------------------------------------------
// Everything a switch has to touch, in one place: tick the menu, re-push the
// metrics through the widget tree (so hit-testing and painting agree again),
// redo the app-level layout, then let the client update the pieces the server
// holds — window background and font — and repaint.
$themes->onChange(static function (Theme $theme, string $variant, bool $themeChanged) use (
    $themeItems, $variantItems, $themes, $widgetTree, $client, $applyLayout, $logger, $fileDialog, $fileDialogHandler
): void {
    foreach ($themeItems as $id => $item) {
        $item->checked = $id === $theme->id();
    }
    foreach ($variantItems as $key => $item) {
        $item->checked = $key === $theme->id() . ':' . $variant;
    }

    // A variant is colours and nothing else, so it needs the tree re-pushed and
    // a repaint — but not a relayout, because no size moved. Doing one anyway
    // would reflow every widget in the window each time someone toggled dark
    // mode, which is visible work for no change.
    $widgetTree->refreshTheme();

    if ($themeChanged) {
        $applyLayout($client->getWindowWidth(), $client->getWindowHeight());

        // The file dialog is its own window, so a theme that wants a different
        // dialog size needs the server told, not just the widget.
        $fileDialogHandler->refreshLayout($fileDialog);
    }

    $client->applyTheme();

    $logger->info('Theme switched', [
        'theme'   => $theme->name(),
        'id'      => $theme->id(),
        'variant' => $themes->currentVariantName(),
        'sizes'   => $themeChanged ? 'relaid out' : 'unchanged',
    ]);
});

// Live mouse-coords readout in the status bar's 3rd pane. The existing
// MotionHandler already triggers a redraw — we just mutate the text and
// the next paint picks it up.
$registry->addListener(X11MotionEvent::class, static function (X11MotionEvent $e) use ($statusBar, $client): \React\Promise\PromiseInterface {
    if ($e->windowId === $client->getWindowId()) {
        $statusBar->setPaneText(2, "X={$e->x} Y={$e->y}");
    }
    return resolve(null);
});
$registry->addListener(X11MapEvent::class,           new MapHandler($logger));
$registry->addListener(X11UnmapEvent::class,         new UnmapHandler($logger));
$registry->addListener(X11FocusInEvent::class,       $focusHandler);
$registry->addListener(X11FocusOutEvent::class,      $focusHandler);

// A toolkit-drawn caption has to dim itself when the window loses focus, the
// way the window manager's would have.
$registry->addListener(X11FocusInEvent::class, static function () use ($windowFrame, $client): \React\Promise\PromiseInterface {
    if ($windowFrame->setActive(true)) $client->redraw();
    return resolve(null);
});
$registry->addListener(X11FocusOutEvent::class, static function () use ($windowFrame, $client): \React\Promise\PromiseInterface {
    if ($windowFrame->setActive(false)) $client->redraw();
    return resolve(null);
});

// Clipboard: the X11Client owns the selection state machine. Route the
// three selection events at it directly.
$registry->addListener(X11SelectionNotifyEvent::class,  $client->onSelectionNotify(...));
$registry->addListener(X11SelectionRequestEvent::class, $client->onSelectionRequest(...));
$registry->addListener(X11SelectionClearEvent::class,   $client->onSelectionClear(...));

// --- The Canvas tab's animation ---------------------------------------------
// Twenty frames a second, and only while that tab is on top. The guard is not an
// optimisation: redrawRegion() with a subtree paints that subtree whether or not
// the paint walk would have reached it, so animating a hidden page would draw
// the cube straight over whichever tab is actually showing.
$loop->addPeriodicTimer(0.05, static function () use (
    $tabView, $canvasTabIndex, $cube, $cubeCanvas, $drawCube, $client
): void {
    if ($tabView->getActiveIndex() !== $canvasTabIndex) return;

    $cube->advance();
    $drawCube();

    $damage = $cubeCanvas->takeDamage();
    if (!$damage->isEmpty()) {
        $client->redrawRegion($cubeCanvas->toWindow($damage), $cubeCanvas);
    }
});

// --- Connect and run --------------------------------------------------------

$client->connect()->then(
    static fn() => null,
    static fn(\Throwable $e) => $logger->critical('Connection failed', ['error' => $e->getMessage()]),
);

$loop->run();
