<?php

namespace Tests\Feature\Platform;

use App\Support\ClientIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WellKnownTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_can_verify_which_institution_a_server_belongs_to(): void
    {
        config(['platform.signing_key_path' => storage_path('framework/testing/sign.key')]);
        app()->forgetInstance(ClientIdentity::class);

        $res = $this->getJson('/.well-known/exam-platform.json')->assertOk()->json();

        $payload = json_decode($res['payload'], true);
        $this->assertSame($this->tenant->slug, $payload['institution']['slug']);
        $this->assertSame(['web', 'desktop', 'seb'], $payload['channels']);
        $this->assertTrue(ClientIdentity::verify($res['payload'], $res['signature'], $res['public_key']));
        $this->assertFalse(ClientIdentity::verify(str_replace('Test', 'Fake', $res['payload']), $res['signature'], $res['public_key']));
    }

    public function test_the_desktop_app_can_name_its_institution_with_a_header(): void
    {
        \App\Models\Tenant::create(['slug' => 'other', 'name' => 'Other University']);

        $res = $this->getJson('/.well-known/exam-platform.json', ['X-Tenant' => 'other'])->json();

        $this->assertSame('other', json_decode($res['payload'], true)['institution']['slug']);
    }
}
