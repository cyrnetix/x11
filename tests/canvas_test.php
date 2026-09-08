<?php
declare(strict_types=1);

/**
 * The canvas: a framebuffer, the PutImage that gets it on screen, and the damage
 * bookkeeping that keeps a stroke cheap.
 *
 * Four claims are worth pinning, because each of them fails quietly:
 *
 *  - **The wire format.** A PutImage carries raw memory, not protocol fields, so
 *    nothing byte-swaps it and nothing checks it. A pixel packed the wrong way
 *    round draws in the wrong colour; a row of the wrong length draws a diagonal
 *    smear. Both look like a broken renderer rather than a broken byte order.
 *  - **A renderer that was never told the image format sends nothing.** That is
 *    the deliberate behaviour — a malformed PutImage is worse — so it needs a
 *    test to say the guard is the guard and not an accident.
 *  - **The painter blits the clip, not the image.** This is the whole reason a
 *    pencil stroke is affordable: get it wrong and everything still *looks*
 *    right, while every motion event pushes the entire framebuffer over the
 *    socket.
 *  - **Paper follows the theme until something is drawn on it.** An undrawn
 *    canvas is a display surface and should change era with the window; a
 *    drawing must not be wiped by a theme switch.
 *
 * No X server: the renderer writes into a ThroughStream and the bytes are read
 * back, and the handler is driven with synthetic events.
 */
require dirname(__DIR__) . '/vendor/autoload.php';

use Cyrnetix\X11\Client\X11Client;
use Cyrnetix\X11\Dispatcher\AsyncEventDispatcher;
use Cyrnetix\X11\Dispatcher\ListenerRegistry;
use Cyrnetix\X11\Drawing\Rect;
use Cyrnetix\X11\Drawing\Renderer;
use Cyrnetix\X11\Event\X11ButtonPressEvent;
use Cyrnetix\X11\Event\X11ButtonReleaseEvent;
use Cyrnetix\X11\Event\X11MotionEvent;
use Cyrnetix\X11\Protocol\SetupParser;
use Cyrnetix\X11\Theme\Cde\CdeTheme;
use Cyrnetix\X11\Theme\ThemeManager;
use Cyrnetix\X11\Theme\Win9x\Win9xTheme;
use Cyrnetix\X11\UI\Event\CanvasPaintEvent;
use Cyrnetix\X11\UI\Event\CanvasPhase;
use Cyrnetix\X11\UI\Handler\CanvasHandler;
use Cyrnetix\X11\UI\Painter\CanvasPainter;
use Cyrnetix\X11\UI\SyncEventDispatcher;
use Cyrnetix\X11\UI\Widget\Canvas;
use Cyrnetix\X11\UI\WidgetTree;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use React\EventLoop\Factory;
use React\Stream\ThroughStream;

$fail = 0;

/** Readable for the values these tests compare: triples, rects, and enum cases. */
$show = static function ($value) use (&$show): string {
    if ($value instanceof \UnitEnum)  return $value->name;
    if (is_array($value)) {
        return '[' . implode(', ', array_map($show, $value)) . ']';
    }

    return var_export($value, true);
};

$check = function (string $what, $got, $want) use (&$fail, $show): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-56s %-24s %s\n", $what, $show($got), $ok ? 'ok' : 'FAIL (want ' . $show($want) . ')');
};

/** X11 requests, split on the length field every one of them carries. */
$requests = static function (string $bytes): array {
    $out    = [];
    $offset = 0;

    while ($offset + 4 <= strlen($bytes)) {
        $length = unpack('v', substr($bytes, $offset + 2, 2))[1];
        if ($length === 0) break;

        $out[]   = substr($bytes, $offset, $length * 4);
        $offset += $length * 4;
    }

    return $out;
};

