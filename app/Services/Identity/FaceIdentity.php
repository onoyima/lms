<?php

namespace App\Services\Identity;

use App\Models\Attempt;
use App\Models\FaceProfile;
use App\Models\IdentityCheck;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Evidence\EvidenceStore;
use App\Services\Exams\AttemptService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Face identity (Target 1-3, 101, 112, 172): a person enrols a face template with consent; it
 * is compared with their official ID photo; at exam entry and during the exam the live face is
 * compared with the enrolled template on the SERVER, and every check keeps a photo for a
 * person to review. A mismatch never punishes by itself — it alerts a proctor.
 *
 * Templates are 128-number face descriptors computed in the browser by a model served from
 * this platform (no third-party service). They are encrypted at rest and never sent back out.
 */
class FaceIdentity
{
    /** Euclidean distance below this counts as the same person (the model's guidance is 0.6; we are stricter). */
    public const MATCH = 0.5;

    /** Enrolment samples must agree with each other at least this well. */
    public const CONSISTENT = 0.45;

    public function __construct(private AuditLogger $audit, private EvidenceStore $evidence, private AttemptService $attempts) {}

    /** @param list<mixed> $d */
    public static function validDescriptor(mixed $d): bool
    {
        if (! is_array($d) || count($d) !== 128 || ! array_is_list($d)) {
            return false;
        }
        $norm = 0.0;
        foreach ($d as $v) {
            if (! is_int($v) && ! is_float($v) || ! is_finite((float) $v) || abs($v) > 1) {
                return false;
            }
            $norm += $v * $v;
        }

        return $norm > 0.01; // all-zero templates are rejected
    }

    public static function distance(array $a, array $b): float
    {
        $s = 0.0;
        for ($i = 0; $i < 128; $i++) {
            $s += ($a[$i] - $b[$i]) ** 2;
        }

        return sqrt($s);
    }

    /** @param list<list<float>> $samples */
    public static function average(array $samples): array
    {
        $out = array_fill(0, 128, 0.0);
        foreach ($samples as $s) {
            foreach ($s as $i => $v) {
                $out[$i] += $v / count($samples);
            }
        }

        return array_map(fn ($v) => round($v, 6), $out);
    }

    /** Self-enrolment: 3-5 samples taken a moment apart, plus one photo for people to compare. */
    public function enrol(User $user, array $samples, UploadedFile $photo): FaceProfile
    {
        if (count($samples) < 3 || count($samples) > 5 || array_filter($samples, fn ($s) => ! self::validDescriptor($s))) {
            throw ValidationException::withMessages(['samples' => 'The face capture was not valid. Try again in good light.']);
        }
        for ($i = 0; $i < count($samples); $i++) {
            for ($j = $i + 1; $j < count($samples); $j++) {
                if (self::distance($samples[$i], $samples[$j]) > self::CONSISTENT) {
                    throw ValidationException::withMessages(['samples' => 'The captures did not look like the same face. Keep only your face in view and try again.']);
                }
            }
        }
        $descriptor = self::average($samples);
        $profile = FaceProfile::firstOrNew(['user_id' => $user->id]);
        $this->forget($profile->photo_path);
        $profile->fill([
            'descriptor' => $descriptor, 'photo_path' => $this->put($user, 'enrolment', $photo),
            'consent_at' => now(), 'enrolled_at' => now(), 'verified_by' => null, 'verified_at' => null,
        ]);
        $this->compareWithId($profile);
        $profile->save();
        $this->audit->record('identity.enrolled', $user, ['status' => $profile->status, 'id_distance' => $profile->id_distance]);

        return $profile;
    }

    /** Official ID photo (uploaded by registry); its template is computed in the uploader's browser. */
    public function setIdPhoto(User $user, array $descriptor, UploadedFile $photo, User $by): FaceProfile
    {
        if (! self::validDescriptor($descriptor)) {
            throw ValidationException::withMessages(['descriptor' => 'No single clear face was found in the ID photo.']);
        }
        $profile = FaceProfile::firstOrNew(['user_id' => $user->id]);
        $this->forget($profile->id_photo_path);
        $profile->fill(['id_descriptor' => $descriptor, 'id_photo_path' => $this->put($user, 'id', $photo)]);
        $this->compareWithId($profile);
        $profile->save();
        $this->audit->record('identity.id_photo_set', $user, ['by' => $by->id, 'id_distance' => $profile->id_distance]);

        return $profile;
    }

    /** Auto-verify only when enrolment clearly matches the ID photo; everything else waits for a person. */
    private function compareWithId(FaceProfile $p): void
    {
        if (! $p->descriptor) {
            $p->status = 'pending';

            return;
        }
        if (! $p->id_descriptor) {
            $p->id_distance = null;
            $p->status = 'pending';

            return;
        }
        $p->id_distance = round(self::distance($p->descriptor, $p->id_descriptor), 3);
        $p->status = $p->id_distance < self::MATCH * 0.9 ? 'verified' : 'pending';
        $p->note = $p->status === 'verified' ? 'Matched the ID photo automatically' : 'Needs a person to compare with the ID photo';
    }

