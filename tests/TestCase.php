<?php

namespace Tests;

use App\Support\SpreadsheetDownload;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Downloads are never sent in tests, so their files are not deleted after sending: give each parallel
        // process its own folder and empty it after every test.
        config(['siakad.downloads_dir' => sys_get_temp_dir().DIRECTORY_SEPARATOR.'siakad-downloads-test-'.(getenv('TEST_TOKEN') ?: '0')]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(SpreadsheetDownload::directory());

        parent::tearDown();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
