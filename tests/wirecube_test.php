<?php
declare(strict_types=1);

/**
 * The widget gallery's Canvas tab: eight vertices, a perspective divide and
 * twelve edges, with no X server anywhere near it.
 *
 * `example/lib/WireCube.php` hands back line segments in pixel coordinates and
 * draws nothing itself, which is what makes it testable — and the geometry is
 * worth testing, because every way it can be wrong looks plausible on screen:
 * an orthographic projection reads as a flat hexagon, a sign slip spins the cube
 * inside out, and a scale that is slightly too generous flattens a corner
 * against the frame once every revolution rather than on the frame you happened
 * to look at.
 */
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/example/lib/WireCube.php';

use Cyrnetix\X11\Example\WireCube;

$fail  = 0;
$check = function (string $what, $got, $want) use (&$fail): void {
    $ok = $got === $want;
    if (!$ok) $fail++;
    printf("  %-56s %-24s %s\n", $what,
        is_array($got) ? (string) json_encode($got) : var_export($got, true),
        $ok ? 'ok' : 'FAIL (want ' . (is_array($want) ? (string) json_encode($want) : var_export($want, true)) . ')');
};

/** Pixel length of a segment. */
$length = static fn(array $s): float => sqrt(($s[2] - $s[0]) ** 2 + ($s[3] - $s[1]) ** 2);

echo "projection\n";

$cube     = new WireCube();
$segments = $cube->project(200, 150);

$check('a cube has twelve edges', count($segments), 12);

// Unrotated, the back face is the first four edges and the front face the next
// four. Both are squares about the centre, so each edge's endpoints straddle it.
$check('the unrotated cube is centred on the image',
    [$segments[0][0] + $segments[0][2], $segments[4][0] + $segments[4][2]],
    [200, 200]);

// The whole point of the divide: the near face is drawn larger. An
// orthographic projection would make these equal, and the cube would read as a
// flat outline with a cross through it.
$check('the near face is larger than the far one',
    $length($segments[4]) > $length($segments[0]) * 1.5, true);

$before = $cube->project(200, 150);
$cube->advance();
$check('advancing moves it', $cube->project(200, 150) !== $before, true);

echo "it stays inside the canvas\n";

// The claim the 0.7 scale factor rests on: no corner leaves the image at any
// angle. The canvas would clip a stray pixel silently, so nothing on screen
// would say this had broken — only a cube that looked slightly wrong.
foreach ([[200, 150], [284, 194], [60, 400], [40, 40]] as [$width, $height]) {
    $outside = 0;
    $spin    = new WireCube();

    for ($frame = 0; $frame < 500; $frame++) {
        foreach ($spin->project($width, $height) as [$x0, $y0, $x1, $y1]) {
            foreach ([[$x0, $y0], [$x1, $y1]] as [$x, $y]) {
                if ($x < 0 || $y < 0 || $x >= $width || $y >= $height) $outside++;
            }
        }
        $spin->advance();
    }

    $check(sprintf('%dx%d: no corner leaves the image in 500 frames', $width, $height), $outside, 0);
}

// And it is not staying inside by being tiny: it should fill most of the
// smaller dimension.
$spread = 0;
$spin   = new WireCube();
for ($frame = 0; $frame < 200; $frame++) {
    foreach ($spin->project(200, 150) as [$x0, $y0, $x1, $y1]) {
        $spread = max($spread, abs($y0 - 75), abs($y1 - 75));
    }
    $spin->advance();
}
$check('it fills most of the shorter side', $spread > 60 && $spread < 75, true);

printf("\n%s\n", $fail === 0 ? 'wirecube: all checks passed' : sprintf('wirecube: %d FAILED', $fail));
exit($fail === 0 ? 0 : 1);
