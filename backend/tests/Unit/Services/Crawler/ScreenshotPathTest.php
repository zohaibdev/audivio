<?php

namespace Tests\Unit\Services\Crawler;

use App\Services\Crawler\CrawlOrchestrator;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression coverage for screenshot path normalization.
 *
 * The stored path must be RELATIVE to the public disk (`screenshots/…`),
 * because the frontend serves it as `/storage/{path}`. Storing the full
 * absolute filesystem path makes every screenshot 403.
 *
 * This is deliberately a direct unit test of the private method rather
 * than a pipeline test: the bug only appears when the disk's base path
 * and the incoming path use *different* separators, which `Storage::fake()`
 * never produces (it is forward-slash on both sides). Every existing
 * pipeline test therefore passed while real audits stored broken paths.
 */
class ScreenshotPathTest extends TestCase
{
    private function toPublicPath(string $absolutePath): string
    {
        $method = new ReflectionMethod(CrawlOrchestrator::class, 'toPublicPath');

        return $method->invoke(app(CrawlOrchestrator::class), $absolutePath);
    }

    private function fakeDiskWithBasePath(string $basePath): void
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('path')->with('')->andReturn($basePath);
        Storage::shouldReceive('disk')->with('public')->andReturn($disk);
    }

    public function test_a_windows_backslash_base_path_still_yields_a_relative_path(): void
    {
        // The exact real-world shape: Laravel reports the base path with
        // backslashes, the Node crawler reports the file with forward
        // slashes. Comparing them unnormalized never matches.
        $this->fakeDiskWithBasePath('C:\\Users\\Zohaib\\project\\storage\\app\\public\\');

        $result = $this->toPublicPath('C:/Users/Zohaib/project/storage/app/public/screenshots/11/homepage/desktop.png');

        $this->assertSame('screenshots/11/homepage/desktop.png', $result);
    }

    public function test_a_posix_base_path_still_yields_a_relative_path(): void
    {
        $this->fakeDiskWithBasePath('/var/www/storage/app/public/');

        $result = $this->toPublicPath('/var/www/storage/app/public/screenshots/4/about/mobile.png');

        $this->assertSame('screenshots/4/about/mobile.png', $result);
    }

    public function test_a_base_path_without_a_trailing_separator_is_handled(): void
    {
        $this->fakeDiskWithBasePath('/var/www/storage/app/public');

        $result = $this->toPublicPath('/var/www/storage/app/public/screenshots/9/x/desktop.png');

        $this->assertSame('screenshots/9/x/desktop.png', $result);
    }

    public function test_a_path_outside_the_disk_is_returned_normalized_not_mangled(): void
    {
        $this->fakeDiskWithBasePath('/var/www/storage/app/public/');

        $result = $this->toPublicPath('D:\\elsewhere\\screenshots\\a.png');

        $this->assertSame('D:/elsewhere/screenshots/a.png', $result);
    }
}