    public function decide(FaceProfile $p, bool $approve, User $by, ?string $note): void
    {
        $p->update(['status' => $approve ? 'verified' : 'rejected', 'verified_by' => $by->id, 'verified_at' => now(), 'note' => $note]);
        $this->audit->record('identity.'.($approve ? 'verified' : 'rejected'), $p->user, ['note' => $note]);
    }

    /** Right to erasure: remove templates and photos (checks keep only their result). */
    public function erase(FaceProfile $p, User $by): void
    {
        $this->forget($p->photo_path);
        $this->forget($p->id_photo_path);
        $this->audit->record('identity.erased', $p->user, ['by' => $by->id]);
        $p->delete();
    }

    /**
     * A check during an exam. The browser sends the live template (or how many faces it saw)
     * and a photo; the decision is made here.
     */
    public function check(Attempt $attempt, string $kind, ?array $descriptor, int $faces, ?UploadedFile $photo): IdentityCheck
    {
        // "proctor_review" exams only keep the photo for a person to look at
        if (\App\Support\Exams\ExamSettings::proctoring($attempt->exam->proctoring)['identity_check'] === 'proctor_review') {
            $evidenceId = $photo ? $this->evidence->store($attempt, 'snapshot', $photo->get(), $photo->getMimeType() ?: 'image/jpeg')->id : null;

            return IdentityCheck::create(['user_id' => $attempt->user_id, 'attempt_id' => $attempt->id, 'kind' => $kind, 'result' => 'review',
                'distance' => null, 'evidence_file_id' => $evidenceId, 'created_at' => now()]);
        }

        $profile = FaceProfile::where('user_id', $attempt->user_id)->first();
        // a self-enrolment only counts once verified; the official ID photo always counts
        $refs = $profile && $profile->status !== 'rejected'
            ? array_values(array_filter([$profile->status === 'verified' ? $profile->descriptor : null, $profile->id_descriptor])) : [];
        $distance = null;
        $result = match (true) {
            $faces === 0 => 'no_face',
            $faces > 1 => 'multiple_faces',
            $refs === [] => 'no_profile',
            $descriptor === null || ! self::validDescriptor($descriptor) => 'no_face',
            default => null,
        };
        if ($result === null) {
            // compare with the verified enrolment and the ID photo; the closer wins
            $distance = round(min(array_map(fn ($r) => self::distance($descriptor, $r), $refs)), 3);
            $result = $distance < self::MATCH ? 'match' : 'mismatch';
        }
        $evidenceId = $photo ? $this->evidence->store($attempt, 'snapshot', $photo->get(), $photo->getMimeType() ?: 'image/jpeg')->id : null;
        $check = IdentityCheck::create(['user_id' => $attempt->user_id, 'attempt_id' => $attempt->id, 'kind' => $kind, 'result' => $result,
            'distance' => $distance, 'evidence_file_id' => $evidenceId, 'created_at' => now()]);

        if ($result === 'mismatch') {
            $this->attempts->event($attempt, 'identity_mismatch', ['kind' => $kind, 'distance' => $distance, 'check' => $check->id], 5);
            $this->attempts->flagForReview($attempt, 'identity_mismatch', 5);
            $attempt->forceFill(['risk_score' => min(100, $attempt->risk_score + 40)])->save();
        } elseif ($result === 'no_profile') {
            $this->attempts->event($attempt, 'identity_unverified', ['kind' => $kind], 3);
            $kind === 'entry' && $this->attempts->flagForReview($attempt, 'identity_unverified', 3);
        } elseif ($result !== 'match') {
            $this->attempts->event($attempt, $result === 'no_face' ? 'face_absent' : 'multiple_faces', ['kind' => 'identity_'.$kind], $result === 'no_face' ? 3 : 4);
        }

        return $check;
    }

    private function put(User $user, string $kind, UploadedFile $photo): string
    {
        abort_unless(in_array($photo->getMimeType(), ['image/jpeg', 'image/png', 'image/webp'], true), 422, 'Photos must be JPEG, PNG or WebP.');
        abort_if($photo->getSize() > 4 * 1024 * 1024, 413, 'Photo too large.');
        $path = 'identity/'.$user->tenant_id.'/'.$user->id.'/'.$kind.'_'.Str::random(12).'.bin';
        Storage::disk('local')->put($path, Crypt::encryptString(base64_encode($photo->get())));

        return $path;
    }

    public function photo(?string $path): ?string
    {
        return $path && Storage::disk('local')->exists($path) ? base64_decode(Crypt::decryptString(Storage::disk('local')->get($path))) : null;
    }

    private function forget(?string $path): void
    {
        $path && Storage::disk('local')->delete($path);
    }
}
