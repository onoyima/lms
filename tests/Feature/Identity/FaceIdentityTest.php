<?php

namespace Tests\Feature\Identity;

use App\Models\FaceProfile;
use App\Models\IdentityCheck;
use App\Models\Incident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

class FaceIdentityTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    /** A unit-length face template, with optional small noise. */
    private function face(int $seed, float $noise = 0.0): array
    {
        mt_srand($seed);
        $v = array_map(fn () => mt_rand(-1000, 1000) / 1000, range(1, 128));
        if ($noise) {
            mt_srand($seed * 7919 + (int) ($noise * 1000));
            $v = array_map(fn ($x) => $x + mt_rand(-1000, 1000) / 1000 * $noise, $v);
        }
        $n = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return array_map(fn ($x) => round($x / $n, 6), $v);
    }

    private function photo(): UploadedFile
    {
        return UploadedFile::fake()->image('face.jpg', 200, 200);
    }

    public function test_enrolment_needs_consent_and_consistent_samples_and_keeps_templates_private(): void
    {
        $this->setUpPlatform();
        $s = User::factory()->create(['user_type' => 'student']);
        $s->assignRole('student');
        $same = [$this->face(1, 0.01), $this->face(1, 0.02), $this->face(1, 0.03)];

        $this->actingAs($s)->post('/my/identity', ['samples' => json_encode($same), 'photo' => $this->photo()])->assertSessionHasErrors('consent');
        $this->actingAs($s)->post('/my/identity', ['samples' => json_encode([$this->face(1), $this->face(2), $this->face(3)]), 'photo' => $this->photo(), 'consent' => '1'])->assertSessionHasErrors('samples');
        $this->actingAs($s)->post('/my/identity', ['samples' => json_encode([[1, 2, 3], [1], [2]]), 'photo' => $this->photo(), 'consent' => '1'])->assertSessionHasErrors('samples');
        $this->actingAs($s)->post('/my/identity', ['samples' => json_encode($same), 'photo' => $this->photo(), 'consent' => '1'])->assertSessionHasNoErrors();

        $p = FaceProfile::where('user_id', $s->id)->first();
        $this->assertSame('pending', $p->status); // no ID photo yet: a person must confirm
        $raw = \DB::table('face_profiles')->where('id', $p->id)->value('descriptor');
        $this->assertStringNotContainsString((string) $same[0][0], $raw); // encrypted at rest
        $this->actingAs($s)->get('/my/identity')->assertInertia(fn ($page) => $page->missing('profile.descriptor')->where('profile.status', 'pending'));
        $this->actingAs($s)->get('/my/identity/photo')->assertOk();

        // students cannot reach the registry queue or other people's photos
        $this->actingAs($s)->get('/admin/identity')->assertForbidden();
        $this->actingAs($s)->get("/admin/identity/{$p->id}/photo/enrolment")->assertForbidden();
    }

    public function test_id_photo_match_verifies_automatically_and_registry_decides_the_rest(): void
    {
        $this->setUpPlatform();
        [$a, $b] = User::factory()->count(2)->create(['user_type' => 'student'])->all();
        $this->actingAs($this->officer)->post('/admin/identity/id-photo', ['identifier' => $a->email, 'descriptor' => json_encode($this->face(10)), 'photo' => $this->photo()])->assertSessionHasNoErrors();
        $this->actingAs($this->officer)->post('/admin/identity/id-photo', ['identifier' => $b->email, 'descriptor' => json_encode($this->face(20)), 'photo' => $this->photo()])->assertSessionHasNoErrors();

        $this->actingAs($a)->post('/my/identity', ['samples' => json_encode([$this->face(10, 0.01), $this->face(10, 0.02), $this->face(10, 0.015)]), 'photo' => $this->photo(), 'consent' => '1']);
        $this->actingAs($b)->post('/my/identity', ['samples' => json_encode([$this->face(30, 0.01), $this->face(30, 0.02), $this->face(30, 0.015)]), 'photo' => $this->photo(), 'consent' => '1']); // someone else's face

        $this->assertSame('verified', FaceProfile::where('user_id', $a->id)->value('status'));
        $pb = FaceProfile::where('user_id', $b->id)->first();
        $this->assertSame('pending', $pb->status);
        $this->assertGreaterThan(0.5, $pb->id_distance);

        $this->actingAs($this->officer)->get("/admin/identity/{$pb->id}/photo/id")->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'identity.photo_viewed']);
        $this->actingAs($this->officer)->post("/admin/identity/{$pb->id}", ['approve' => false, 'note' => 'Different person'])->assertRedirect();
        $this->assertSame('rejected', $pb->fresh()->status);

        $this->actingAs($b)->delete('/my/identity')->assertRedirect();
        $this->assertNull(FaceProfile::where('user_id', $b->id)->first());
    }

    public function test_exam_identity_checks_are_decided_on_the_server_and_mismatches_alert_a_person(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'identity_check' => 'photo_match', 'violation_threshold' => 3, 'violation_action' => 'lock']]);
        $c = $this->candidate($this->sitting($exam));
        FaceProfile::create(['user_id' => $c->user_id, 'descriptor' => $this->face(5), 'status' => 'verified', 'enrolled_at' => now()]);
        $token = $this->startAs($c)->json('token');

        $ok = $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/identity', ['kind' => 'entry', 'faces' => 1, 'descriptor' => json_encode($this->face(5, 0.02)), 'photo' => $this->photo()], ['Accept' => 'application/json']);
        $ok->assertOk()->assertJson(['result' => 'match'])->assertJsonMissingPath('distance');

        $bad = $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/identity', ['kind' => 'periodic', 'faces' => 1, 'descriptor' => json_encode($this->face(99)), 'photo' => $this->photo()], ['Accept' => 'application/json']);
        $bad->assertOk()->assertJson(['result' => 'mismatch']);
        $this->assertSame(2, IdentityCheck::count());
        $this->assertNotNull(IdentityCheck::where('result', 'mismatch')->value('evidence_file_id'));
        $this->assertTrue(Incident::where('title', 'like', 'identity_mismatch%')->where('needs_human_review', true)->exists());

        $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/identity', ['kind' => 'periodic', 'faces' => 2], ['Accept' => 'application/json'])->assertJson(['result' => 'multiple_faces']);
        // garbage templates are never treated as a match
        $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/identity', ['kind' => 'periodic', 'faces' => 1, 'descriptor' => json_encode(array_fill(0, 128, 0))], ['Accept' => 'application/json'])->assertJson(['result' => 'no_face']);
    }

    public function test_unverified_self_enrolment_does_not_count_as_proof(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['proctoring' => ['require_consent' => false, 'identity_check' => 'photo_match']]);
        $c = $this->candidate($this->sitting($exam));
        FaceProfile::create(['user_id' => $c->user_id, 'descriptor' => $this->face(5), 'status' => 'pending', 'enrolled_at' => now()]);
        $token = $this->startAs($c)->json('token');

        $this->withHeaders(['Authorization' => "Bearer $token"])->post('/api/exam/attempt/identity', ['kind' => 'entry', 'faces' => 1, 'descriptor' => json_encode($this->face(5))], ['Accept' => 'application/json'])
            ->assertJson(['result' => 'no_profile']);
    }

    public function test_proctoring_settings_are_type_checked(): void
    {
        $this->setUpPlatform();
        $exam = $this->cbtExam(['status' => 'draft']);
        $this->actingAs($this->officer)->put("/exams/{$exam->id}", ['proctoring' => ['identity_check' => '<script>', 'violation_action' => 'explode', 'webcam' => 'yes', 'snapshot_seconds' => 1, 'unknown' => 1]]);
        $p = $exam->fresh()->proctoring;
        $this->assertSame('none', $p['identity_check']);
        $this->assertSame('lock', $p['violation_action']);
        $this->assertTrue($p['webcam']);
        $this->assertSame(10, $p['snapshot_seconds']);
        $this->assertArrayNotHasKey('unknown', $p);
    }
}