/** Every PutImage in a byte stream, unpacked into readable fields. */
$putImages = static function (string $bytes) use ($requests): array {
    $out = [];

    foreach ($requests($bytes) as $request) {
        if (ord($request[0]) !== 72) continue;

        $head  = unpack('Copcode/Cformat/vlength/Vdrawable/Vgc/vwidth/vheight/sx/sy/Cpad/Cdepth', $request);
        $out[] = [
            'format' => $head['format'],
            'width'  => $head['width'],
            'height' => $head['height'],
            'x'      => $head['x'],
            'y'      => $head['y'],
            'pad'    => $head['pad'],
            'depth'  => $head['depth'],
            'data'   => substr($request, 24, $head['width'] * $head['height'] * 4),
        ];
    }

    return $out;
};

/** A renderer writing into a buffer the caller can read. @return array{Renderer, callable} */
$wiredRenderer = static function (int $depth = 24, bool $lsbFirst = true, int $maxWords = 65535): array {
    $captured = '';
    $stream   = new ThroughStream();
    $stream->on('data', function (string $chunk) use (&$captured): void { $captured .= $chunk; });

    $renderer = new Renderer();
    $renderer->init($stream, 0x600001, 0x600003);
    $renderer->setImageFormat($depth, $lsbFirst, $maxWords);

    return [$renderer, static function () use ($renderer, &$captured): string {
        // Nothing is on the wire until the buffer is sent: a test that forgets
        // this sees an empty stream and concludes the drawing never happened.
        $renderer->flush();
        $taken   = $captured;
        $captured = '';

        return $taken;
    }];
};

$ui     = new SyncEventDispatcher(new ListenerRegistry());
$themes = new ThemeManager(new Win9xTheme(), new CdeTheme());
$border = $themes->metrics()->canvasBorder;

// -----------------------------------------------------------------------------
echo "framebuffer\n";
// -----------------------------------------------------------------------------

$tree   = new WidgetTree($themes);
$canvas = new Canvas(0, 0, 40 + 2 * $border, 30 + 2 * $border, $ui);
$tree->addRoot($canvas);

$check('image tracks the widget minus its frame', [$canvas->imageWidth(), $canvas->imageHeight()], [40, 30]);
$check('paper is the theme content colour',       $canvas->pixelAt(0, 0), $themes->palette()->content);
$check('nothing is damaged before anything is drawn', $canvas->damage()->isEmpty(), true);

$canvas->setPixel(3, 4, [10, 20, 30]);
$check('setPixel round-trips through pixelAt', $canvas->pixelAt(3, 4), [10, 20, 30]);
$check('damage is the pixel',                  $canvas->damage()->toArray(), [3, 4, 1, 1]);

$canvas->setPixel(9, 7, [1, 2, 3]);
$check('damage unions to cover both', $canvas->damage()->toArray(), [3, 4, 7, 4]);
$check('takeDamage hands it over',    $canvas->takeDamage()->toArray(), [3, 4, 7, 4]);
$check('and resets',                  $canvas->damage()->isEmpty(), true);

$canvas->setPixel(-1, 0, [0, 0, 0]);
$canvas->setPixel(40, 0, [0, 0, 0]);
$check('a pixel outside the image is dropped, not damaged', $canvas->damage()->isEmpty(), true);
$check('and reads back as nothing at all',                  $canvas->pixelAt(40, 0), null);

// A fill that hangs off the edge is clipped rather than corrupting the rows: the
// image must still be exactly as many bytes as it was.
$canvas->fillRect(35, 28, 20, 20, [200, 0, 0]);
$check('a fill over the edge is clipped',       $canvas->damage()->toArray(), [35, 28, 5, 2]);
$check('and the image is still its own size',   strlen($canvas->region(Rect::of(0, 0, 40, 30))), 40 * 30 * 4);
$check('the clipped fill landed',               $canvas->pixelAt(39, 29), [200, 0, 0]);

