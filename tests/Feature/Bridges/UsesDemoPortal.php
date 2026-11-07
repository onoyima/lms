<?php

namespace Tests\Feature\Bridges;

use App\Bridges\Vuna\Demo\DemoPortal;
use App\Bridges\Vuna\Portal;
use App\Bridges\Vuna\VunaBridgeServiceProvider;
use Illuminate\Support\Facades\DB;

/** Points the bridge at an in-memory copy of the portal tables filled with the demo people. */
trait UsesDemoPortal
{
    protected const BRIDGE_SECRET = 'bridge-secret-for-tests-0123456789';

    protected function bootDemoPortal(): void
    {
        config([
            'vuna_bridge.enabled' => true,
            'vuna_bridge.issuer' => 'http://localhost/bridge/vuna',
            'vuna_bridge.db' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false],
            'vuna_bridge.key_path' => storage_path('framework/testing/vuna-oidc-test.pem'),
            'vuna_bridge.clients' => [[
                'id' => 'exams-lms', 'secret' => self::BRIDGE_SECRET, 'name' => 'Exams & LMS', 'directory' => true,
                'redirect_uris' => ['http://localhost/sso/portal/callback'], 'post_logout_redirect_uris' => [],
            ]],
        ]);
        (new VunaBridgeServiceProvider($this->app))->registerConnection();
        DB::purge(Portal::CONNECTION);
        $this->app->forgetInstance(Portal::class);
        DemoPortal::build(DB::connection(Portal::CONNECTION));
    }

    protected function directoryToken(): string
    {
        return $this->withHeader('Authorization', 'Basic '.base64_encode('exams-lms:'.self::BRIDGE_SECRET))
            ->post('/bridge/vuna/token', ['grant_type' => 'client_credentials', 'scope' => 'directory'])->assertOk()->json('access_token');
    }
}
