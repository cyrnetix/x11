<?php
declare(strict_types=1);

/**
 * A tree that opts in to multi-select picks rows the way Explorer does.
 *
 * Driven through TreeViewHandler with real button events, because the whole
 * feature is which modifier bit in `state` means what — a test that called
 * toggleSelected() directly would pass with the masks swapped. A tree that
 * does *not* opt in must ignore the modifiers, or every existing tree changes
 * behaviour under a held Ctrl.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\DoubleClickDetector;
use Cyrnetix\X11\UI\Event\TreeNodeSelectedEvent;
use Cyrnetix\X11\UI\Event\TreeSelectionChangedEvent;
use Cyrnetix\X11\UI\Handler\TreeViewHandler;
use Cyrnetix\X11\UI\Painter\TreeViewPainter;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\TreeNode;
use Cyrnetix\X11\UI\Widget\TreeView;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;

$fail = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-52s %-28s %s\n", $what, is_array($got) ? json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . var_export($want, true) . ')');
};

$logger = new Logger('tree-multiselect');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Error));
$themes   = new ThemeManager(new Win9xTheme());
$renderer = new Renderer();
// Not connected: redraw() has nothing to write to, which is all this needs.
$client   = new X11Client(Factory::create(), new AsyncEventDispatcher(new ListenerRegistry(), $logger), $logger, $renderer, $themes);
$tree     = new WidgetTree($themes);
$handler  = new TreeViewHandler($tree, $client, new DoubleClickDetector(), new TreeViewPainter($themes));

$registry = new ListenerRegistry();
$ui       = new SyncEventDispatcher($registry);
$changes  = [];
$picked   = [];
$registry->addListener(TreeSelectionChangedEvent::class, static function (TreeSelectionChangedEvent $e) use (&$changes): void {
    $changes[] = array_map(static fn(TreeNode $n): string => $n->label, $e->nodes);
});
$registry->addListener(TreeNodeSelectedEvent::class, static function (TreeNodeSelectedEvent $e) use (&$picked): void {
    $picked[] = $e->node->label;
});

$view = new TreeView(0, 0, 200, 200, $ui);
foreach (['clicks', 'cpc', 'views', 'transactions', 'offers'] as $name) {
    $view->addRoot(new TreeNode($name));
}
$tree->addRoot($view);

$m    = $view->metrics();
$time = 0;
$click = static function (string $label, int $state = 0) use ($handler, $view, $m, &$time): void {
    foreach ($view->flattenVisibleRows() as $i => [$node]) {
        if ($node->label !== $label) continue;
        $y = $m->treeBorder + $i * $m->treeRowHeight + 2;
        // A second apart, so no two clicks read as a double-click.
        $time += 1_000;
        $handler->tryPress(new X11ButtonPressEvent(0, 1, 120, $y, 120, $y, $time, $state));
        return;
    }
    throw new LogicException("no row $label");
};
$labels = static fn(): array => array_map(static fn(TreeNode $n): string => $n->label, $view->getSelection());

const SHIFT = 0x0001;
const CTRL  = 0x0004;

echo "without opting in\n";
$click('clicks');
$click('views', CTRL);
$check('Ctrl+click still selects one row',           $labels(), ['views']);
$click('offers', SHIFT);
$check('and so does Shift+click',                    $labels(), ['offers']);

echo "with multi-select\n";
$view->setMultiSelect(true);
$changes = [];
$picked  = [];

$click('clicks');
$click('views', CTRL);
$click('cpc', CTRL);
$check('Ctrl+click adds, listed in row order',       $labels(), ['clicks', 'cpc', 'views']);
$check('the current row is the last one picked',     $view->getSelected()?->label, 'cpc');
$check('each pick said so',                          $picked, ['clicks', 'views', 'cpc']);
$check('and the whole set was announced each time',  $changes[count($changes) - 1], ['clicks', 'cpc', 'views']);

$click('cpc', CTRL);
$check('Ctrl+click on a selected row takes it out',  $labels(), ['clicks', 'views']);
$check('handing "current" to the newest of the rest', $view->getSelected()?->label, 'views');
$check('which is announced as a change',             $changes[count($changes) - 1], ['clicks', 'views']);

$click('cpc');
$click('offers', SHIFT);
$check('Shift+click selects the run from the anchor', $labels(), ['cpc', 'views', 'transactions', 'offers']);
$click('clicks', SHIFT);
$check('and a second one re-draws it from there',    $labels(), ['clicks', 'cpc']);

$click('transactions', CTRL);
$check('Ctrl+click adds to a Shift range',           $labels(), ['clicks', 'cpc', 'transactions']);
$check('the painter asks the same question',
    [$view->isSelected($view->getRoots()[3]), $view->isSelected($view->getRoots()[2])], [true, false]);
$check('copy gives every selected label',            $view->copy(), "clicks\ncpc\ntransactions");

$click('views');
$check('a plain click starts again from one row',    $labels(), ['views']);
$click('views', CTRL);
$check('and Ctrl can empty the selection',           [$labels(), $view->getSelected()], [[], null]);

$click('clicks');
$click('cpc', CTRL);
$view->setMultiSelect(false);
$check('opting out keeps just the current row',      $labels(), ['cpc']);

$view->clearRoots();
$check('clearing the tree clears the selection',     $view->getSelection(), []);

exit($fail === 0 ? 0 : 1);