// The dotted-line bug: motion arrives in jumps, so a stroke has to interpolate.
$canvas->clear([255, 255, 255]);
$canvas->drawLine(0, 0, 9, 3, [0, 0, 0]);
$gaps = 0;
for ($x = 0; $x <= 9; $x++) {
    $column = 0;
    for ($y = 0; $y <= 3; $y++) {
        if ($canvas->pixelAt($x, $y) === [0, 0, 0]) $column++;
    }
    if ($column === 0) $gaps++;
}
$check('a line leaves no column unpainted', $gaps, 0);

$canvas->clear([255, 255, 255]);
$canvas->drawLine(10, 10, 10, 10, [0, 0, 0], size: 3);
$check('a thick stroke is centred on its path, not hung off it',
    [$canvas->pixelAt(9, 9), $canvas->pixelAt(11, 11)], [[0, 0, 0], [0, 0, 0]]);

// The wall renderer's primitive: one vertical run per screen column.
$canvas->clear([255, 255, 255]);
$canvas->takeDamage();          // the clear's own damage, or the union below covers everything
$canvas->fillSpan(6, 4, 10, [7, 8, 9]);
$check('a span fills its column',        [$canvas->pixelAt(6, 4), $canvas->pixelAt(6, 13)], [[7, 8, 9], [7, 8, 9]]);
$check('and stops there',                $canvas->pixelAt(6, 14), [255, 255, 255]);
$check('leaving the neighbours alone',   $canvas->pixelAt(7, 8), [255, 255, 255]);
$check('with one damage rect, not ten',  $canvas->takeDamage()->toArray(), [6, 4, 1, 10]);

// Once the damage covers everything the widget stops unioning — a renderer
// writing thousands of spans over a cleared image was allocating a rectangle
// each time to widen one that already covered it. What must not change is the
// answer: a full-image damage stays full, and taking it starts narrow again.
$canvas->clear([255, 255, 255]);
$canvas->fillSpan(3, 5, 4, [1, 2, 3]);
$check('a span after a clear leaves the damage full', $canvas->takeDamage()->toArray(), [0, 0, 40, 30]);
$canvas->fillSpan(3, 5, 4, [1, 2, 3]);
$check('and the next one is tracked narrowly again',  $canvas->takeDamage()->toArray(), [3, 5, 1, 4]);

// A wall taller than the screen is the normal case, not an error: the span is
// clipped at both ends rather than refused.
$canvas->fillSpan(9, -20, 200, [1, 1, 1]);
$check('a span overrunning the image is clipped', $canvas->takeDamage()->toArray(), [9, 0, 1, 30]);
$check('and painted to the very edges',  [$canvas->pixelAt(9, 0), $canvas->pixelAt(9, 29)], [[1, 1, 1], [1, 1, 1]]);

$canvas->fillSpan(40, 0, 10, [2, 2, 2]);
$canvas->fillSpan(5, 0, 0, [2, 2, 2]);
$check('a column outside the image draws nothing', $canvas->damage()->isEmpty(), true);

$canvas->clear([255, 255, 255]);
$canvas->strokeRect(4, 4, 10, 8, [0, 0, 0]);
$check('a stroked rect draws its corner',   $canvas->pixelAt(13, 11), [0, 0, 0]);
$check('and leaves its middle alone',       $canvas->pixelAt(9, 8), [255, 255, 255]);

$canvas->clear([255, 255, 255]);
$canvas->strokeEllipse(4, 4, 11, 9, [0, 0, 0]);
$check('an ellipse touches the top of its box', $canvas->pixelAt(9, 4), [0, 0, 0]);
$check('and misses the corner',                 $canvas->pixelAt(4, 4), [255, 255, 255]);

// -----------------------------------------------------------------------------
echo "snapshots\n";
// -----------------------------------------------------------------------------

$canvas->clear([255, 255, 255]);
$before = $canvas->snapshot();
$canvas->fillRect(0, 0, 40, 30, [9, 9, 9]);
$canvas->takeDamage();

