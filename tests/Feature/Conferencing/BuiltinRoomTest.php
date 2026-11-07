<?php

namespace Tests\Feature\Conferencing;

use App\Models\AcademicSession;
use App\Models\ConferenceServer;
use App\Models\Course;
use App\Models\CourseOffering;
use App\Models\Enrolment;
use App\Models\Meeting;
use App\Models\MeetingAttendance;
use App\Models\MeetingGuestLink;
use App\Models\MeetingPeer;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Exams\BuildsExams;
use Tests\TestCase;

/**
 * Built-in rooms (no meeting server: browsers connect directly, the platform passes connection details)
 * and guest links. The browser side is checked in tests/e2e; here: who gets in, limits, the waiting room,
 * message routing, host controls, attendance, and guests.
 */
class BuiltinRoomTest extends TestCase
{
    use BuildsExams, RefreshDatabase;

    private User $lecturer;

    private CourseOffering $offering;

    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlatform();
        $session = AcademicSession::create(['name' => '2026/2027', 'is_current' => true]);
        $semester = Semester::create(['academic_session_id' => $session->id, 'name' => 'First semester', 'is_current' => true, 'starts_on' => '2026-10-01']);
        $this->lecturer = User::factory()->create(['user_type' => 'staff', 'name' => 'Dr Ada Obi']);
        $this->lecturer->assignRole('lecturer');
        $course = Course::create(['code' => 'CHM 101', 'title' => 'General Chemistry', 'credit_units' => 3, 'level' => 100]);
        $this->offering = CourseOffering::create(['course_id' => $course->id, 'semester_id' => $semester->id, 'status' => 'open']);
        $this->offering->staff()->attach($this->lecturer->id, ['role' => 'coordinator']);
        foreach (range(1, 7) as $i) {
            $u = User::factory()->create(['user_type' => 'student', 'name' => "Student $i", 'identifier' => "VUG/$i"]);
            $u->assignRole('student');
            Enrolment::create(['course_offering_id' => $this->offering->id, 'user_id' => $u->id, 'status' => 'active']);
            $this->students[] = $u;
        }
    }

    private function meeting(array $settings = []): Meeting
    {
        $this->actingAs($this->lecturer)->post('/meetings', ['title' => 'Tutorial', 'type' => 'class', 'course_offering_id' => $this->offering->id,
            'starts_at' => now()->addMinutes(5)->format('Y-m-d H:i'), 'duration_minutes' => 60, 'timezone' => 'UTC', 'access' => 'course', 'settings' => $settings])->assertSessionHasNoErrors();

        return Meeting::latest('id')->firstOrFail();
    }

    /** Open the room as someone (a fresh browser) and return their peer id. */
    private function enter(User $u, Meeting $m): ?string
    {
        $this->flushSession();
        \Illuminate\Support\Facades\Cache::forget(\App\Http\Middleware\SingleStudentSession::key($u->id));
        $res = $this->actingAs($u)->get("/meetings/{$m->uuid}/room");
        if ($res->status() !== 200) {
            return null;
        }

        return $res->viewData('page')['props']['me']['peer_id'];
    }

    private function as(User $u, string $peer, Meeting $m)
    {
        // each simulated browser is a different session; the one-sign-in-per-student rule is tested elsewhere
        \Illuminate\Support\Facades\Cache::forget(\App\Http\Middleware\SingleStudentSession::key($u->id));

        return $this->actingAs($u)->withSession(['meeting_peer.'.$m->id => $peer]);
    }

    public function test_without_a_meeting_server_join_opens_the_built_in_room(): void
    {
        $m = $this->meeting();
        $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}")->assertInertia(fn (Assert $p) => $p->where('room.engine', 'builtin'));
        $this->actingAs($this->lecturer)->withHeader('X-Inertia', 'true')->post("/meetings/{$m->uuid}/join")
            ->assertStatus(409)->assertHeader('X-Inertia-Location', route('meetings.room', $m));

        // students wait until the host opens it
        $this->flushHeaders();
        $this->actingAs($this->students[0])->get("/meetings/{$m->uuid}/room")->assertRedirect("/meetings/{$m->uuid}");
        $host = $this->enter($this->lecturer, $m);
        $this->assertNotNull($host);
        $this->assertSame('live', $m->fresh()->status);
        $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}/room")->assertInertia(fn (Assert $p) => $p->component('meetings/room')
            ->where('me.moderator', true)->where('ice.0.urls.0', 'stun:stun.l.google.com:19302'));

        // with a meeting server connected, "automatic" meetings use it; a room already open keeps its engine
        ConferenceServer::create(['tenant_id' => null, 'name' => 'S', 'base_url' => 'https://bbb.test/bigbluebutton/', 'secret' => 'x', 'status' => 'online']);
        $this->assertSame('builtin', app(\App\Conferencing\BuiltinRoom::class)->engine($m->fresh()));
        $this->assertSame('bbb', app(\App\Conferencing\BuiltinRoom::class)->engine($this->meeting()));
        $this->assertSame('builtin', app(\App\Conferencing\BuiltinRoom::class)->engine($this->meeting(['engine' => 'builtin'])));
        // outsiders never get in
        $outsider = User::factory()->create(['user_type' => 'student']);
        $this->actingAs($outsider)->get("/meetings/{$m->uuid}/room")->assertForbidden();
    }

    public function test_browsers_exchange_connection_details_only_with_the_right_person(): void
    {
        $m = $this->meeting();
        $host = $this->enter($this->lecturer, $m);
        [$a, $b] = [$this->students[0], $this->students[1]];
        $pa = $this->enter($a, $m);
        $pb = $this->enter($b, $m);

        // a newcomer sees who is in the room
        $r = $this->as($b, $pb, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0, 'audio' => true, 'video' => false])->assertOk()->json();
        $this->assertSame('in', $r['state']);
        $this->assertEqualsCanonicalizing([$host, $pa], array_column($r['peers'], 'peer_id'));
        $this->assertSame([], $r['waiting']); // only hosts see the waiting room

        // B offers to A: only A receives it
        $this->as($b, $pb, $m)->postJson("/meetings/{$m->uuid}/room/signal", ['to' => $pa, 'type' => 'offer', 'payload' => '{"type":"offer","sdp":"v=0"}'])->assertOk();
        $ra = $this->as($a, $pa, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->assertOk()->json();
        $this->assertSame([['from' => $pb, 'type' => 'offer']], array_map(fn ($s) => ['from' => $s['from'], 'type' => $s['type']], $ra['signals']));
        $this->assertSame([], $this->as($this->lecturer, $host, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('signals'));
        // and only once
        $this->assertSame([], $this->as($a, $pa, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => $ra['last']])->json('signals'));

        // chat goes to everyone else, with the sender's name from the platform (not what they claim)
        $this->as($a, $pa, $m)->postJson("/meetings/{$m->uuid}/room/signal", ['type' => 'chat', 'payload' => 'Hello class'])->assertOk();
        $chat = collect($this->as($b, $pb, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('signals'))->firstWhere('type', 'chat');
        $this->assertSame(['name' => 'Student 1', 'text' => 'Hello class'], array_intersect_key(json_decode($chat['payload'], true), ['name' => 1, 'text' => 1]));

        // only hosts mute others or remove people; unknown types and strangers are refused
        $this->as($a, $pa, $m)->postJson("/meetings/{$m->uuid}/room/signal", ['to' => $pb, 'type' => 'mute'])->assertForbidden();
        $this->as($a, $pa, $m)->postJson("/meetings/{$m->uuid}/room/signal", ['to' => $pb, 'type' => 'script'])->assertStatus(422);
        $this->as($a, $pa, $m)->postJson("/meetings/{$m->uuid}/room/remove", ['peer' => $pb])->assertForbidden();
        $this->as($this->lecturer, $host, $m)->postJson("/meetings/{$m->uuid}/room/signal", ['to' => null, 'type' => 'mute'])->assertOk();
        $this->as($this->lecturer, $host, $m)->postJson("/meetings/{$m->uuid}/room/remove", ['peer' => $pb])->assertOk();
        $this->assertSame('removed', $this->as($b, $pb, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('state'));
        // a removed person cannot just reload back in
        $this->as($b, $pb, $m)->get("/meetings/{$m->uuid}/room")->assertRedirect("/meetings/{$m->uuid}");
    }

    public function test_rooms_are_kept_small_and_people_who_vanish_leave(): void
    {
        config(['conferencing.builtin.max_participants' => 4]);
        $m = $this->meeting();
        $this->enter($this->lecturer, $m);
        foreach (array_slice($this->students, 0, 3) as $s) {
            $this->assertNotNull($this->enter($s, $m));
        }
        $this->flushSession();
        $this->actingAs($this->students[3])->get("/meetings/{$m->uuid}/room")->assertRedirect("/meetings/{$m->uuid}")->assertSessionHas('error');

        // one browser goes quiet: after a while it counts as gone and frees its place
        MeetingPeer::where('user_id', $this->students[0]->id)->update(['last_seen_at' => now()->subMinute()]);
        $this->assertNotNull($this->enter($this->students[3], $m));
        $this->assertSame('left', MeetingPeer::where('user_id', $this->students[0]->id)->value('state'));

        // the same person opening the room again elsewhere replaces their older window
        $first = MeetingPeer::where('user_id', $this->students[3]->id)->value('peer_id');
        $this->enter($this->students[3], $m);
        $this->assertSame('left', MeetingPeer::where('peer_id', $first)->value('state'));
        $this->assertSame(1, MeetingPeer::where('user_id', $this->students[3]->id)->where('state', 'in')->count());
    }

    public function test_the_waiting_room_and_attendance(): void
    {
        $m = $this->meeting(['waiting_room' => true]);
        $host = $this->enter($this->lecturer, $m);
        $s = $this->students[0];
        $ps = $this->enter($s, $m);
        $this->assertSame('waiting', $this->as($s, $ps, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('state'));
        $this->assertSame([], $this->as($s, $ps, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('peers')); // sees nobody while waiting
        $this->assertSame($ps, $this->as($this->lecturer, $host, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('waiting.0.peer_id'));
        $this->as($this->lecturer, $host, $m)->postJson("/meetings/{$m->uuid}/room/admit", ['peer' => $ps, 'admit' => true])->assertOk();
        $this->assertSame('in', $this->as($s, $ps, $m)->postJson("/meetings/{$m->uuid}/room/poll", ['after' => 0])->json('state'));

        // leaving and the end of the meeting are recorded as attendance, like on the meeting server
        $this->travel(20)->minutes();
        $this->as($s, $ps, $m)->postJson("/meetings/{$m->uuid}/room/leave")->assertOk();
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/end")->assertRedirect();
        $this->assertSame('ended', $m->fresh()->status);
        $row = MeetingAttendance::where('meeting_id', $m->id)->where('user_id', $s->id)->sole();
        $this->assertEqualsWithDelta(1200, $row->seconds, 2);
        $this->assertSame(0, MeetingPeer::where('meeting_id', $m->id)->whereIn('state', ['in', 'waiting'])->count());
        $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}")->assertInertia(fn (Assert $p) => $p->has('attendance', 2));
    }

    public function test_guests_join_with_a_link_and_a_name_through_the_waiting_room(): void
    {
        $m = $this->meeting();
        $this->actingAs($this->students[0])->post("/meetings/{$m->uuid}/guest-link", ['enabled' => true])->assertForbidden();
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/guest-link", ['enabled' => true, 'passcode' => '4711'])->assertSessionHas('success');
        $link = MeetingGuestLink::sole();
        $this->actingAs($this->lecturer)->get("/meetings/{$m->uuid}")->assertInertia(fn (Assert $p) => $p->where('guestLink.enabled', true)->where('guestLink.passcode', true)
            ->where('guestLink.url', route('meetings.guest', $link->token)));
        $host = $this->enter($this->lecturer, $m);

        // a stranger with the link: no account, no sign-in
        auth()->forgetGuards();
        $this->flushSession();
        $this->get("/join/{$link->token}")->assertOk()->assertInertia(fn (Assert $p) => $p->component('meetings/guest')->where('needsPasscode', true)->where('open', true));
        $this->post("/join/{$link->token}", ['name' => 'Prof. Okafor', 'passcode' => 'wrong'])->assertSessionHasErrors('passcode');
        $this->post("/join/{$link->token}", ['name' => 'Prof. Okafor', 'passcode' => '4711'])->assertRedirect(route('meetings.guest.room', $link->token));
        $page = $this->get("/join/{$link->token}/room")->assertOk();
        $gp = $page->viewData('page')['props']['me']['peer_id'];
        $this->assertSame('Prof. Okafor (guest)', $page->viewData('page')['props']['me']['name']);
        $this->assertNull($page->viewData('page')['props']['endpoints']['end']);
        $this->assertSame('waiting', $this->postJson("/join/{$link->token}/room/poll", ['after' => 0])->json('state'));
        // guests cannot open anything else
        $this->get("/meetings/{$m->uuid}")->assertRedirect('/login');
        $this->getJson('/dashboard')->assertUnauthorized();

        $this->as($this->lecturer, $host, $m)->postJson("/meetings/{$m->uuid}/room/admit", ['peer' => $gp, 'admit' => true])->assertOk();
        auth()->forgetGuards();
        $this->withSession(['meeting_guest.'.$m->id => \App\Models\MeetingGuest::sole()->id, 'meeting_peer.'.$m->id => $gp])
            ->postJson("/join/{$link->token}/room/poll", ['after' => 0])->assertJsonPath('state', 'in')->assertJsonPath('peers.0.peer_id', $host);
        $this->assertDatabaseHas('meeting_attendance', ['meeting_id' => $m->id, 'user_id' => null, 'name' => 'Prof. Okafor (guest)']);

        // switching the link off, or a made-up link, closes the door
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/guest-link", ['enabled' => false]);
        auth()->forgetGuards();
        $this->get("/join/{$link->token}")->assertNotFound();
        $this->get('/join/'.str_repeat('a', 32))->assertNotFound();
    }

    public function test_guests_on_the_meeting_server_get_a_viewer_link_through_the_waiting_room(): void
    {
        Http::fake(['bbb.test/*' => Http::response('<response><returncode>SUCCESS</returncode><createTime>1</createTime></response>')]);
        ConferenceServer::create(['tenant_id' => null, 'name' => 'S', 'base_url' => 'https://bbb.test/bigbluebutton/', 'secret' => 'x', 'status' => 'online']);
        $m = $this->meeting(['engine' => 'bbb']);
        $m->update(['status' => 'live']);
        $this->actingAs($this->lecturer)->post("/meetings/{$m->uuid}/guest-link", ['enabled' => true]);
        $link = MeetingGuestLink::sole();
        auth()->forgetGuards();
        $this->flushSession();
        $url = $this->withHeader('X-Inertia', 'true')->post("/join/{$link->token}", ['name' => 'Mrs Bello'])->assertStatus(409)->headers->get('X-Inertia-Location');
        $this->assertStringContainsString('role=VIEWER', $url);
        $this->assertStringContainsString('guest=true', $url);
        $this->assertStringContainsString('fullName=Mrs%20Bello%20%28guest%29', $url);
        $this->assertStringContainsString('userID=g', $url);
    }

    public function test_relay_passwords_are_short_lived_when_a_shared_secret_is_set(): void
    {
        config(['conferencing.builtin.turn_urls' => ['turn:turn.example.edu:3478'], 'conferencing.builtin.turn_secret' => 's3cret']);
        $ice = app(\App\Conferencing\BuiltinRoom::class)->iceServers('peerabc');
        [$expires, $peer] = explode(':', $ice[1]['username']);
        $this->assertSame('peerabc', $peer);
        $this->assertGreaterThan(time(), (int) $expires);
        $this->assertSame(base64_encode(hash_hmac('sha1', $ice[1]['username'], 's3cret', true)), $ice[1]['credential']);
    }
}
