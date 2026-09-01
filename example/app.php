<?php
declare(strict_types=1);

/**
 * The smallest complete app built on this toolkit: a themed window with a
 * label, a button and a message box.
 *
 * Read this before widget-gallery.php — that's the same wiring for thirty
 * widgets. From a
 * consuming project the only difference is the autoloader path:
 *
 *     require __DIR__ . '/vendor/autoload.php';
 *
 * Run it from the repository with:  php example/app.php
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Loop;
use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\IconName;
use Cyrnetix\X11\Drawing\IconRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ConfigureEvent;
use Cyrnetix\X11\Event\X11ExposeEvent;
use Cyrnetix\X11\Event\X11SetupCompleteEvent;
use Cyrnetix\X11\Handler\ConfigureHandler;
use Cyrnetix\X11\Handler\ExposeHandler;
use Cyrnetix\X11\Theme\Platinum\PlatinumTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Event\WindowCloseRequestedEvent;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\ButtonClickedEvent;
use Cyrnetix\X11\UI\Handler as H;
use Cyrnetix\X11\UI\KeyTranslator;
use Cyrnetix\X11\UI\Painter as P;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Button;
use Cyrnetix\X11\UI\Widget\Label;
use Cyrnetix\X11\UI\Widget\MessageBox;
use Cyrnetix\X11\UI\Widget\WindowFrame;
use Cyrnetix\X11\UI\WidgetManager;
use Cyrnetix\X11\UI\WidgetTree;
use function React\Promise\resolve;

$logger = new Logger('app');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Info));

$themes   = new ThemeManager(new Win9xTheme(), new PlatinumTheme());
$registry = new ListenerRegistry();
$renderer = new Renderer();
$client   = new X11Client(Loop::get(), new AsyncEventDispatcher($registry, $logger), $logger, $renderer, $themes);
$ui       = new SyncEventDispatcher($uiRegistry = new ListenerRegistry());

// Icons: themesRoot and loaders default to what the package ships.
$icons = new IconRegistry(themes: $themes, logger: $logger);
$logger->info('shipped icon set resolves', [
    'root'   => IconRegistry::shippedThemesRoot(),
    'folder' => $icons->get(IconName::Folder) !== null ? 'loaded' : 'missing',
]);

$tree  = new WidgetTree($themes);
$frame = new WindowFrame(360, 200, 'Vendored Toolkit', drawsChrome: true);
$box   = new MessageBox($ui, $themes);

$label  = new Label('Built on rennik/x11', 16, 16);
$button = new Button('Say hello', 16, 48, 100, $themes->metrics()->dialogButtonHeight, $ui);
$frame->addChild($label);
$frame->addChild($button);

$handlers = [
    new H\WindowFrameHandler($tree, $client, $ui, new DoubleClickDetector(), $renderer, new P\WindowFramePainter($themes)),
    new H\ButtonHandler($tree, $client, $logger, new P\ButtonPainter($themes)),
    new H\LabelHandler(new P\LabelPainter($themes)),
];

$manager = new WidgetManager(
    $client, $renderer, $tree, new P\MessageBoxPainter($themes), new KeyTranslator(), $logger,
    $handlers,
    new H\TreeViewHandler($tree, $client, new DoubleClickDetector(), new P\TreeViewPainter($themes)),
);
$client->setWidgetManager($manager);
$manager->addChild($frame);
$manager->setDialog($box);
$manager->register($registry);

$uiRegistry->addListener(ButtonClickedEvent::class, static function (ButtonClickedEvent $e) use ($box, $client): void {
    $box->show('Hello from a vendored toolkit.', 'Message', 0);
    $x = $client->getWindowX() + intdiv($client->getWindowWidth()  - MessageBox::WIDTH,  2);
    $y = $client->getWindowY() + intdiv($client->getWindowHeight() - MessageBox::HEIGHT, 2);
    $client->showDialogWindow($x, $y);
    $client->redrawDialog();
});

$registry->addListener(X11SetupCompleteEvent::class, static function () use ($logger): \React\Promise\PromiseInterface {
    $logger->info('connected to the X server');
    return resolve(null);
});
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
$registry->addListener(X11ConfigureEvent::class, static function (X11ConfigureEvent $e) use ($client, $frame): \React\Promise\PromiseInterface {
    if ($e->windowId === $client->getWindowId()) {
        $frame->setSize($e->width, $e->height);
        $frame->relayout();
        $client->setWindowShape($frame->shapeRects(new Renderer()));
        $client->redraw();
    }
    return resolve(null);
});

$client->setWindowTitle('Vendored Toolkit');
$client->setWindowSize(360, 200);
$client->setDecorated(false);
$client->connect();

// No timer: the close button works, because the listener above answers it. This
// example used to stop itself after four seconds, which hid the fact that it
// could not be closed.
Loop::run();
$logger->info('clean exit');