$check('restore puts the old image back', $canvas->restore($before) && $canvas->pixelAt(20, 20) === [255, 255, 255], true);
$check('and damages all of it',           $canvas->takeDamage()->toArray(), [0, 0, 40, 30]);
$check('a snapshot of the wrong size is refused', $canvas->restore(array_slice($before, 0, 5)), false);

$canvas->fillRect(0, 0, 40, 30, [9, 9, 9]);
$canvas->takeDamage();
$canvas->restoreRegion($before, Rect::of(5, 5, 4, 4));
$check('restoreRegion damages only the region', $canvas->takeDamage()->toArray(), [5, 5, 4, 4]);
$check('it puts that region back',              $canvas->pixelAt(6, 6), [255, 255, 255]);
$check('and leaves the rest as it was',         $canvas->pixelAt(20, 20), [9, 9, 9]);

// -----------------------------------------------------------------------------
echo "the wire format\n";
// -----------------------------------------------------------------------------

$canvas->clear([1, 2, 3]);
$one = $canvas->region(Rect::of(0, 0, 1, 1));
$check('a pixel is little-endian 0xFFRRGGBB', bin2hex($one), '030201ff');
$check('a region is exactly its own pixels',  strlen($canvas->region(Rect::of(2, 3, 6, 5))), 6 * 5 * 4);
$check('a region off the image is empty',     $canvas->region(Rect::of(80, 80, 4, 4)), '');

[$renderer, $take] = $wiredRenderer();
$renderer->putImage($canvas->region(Rect::of(0, 0, 2, 2)), 2, 2, 17, 23);
$blits = $putImages($take());

$check('one PutImage went out',        count($blits), 1);
$check('format is ZPixmap',           $blits[0]['format'], 2);
$check('left-pad is zero, as ZPixmap requires', $blits[0]['pad'], 0);
$check('depth is the window depth',   $blits[0]['depth'], 24);
$check('size is the blit size',       [$blits[0]['width'], $blits[0]['height']], [2, 2]);
$check('destination is where asked',  [$blits[0]['x'], $blits[0]['y']], [17, 23]);
$check('the data is the pixels',      bin2hex($blits[0]['data']), str_repeat('030201ff', 4));

$unformatted = new Renderer();
$stream      = new ThroughStream();
$silent      = '';
$stream->on('data', function (string $chunk) use (&$silent): void { $silent .= $chunk; });
$unformatted->init($stream, 0x600001, 0x600003);
$unformatted->putImage($canvas->region(Rect::of(0, 0, 2, 2)), 2, 2, 0, 0);
$unformatted->flush();
$check('a renderer never told the format declines', $unformatted->canPutImage(), false);
$check('and writes nothing rather than a malformed request', $silent, '');

// A request's length is 16 bits of words, so a big image has to be split. With
// room for four pixels a four-wide image goes one row at a time.
[$small, $takeSmall] = $wiredRenderer(depth: 24, lsbFirst: true, maxWords: 10);
$small->putImage($canvas->region(Rect::of(0, 0, 4, 3)), 4, 3, 5, 6);
$chunks = $putImages($takeSmall());
$check('an oversized blit is split into whole rows', count($chunks), 3);
$check('each chunk is one row',   array_column($chunks, 'height'), [1, 1, 1]);
$check('and lands on its own row', array_column($chunks, 'y'), [6, 7, 8]);

// A big-endian server reads each pixel the other way round.
[$msb, $takeMsb] = $wiredRenderer(depth: 24, lsbFirst: false);
$msb->putImage($canvas->region(Rect::of(0, 0, 1, 1)), 1, 1, 0, 0);
$check('MSBFirst swaps every pixel', bin2hex($putImages($takeMsb())[0]['data']), 'ff010203');

// The swap runs a slice at a time, so an image bigger than one slice is where a
// boundary bug would show — as a band of the picture in the wrong colour.
$msb->putImage($canvas->region(Rect::of(0, 0, 40, 30)), 40, 30, 0, 0);
$wide = $putImages($takeMsb())[0]['data'];
$check('and keeps doing it across its own slice boundary',
    [$wide === str_repeat(pack('N', 0xFF010203), 40 * 30), strlen($wide)],
    [true, 40 * 30 * 4]);

