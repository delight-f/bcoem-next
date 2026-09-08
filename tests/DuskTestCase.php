<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Firefox\FirefoxOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

/**
 * Dusk base for the BS5-migration marker suite (issue 2).
 *
 * The repo has no Chrome; the available browser is Firefox 154 driven by
 * geckodriver. Dusk 8 ships only a Chrome driver helper, so this test case
 * overrides `driver()` to open Firefox via geckodriver instead.
 *
 * The app under test is the running `php artisan serve` on port 8000 (see
 * .env APP_URL). Dusk 8 does NOT auto-start a PHP server — it targets
 * whatever `config('app.url')` resolves to, so the harness asserts against
 * the running dev server's rendered DOM.
 */
abstract class DuskTestCase extends BaseTestCase
{

    /**
     * Prepare for Dusk test execution.
     *
     * Dusk's default spawns ChromeDriver; this project drives Firefox via an
     * externally-provided geckodriver (start it separately: `geckodriver
     * --port=4444`). Running geckodriver as a child of the PHPUnit process
     * breaks Dusk's session teardown — the DELETE /session in
     * tearDownDuskClass can no longer reach it, producing a PHPUnit exit 2 on
     * otherwise-green tests — so an external driver is required rather than
     * auto-spawning one.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        $url = $_ENV['GECKODRIVER_URL'] ?? env('GECKODRIVER_URL') ?? 'http://127.0.0.1:4444';
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 4444);

        if (! self::portIsListening('127.0.0.1', $port)) {
            throw new \RuntimeException(
                'No geckodriver on port '.$port.'. Start one first: '
                .'`geckodriver --port='.$port.' --host=127.0.0.1` (install via '
                .'`sudo apt install firefox-geckodriver` or the mozilla/geckodriver '
                .'release if missing).'
            );
        }
    }

    /**
     * Create the RemoteWebDriver instance — Firefox over geckodriver.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new FirefoxOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--width=1920',
            $this->shouldStartMaximized() ? '' : '--height=1080',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge(['-headless']);
        })->reject(fn ($a) => $a === '')->all());

        return RemoteWebDriver::create(
            $_ENV['GECKODRIVER_URL'] ?? env('GECKODRIVER_URL') ?? 'http://127.0.0.1:4444',
            DesiredCapabilities::firefox()->setCapability(
                FirefoxOptions::CAPABILITY, $options
            )
        );
    }

    /**
     * Whether a TCP connection can be opened to $host:$port.
     */
    private static function portIsListening(string $host, int $port): bool
    {
        $conn = @fsockopen($host, $port, $errno, $errstr, 0.2);
        if ($conn === false) {
            return false;
        }
        fclose($conn);

        return true;
    }
}
