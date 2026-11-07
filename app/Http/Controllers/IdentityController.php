<?php

namespace App\Http\Controllers;

use App\Models\FaceProfile;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Identity\FaceIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Face enrolment for students and staff, and the registry's review queue (ID photos,
 * verification, erasure). Face templates never leave the server; photos are shown only to
 * the person themselves and to staff who manage users, and every staff view is audited.
 */
class IdentityController extends Controller
{
    public function __construct(private FaceIdentity $faces, private AuditLogger $audit) {}

    public function mine(Request $request): Response
    {
        $p = FaceProfile::where('user_id', $request->user()->id)->first();

        return Inertia::render('identity/enrol', [
            'profile' => $p ? ['status' => $p->status, 'enrolled_at' => $p->enrolled_at?->toIso8601String(), 'has_photo' => (bool) $p->photo_path,
                'has_id_photo' => (bool) $p->id_photo_path, 'note' => $p->status === 'rejected' ? $p->note : null] : null,
            'retention' => 'Kept while you are a member of the institution, then deleted. You can delete it at any time; exams that require identity checks will then ask a proctor to check you instead.',
        ]);
    }

    public function enrol(Request $request): RedirectResponse
    {
        $data = $request->validate(['samples' => ['required', 'string', 'max:20000'], 'photo' => ['required', 'file', 'image', 'max:4096'], 'consent' => ['accepted']]);
        $samples = json_decode($data['samples'], true);
        if (! is_array($samples)) {
            throw ValidationException::withMessages(['samples' => 'The face capture was not valid.']);
        }
        $p = $this->faces->enrol($request->user(), array_values($samples), $request->file('photo'));

        return back()->with('success', $p->status === 'verified' ? 'Face enrolled and matched to your ID photo.' : 'Face enrolled. The registry will confirm it against your ID photo.');
    }

    public function photo(Request $request)
    {
        $p = FaceProfile::where('user_id', $request->user()->id)->firstOrFail();

        return $this->image($this->faces->photo($p->photo_path));
    }

    public function eraseMine(Request $request): RedirectResponse
    {
        $p = FaceProfile::where('user_id', $request->user()->id)->first();
        $p && $this->faces->erase($p, $request->user());

        return back()->with('success', 'Your face data has been deleted.');
    }

    // ── Registry ────────────────────────────────────────────────────────────

    public function index(Request $request): Response
    {
        $status = $request->query('status', 'pending');
        $profiles = FaceProfile::with('user:id,name,identifier,email')->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByRaw('id_distance IS NULL')->orderByDesc('id_distance')->paginate(30)->withQueryString()
            ->through(fn ($p) => ['id' => $p->id, 'user' => $p->user?->only(['id', 'name', 'identifier', 'email']), 'status' => $p->status, 'note' => $p->note,
                'id_distance' => $p->id_distance, 'has_photo' => (bool) $p->photo_path, 'has_id_photo' => (bool) $p->id_photo_path,
                'enrolled_at' => $p->enrolled_at?->toIso8601String()]);

        return Inertia::render('identity/review', [
            'profiles' => $profiles, 'status' => $status,
            'counts' => FaceProfile::selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status'),
            'threshold' => FaceIdentity::MATCH,
        ]);
    }

    public function staffPhoto(Request $request, FaceProfile $profile, string $which)
    {
        abort_unless(in_array($which, ['enrolment', 'id'], true), 404);
        $this->audit->record('identity.photo_viewed', $profile->user, ['which' => $which]);

        return $this->image($this->faces->photo($which === 'id' ? $profile->id_photo_path : $profile->photo_path));
    }

    public function idPhoto(Request $request): RedirectResponse
    {
        $data = $request->validate(['identifier' => ['required', 'string', 'max:190'], 'descriptor' => ['required', 'string', 'max:5000'], 'photo' => ['required', 'file', 'image', 'max:4096']]);
        $user = User::where('identifier', $data['identifier'])->orWhere('email', $data['identifier'])->first();
        if (! $user) {
            throw ValidationException::withMessages(['identifier' => "No one with {$data['identifier']}."]);
        }
        $this->faces->setIdPhoto($user, (array) json_decode($data['descriptor'], true), $request->file('photo'), $request->user());

        return back()->with('success', "ID photo saved for {$user->name}.");
    }

    public function decide(Request $request, FaceProfile $profile): RedirectResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean'], 'note' => ['nullable', 'string', 'max:190']]);
        abort_if($profile->user_id === $request->user()->id, 403, 'You cannot verify your own face.');
        abort_if($data['approve'] && ! $profile->descriptor, 422, 'This person has not enrolled yet.');
        $this->faces->decide($profile, $data['approve'], $request->user(), $data['note'] ?? null);

        return back()->with('success', $data['approve'] ? 'Verified.' : 'Rejected. The person is asked to enrol again.');
    }

    public function erase(Request $request, FaceProfile $profile): RedirectResponse
    {
        $this->faces->erase($profile, $request->user());

        return back()->with('success', 'Face data deleted.');
    }

    private function image(?string $bytes)
    {
        abort_unless($bytes, 404);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'image/jpeg';

        return response($bytes, 200, ['Content-Type' => $mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