// The two fields the canvas added to the setup record. Hand-built rather than
// captured, because the point is that the *offsets* are right: read one field
// short and every image afterwards is byte-swapped or truncated.
$check('the setup reply reports the byte order and the request cap',
    (function (): array {
        $vendor = 'test';

        // 40 bytes of fixed header, then the vendor string, then (no) pixmap
        // formats, then one 40-byte SCREEN — the layout SetupParser walks.
        $screen = pack('VVVVV', 1, 2, 0xFFFFFF, 0, 0)
                . pack('vvvvvv', 800, 600, 200, 150, 1, 1)
                . pack('V', 33)
                . pack('CCCC', 0, 0, 24, 0);

        $header = pack('CCvvv', 1, 0, 11, 0, intdiv(40 + strlen($vendor) + strlen($screen) - 8, 4))
                . pack('VVVV', 0, 0x600000, 0x1FFFFF, 0)
                . pack('vv', strlen($vendor), 32767)
                // numScreens, numFormats, image-byte-order = LSBFirst(0),
                // bitmap-bit-order, scanline-unit, scanline-pad.
                . pack('CCCCCC', 1, 0, 0, 0, 32, 32)
                . str_repeat("\x00", 6);

        [$event] = (new SetupParser())->parse($header . $vendor . $screen);

        return [$event->imageByteOrder, $event->maxRequestLength];
    })(),
    [0, 32767]);

// -----------------------------------------------------------------------------
echo "the painter blits the clip, not the image\n";
// -----------------------------------------------------------------------------

$painter = new CanvasPainter($themes);

$paintTree = new WidgetTree($themes);
$big       = new Canvas(30, 20, 60 + 2 * $border, 40 + 2 * $border, $ui, paper: [7, 7, 7]);
$paintTree->addRoot($big);

[$renderer, $take] = $wiredRenderer();
$painter->paint($big, $renderer);
$blits = $putImages($take());
$check('with no clip the whole visible image goes out',
    [$blits[0]['width'], $blits[0]['height']], [60, 40]);
$check('at the content origin, inside the frame',
    [$blits[0]['x'], $blits[0]['y']], [30 + $border, 20 + $border]);

$clip = Rect::of(40, 30, 10, 8);
$renderer->setClipRect($clip);
$painter->paint($big, $renderer);
$renderer->setClipRect(null);
$blits = $putImages($take());
$check('a clipped repaint blits only the clip',
    [$blits[0]['width'], $blits[0]['height']], [10, 8]);
$check('and puts it back where the clip was',
    [$blits[0]['x'], $blits[0]['y']], [40, 30]);

// An image smaller than its widget is blitted at its own size — the rest of the
// well is the theme's fill, not a stretched picture.
$smallImage = new Canvas(0, 0, 60, 60, $ui, imageWidth: 12, imageHeight: 9, paper: [7, 7, 7]);
$paintTree->addRoot($smallImage);
$painter->paint($smallImage, $renderer);
$blits = $putImages($take());
$check('a small image is not stretched to its widget',
    [$blits[0]['width'], $blits[0]['height']], [12, 9]);

// -----------------------------------------------------------------------------
echo "themes\n";
// -----------------------------------------------------------------------------

$themeTree = new WidgetTree($themes);
$follows   = new Canvas(0, 0, 20, 20, $ui);
$named     = new Canvas(0, 0, 20, 20, $ui, paper: [3, 3, 3]);
$drawnOn   = new Canvas(0, 0, 20, 20, $ui);
$themeTree->addRoot($follows);
$themeTree->addRoot($named);
$themeTree->addRoot($drawnOn);

$drawnOn->fillRect(0, 0, 4, 4, [5, 6, 7]);

$themes->select('cde');
$themeTree->refreshTheme();

