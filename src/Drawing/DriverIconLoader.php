<?php
declare(strict_types=1);

namespace Cyrnetix\X11\Drawing;

use Cyrnetix\X11\Drawing\Decoder\GdDecoder;
use Cyrnetix\X11\Drawing\Decoder\ImageDecoder;
use Cyrnetix\X11\Drawing\Decoder\NativeDecoder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * An {@see IconLoader} that reads through whichever decoder driver is available.
 *
 * The chain is walked in preference order and the first driver that is both
 * present *and* able to read the extension wins, so the fast path is taken when
 * it exists and the slow one when it does not — with nothing to configure
 * either way. The default order is GD then native, which means:
 *
 *  - **.png** goes through GD when the extension is loaded, ~8x faster end to
 *    end, and through this package's own parser when it is not;
 *  - **.ico** always goes through the native driver, because GD cannot read it
 *    and says so via {@see ImageDecoder::formats()} rather than failing.
 *
 * Scaling is applied afterwards by {@see IconScaler}, once, outside the drivers
 * — which is what makes the fallback *invisible*. A driver that returned icons
 * instead of rasters would have to reimplement the toolkit's alpha threshold to
 * agree with its neighbours, and the day the two disagreed the symptom would be
 * icons that look subtly different depending on which extensions are installed.
 *
 * **A driver failing is not fatal.** A file the preferred driver chokes on falls
 * through to the next that claims the format, and only an exhausted chain
 * throws. Decoders read files from disk that this package does not control, and
 * "GD refused this one PNG" should cost one icon, not the application.
 */
final class DriverIconLoader implements IconLoader
{
    /** @var list<ImageDecoder> */
    private readonly array $drivers;

    /**
     * @param list<ImageDecoder>|null $drivers Preference order; null uses GD
     *        first and this package's own parsers behind it.
     */
    public function __construct(
        ?array                           $drivers = null,
        private readonly IconScaler      $scaler  = new IconScaler(),
        private readonly LoggerInterface $logger  = new NullLogger(),
    ) {
        $this->drivers = $drivers ?? [new GdDecoder(), new NativeDecoder()];
    }

    /**
     * Drivers that could be used right now, in order, as id => formats.
     *
     * For diagnostics: "why are my icons slow" is otherwise invisible, and the
     * answer is usually that GD is not installed.
     *
     * @return array<string, list<string>>
     */
    public function available(): array
    {
        $available = [];
        foreach ($this->drivers as $driver) {
            if ($driver->isAvailable()) $available[$driver->id()] = $driver->formats();
        }

        return $available;
    }

    /** The driver that would read $path, or null when none can. */
    public function driverFor(string $path): ?ImageDecoder
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        foreach ($this->drivers as $driver) {
            if ($driver->isAvailable() && in_array($extension, $driver->formats(), true)) {
                return $driver;
            }
        }

        return null;
    }

    /** {@inheritDoc} */
    public function load(string $path): Icon
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $tried     = [];

        foreach ($this->drivers as $driver) {
            if (!$driver->isAvailable()) continue;
            if (!in_array($extension, $driver->formats(), true)) continue;

            try {
                return $this->scaler->toIcon($driver->decode($path, $this->scaler->preferredSize));
            } catch (\Throwable $e) {
                // Try the next driver that claims the format. A backend that
                // cannot read one particular file is not a reason to lose the
                // icon when another backend might manage it.
                $tried[] = $driver->id() . ': ' . $e->getMessage();
                $this->logger->debug('Icon decoder failed, trying the next', [
                    'driver' => $driver->id(),
                    'path'   => $path,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        throw new RuntimeException($tried === []
            ? "No image decoder available for .$extension: $path"
            : "Every decoder failed for $path (" . implode('; ', $tried) . ')');
    }
}
