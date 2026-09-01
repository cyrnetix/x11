<?php
declare(strict_types=1);

/**
 * A form in its own window.
 *
 * Shows the general child-window facility: the application asks the client for
 * a window, hands it a widget subtree, and shows it. Everything inside is an
 * ordinary widget handled by the ordinary handlers — the only differences from
 * the main window are that the subtree's coordinates start at (0, 0) and that
 * showing it grabs the pointer, so it's modal.
 *
 *     php example/child-window.php [--theme=platinum]
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Handler\ConfigureHandler;
use Cyrnetix\X11\Handler\ExposeHandler;
use Cyrnetix\X11\Theme\BeOs\BeOsTheme;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\Handler as H;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter as P;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\FormWindow;
use Cyrnetix\X11\UI\Widget\Label;
use Cyrnetix\X11\UI\Widget\TextBox;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\WidgetTree;
use function React\Promise\resolve;

$theme    = 'win9x';
$openAtStart = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--theme=')) $theme = substr($arg, 8);
    // Open the form on startup, for eyeballing it across themes without clicking.
    if ($arg === '--open-form') $openAtStart = true;
}

$logger = new Logger('form');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

$themes = new ThemeManager(new Win9xTheme(), new PlatinumTheme(), new CdeTheme(), new BeOsTheme());
$themes->select($theme);

$registry = new ListenerRegistry();
$renderer = new Renderer();
$client   = new X11Client(Loop::get(), new AsyncEventDispatcher($registry, $logger), $logger, $renderer, $themes);
$ui       = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());

$tree  = new WidgetTree($themes);
$frame = new WindowFrame(420, 240, 'Child Window Demo', drawsChrome: true);
$open  = new Button('Open form…', 20, 20, 110, $themes->metrics()->dialogButtonHeight, $ui);
$shown = new Label('Nothing entered yet', 20, 60);
$frame->addChild($open);
$frame->addChild($shown);

// ---- The form ------------------------------------------------------------
// FormWindow is the toolkit's ready-made root for a child window: it draws the
// themed caption and border and gives its children a content box to sit in.
$form      = new FormWindow(320, 170, 'Your name');
$nameLabel = new Label('Name:', 0, 6, lineHeight: $themes->metrics()->fieldHeight);
$nameBox   = new TextBox(56, 6, 220, $themes->metrics()->fieldHeight, dispatcher: $ui);
$ok        = new Button('OK', 116, 100, 80, $themes->metrics()->dialogButtonHeight, $ui);
$cancel    = new Button('Cancel', 202, 100, 80, $themes->metrics()->dialogButtonHeight, $ui);
$form->addChild($nameLabel);
$form->addChild($nameBox);
$form->addChild($ok);
$form->addChild($cancel);

// One call: a real top-level window with this subtree painted into it.
$formWindow = $client->createChildWindow(320, 170, modal: true, root: $form);

$doubleClick = new DoubleClickDetector();
$handlers = [
    new H\WindowFrameHandler($tree, $client, $ui, new DoubleClickDetector(), $renderer, new P\WindowFramePainter($themes)),
    new H\FormWindowHandler($tree, $client, $renderer, new P\FormWindowPainter($themes), $doubleClick),
    new H\EditableTextHandler($tree, $client, $renderer),
    new H\ButtonHandler($tree, $client, $logger, new P\ButtonPainter($themes)),
    new H\TextBoxHandler(new P\TextBoxPainter($themes)),
    new H\LabelHandler(new P\LabelPainter($themes)),
];

$manager = new WidgetManager(
    $client, $renderer, $tree, new P\MessageBoxPainter($themes), new KeyTranslator(), $logger,
    $handlers,
    new H\TreeViewHandler($tree, $client, $doubleClick, new P\TreeViewPainter($themes)),
);
$client->setWidgetManager($manager);
$manager->addChild($frame);
// The form's subtree joins the same tree, so every handler finds it as usual.
$manager->addChild($form);

$openForm = static function () use ($client, $form, $formWindow, $tree, $nameBox): void {
    $nameBox->setText('');

    // Centre over the application, then take input until it closes.
    $x = $client->getWindowX() + intdiv($client->getWindowWidth()  - $formWindow->width(),  2);
    $y = $client->getWindowY() + intdiv($client->getWindowHeight() - $formWindow->height(), 2);

    $form->setVisible(true);
    $tree->setModal($form);
    $formWindow->show(max(0, $x), max(0, $y));
    $formWindow->redraw();
};
$open->setOnClick($openForm);

$close = static function (bool $accepted) use ($client, $form, $formWindow, $tree, $nameBox, $shown, $logger): void {
    $name = trim($nameBox->getText());

    $form->setVisible(false);
    $tree->setModal(null);
    $tree->setFocused(null);
    $formWindow->hide();

    $logger->info($accepted ? 'Form accepted' : 'Form cancelled', ['name' => $accepted ? $name : null]);
    $shown->setText($accepted && $name !== '' ? "Hello, {$name}" : 'Nothing entered yet');
    $client->redraw();
};
$ok->setOnClick(static fn() => $close(true));
$cancel->setOnClick(static fn() => $close(false));

$registry->addListener(X11ExposeEvent::class,    new ExposeHandler($client, $logger));
$registry->addListener(X11ConfigureEvent::class, new ConfigureHandler($client, $logger));
$formOpened = false;
$registry->addListener(X11ConfigureEvent::class, static function (X11ConfigureEvent $e) use (
    $client, $frame, $renderer, $openAtStart, $openForm, &$formOpened
): \React\Promise\PromiseInterface {
    if ($e->windowId === $client->getWindowId()) {
        $frame->setSize($e->width, $e->height);
        $frame->relayout();
        $client->setWindowShape($frame->shapeRects($renderer));
        $client->redraw();

        if ($openAtStart && !$formOpened) {
            $formOpened = true;
            $openForm();
        }
    }
    return resolve(null);
});
$manager->register($registry);

// Closing is the application's decision, not the toolkit's: the caption's close
// button only *asks*, by dispatching WindowCloseRequestedEvent, so that an app can
// prompt about unsaved work first. Answer it or the button does nothing.
$uiRegistry->addListener(
    WindowCloseRequestedEvent::class,
    static function (WindowCloseRequestedEvent $e) use ($client): void {
        $client->closeWindow();
    },
);

$client->setWindowTitle('Child Window Demo');
$client->setWindowSize(420, 240);
$client->setDecorated(false);
$client->connect();

Loop::run();