$check('an undrawn canvas takes the new era\'s paper', $follows->pixelAt(0, 0), $themes->palette()->content);
$check('a named paper is left alone',                  $named->pixelAt(0, 0), [3, 3, 3]);
$check('and a drawing is never wiped by a theme',      $drawnOn->pixelAt(1, 1), [5, 6, 7]);

$themes->select('win9x');
$themeTree->refreshTheme();

// -----------------------------------------------------------------------------
echo "the handler\n";
// -----------------------------------------------------------------------------

$logger = new Logger('canvas-test');
$logger->pushHandler(new StreamHandler('php://stderr', Level::Error));

$client       = new X11Client(Factory::create(), new AsyncEventDispatcher(new ListenerRegistry(), $logger), $logger, $renderer, $themes);
$handlerTree  = new WidgetTree($themes);
$strokeCanvas = new Canvas(20, 10, 40 + 2 * $border, 30 + 2 * $border, $ui, paper: [255, 255, 255]);
$handlerTree->addRoot($strokeCanvas);

/** @var list<array{int, int, int, int, CanvasPhase}> $seen */
$seen = [];
$strokeCanvas->setOnPaint(static function (CanvasPaintEvent $event) use (&$seen): void {
    $seen[] = [$event->x, $event->y, $event->previousX, $event->previousY, $event->phase];
    // A pencil: join where the pointer was to where it is.
    $event->canvas->drawLine($event->previousX, $event->previousY, $event->x, $event->y, [0, 0, 0]);
});

$handler = new CanvasHandler($handlerTree, $client, $painter);

$press = static fn(int $x, int $y): X11ButtonPressEvent   => new X11ButtonPressEvent(1, 1, $x, $y, $x, $y, 0, 0);
$move  = static fn(int $x, int $y): X11MotionEvent        => new X11MotionEvent(1, $x, $y, $x, $y, 0, 0);
$up    = static fn(int $x, int $y): X11ButtonReleaseEvent => new X11ButtonReleaseEvent(1, 1, $x, $y, $x, $y, 0, 0);

$check('a press on the frame is not a press on the image', $handler->tryPress($press(20, 10)), false);
$check('a press outside the widget is nobody\'s',           $handler->tryPress($press(200, 200)), false);
$check('a motion with no press is ignored',                 $handler->tryMotion($move(30, 20)), false);

// The image starts at the content origin, so window (20 + border, 10 + border)
// is image (0, 0) — one number, read from the metrics on both sides.
$check('a press on the image is claimed', $handler->tryPress($press(20 + $border + 2, 10 + $border + 3)), true);
$check('and arrives in image coordinates, its own previous point',
    $seen[0], [2, 3, 2, 3, CanvasPhase::Begin]);

$handler->tryMotion($move(20 + $border + 9, 10 + $border + 3));
$check('motion carries where the stroke was', $seen[1], [9, 3, 2, 3, CanvasPhase::Draw]);
$check('and the segment between them is drawn, not just its ends',
    $strokeCanvas->pixelAt(5, 3), [0, 0, 0]);

// A drag off the edge keeps drawing along it rather than stopping or scribbling
// out of bounds — and keeps the capture, so a stroke that comes back is one
// stroke.
$check('a drag past the edge is claimed', $handler->tryMotion($move(20 + $border + 500, 10 + $border + 500)), true);
$check('and clamps to the last pixel',    [$seen[2][0], $seen[2][1]], [39, 29]);

$check('release ends the stroke', $handler->tryRelease($up(20 + $border + 9, 10 + $border + 3)), true);
$check('with the End phase',      $seen[3][4], CanvasPhase::End);
$check('after which motion is nobody\'s again', $handler->tryMotion($move(20 + $border + 4, 10 + $border + 4)), false);

// -----------------------------------------------------------------------------
printf("\n%s\n", $fail === 0 ? 'canvas: all checks passed' : sprintf('canvas: %d FAILED', $fail));
exit($fail === 0 ? 0 : 1);
