<?php

namespace Tests\Feature\Platform;

use App\Support\Platform\PlatformSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The platform has its own name (set by the platform administrator). Veritas University is one
 * institution on it, so its name appears only in that institution's own data and integration
 * (the bridge to its student portal) and in the sample data, never in the platform itself.
 */
class PlatformIsNotAnInstitutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_platform_name_never_comes_from_the_servers_app_name(): void
    {
        config(['app.name' => 'Veritas']);
        PlatformSettings::flush();
        $this->assertSame('Exams & Learning', PlatformSettings::brand()['name']);

        PlatformSettings::put(['brand_name' => 'CampusOne']);
        PlatformSettings::flush();
        $this->assertSame('CampusOne', PlatformSettings::brand()['name']);
    }

    public function test_no_institution_name_is_built_into_the_platform(): void
    {
        $allowed = ['app/Bridges/Vuna/', 'config/vuna_bridge.php']; // Veritas University's own portal bridge
        $found = [];
        foreach (['app', 'config', 'routes', 'resources/js/pages', 'resources/js/components', 'resources/js/layouts', 'resources/views', 'resources/docs', 'resources/css', 'desktop/src'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $rel = str_replace('\\', '/', substr($file->getPathname(), strlen(base_path()) + 1));
                if (collect($allowed)->contains(fn ($a) => str_starts_with($rel, $a))) {
                    continue;
                }
                foreach (file($file->getPathname()) as $n => $line) {
                    if (stripos($line, 'veritas') !== false) {
                        $found[] = $rel.':'.($n + 1);
                    }
                }
            }
        }
        $this->assertSame([], $found, 'Institution name found in platform code: '.implode(', ', $found));
    }
}
