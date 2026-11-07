<?php

namespace App\Bridges\Vuna\Http;

use App\Bridges\Vuna\Oidc\Tokens;
use App\Bridges\Vuna\Portal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Read-only directory API for the exams platform (contract "directory-bridge/1"): who is active now,
 * the running semester(s), faculties, departments, programmes, courses, course registrations and
 * teaching assignments. Access needs a client-credentials token with the "directory" scope.
 * Lists are cursor-paginated: pass ?after=<next> until "next" is null.
 */
class DirectoryController extends Controller
{
    public function __construct(private Portal $portal, private Tokens $tokens) {}

    private function guard(Request $request): ?JsonResponse
    {
        $access = $this->tokens->access($request->bearerToken());
        if (! $access || ! in_array('directory', $access['scopes'] ?? [], true)) {
            return response()->json(['error' => 'invalid_token', 'error_description' => 'A directory access token is needed.'], 401)
                ->header('WWW-Authenticate', 'Bearer error="invalid_token"');
        }

        return null;
    }

    private function answer(Request $request, callable $make): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        try {
            $body = $make(max(1, min(500, (int) $request->query('limit', 200))), max(0, (int) $request->query('after', 0)));
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'temporarily_unavailable', 'error_description' => 'The portal database cannot be read right now.'], 503);
        }

        return response()->json(['contract' => 'directory-bridge/1'] + $body)->header('Cache-Control', 'no-store');
    }

    /** A page of rows with the cursor for the next one. */
    private function page($rows, int $limit): array
    {
        return ['data' => $rows->values(), 'next' => $rows->count() === $limit ? $rows->last()['id'] : null];
    }

    public function term(Request $request): JsonResponse
    {
        return $this->answer($request, fn () => ['institution' => config('vuna_bridge.name'), 'semesters' => $this->portal->activeSemesters(), 'generated_at' => now()->toIso8601String()]);
    }

    public function health(Request $request): JsonResponse
    {
        return $this->answer($request, fn () => ['institution' => config('vuna_bridge.name'), 'counts' => $this->portal->counts()]);
    }

    public function faculties(Request $request): JsonResponse
    {
        return $this->answer($request, fn () => ['data' => $this->portal->faculties(), 'next' => null]);
    }

    public function departments(Request $request): JsonResponse
    {
        return $this->answer($request, fn () => ['data' => $this->portal->departments(), 'next' => null]);
    }

    public function programmes(Request $request): JsonResponse
    {
        return $this->answer($request, fn () => ['data' => $this->portal->programmes(), 'next' => null]);
    }

    public function courses(Request $request): JsonResponse
    {
        return $this->answer($request, fn (int $limit, int $after) => $this->page($this->portal->courses($after, $limit), $limit));
    }

    public function students(Request $request): JsonResponse
    {
        return $this->answer($request, fn (int $limit, int $after) => $this->page($this->portal->students($after, $limit), $limit));
    }

    public function student(Request $request, int $id): JsonResponse
    {
        return $this->answer($request, function () use ($id) {
            $student = $this->portal->student($id);
            abort_unless($student, 404);

            return ['data' => $student + ['registrations' => $this->portal->registrations(0, 500, $id)]];
        });
    }

    public function staff(Request $request): JsonResponse
    {
        return $this->answer($request, fn (int $limit, int $after) => $this->page($this->portal->staff($after, $limit), $limit));
    }

    public function staffMember(Request $request, int $id): JsonResponse
    {
        return $this->answer($request, function () use ($id) {
            $staff = $this->portal->staffMember($id);
            abort_unless($staff, 404);

            return ['data' => $staff];
        });
    }

    public function applicants(Request $request): JsonResponse
    {
        return $this->answer($request, fn (int $limit, int $after) => $this->page($this->portal->applicants($after, $limit), $limit));
    }

    public function registrations(Request $request): JsonResponse
    {
        return $this->answer($request, fn (int $limit, int $after) => $this->page($this->portal->registrations($after, $limit), $limit));
    }

    public function teaching(Request $request): JsonResponse
    {
        return $this->answer($request, fn (int $limit) => $this->portal->teaching($request->query('after') ? (string) $request->query('after') : null, $limit));
    }

    /**
     * Check a portal ID and password for the platform's sign-in page (people type their portal details there).
     * Tries the staff, student and applicant accounts; answers ok (with the person), inactive (with their
     * standing) or invalid. Passwords are never stored or logged; wrong tries are limited per login.
     */
    public function authenticate(Request $request): JsonResponse
    {
        if ($denied = $this->guard($request)) {
            return $denied;
        }
        $login = mb_substr(trim((string) $request->input('login')), 0, 190);
        $password = (string) $request->input('password');
        if ($login === '' || $password === '' || strlen($password) > 200) {
            return response()->json(['contract' => 'directory-bridge/1', 'result' => 'invalid', 'data' => null]);
        }
        $key = 'vunabridge-auth:'.sha1(mb_strtolower($login));
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['contract' => 'directory-bridge/1', 'result' => 'locked', 'data' => null, 'retry_after' => \Illuminate\Support\Facades\RateLimiter::availableIn($key)]);
        }
        try {
            $matches = [];
            foreach (['staff', 'student', 'applicant'] as $type) {
                $account = $this->portal->account($type, $login);
                $hash = is_string($account['hash'] ?? null) && $account['hash'] !== '' ? $account['hash'] : OidcController::DUMMY_HASH;
                if ($account && $account['profile'] && $hash !== OidcController::DUMMY_HASH && password_verify($password, $hash)) {
                    $matches[] = $account['profile'];
                } else {
                    password_verify($password, OidcController::DUMMY_HASH); // same time either way
                }
            }
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['error' => 'temporarily_unavailable', 'error_description' => 'The portal database cannot be read right now.'], 503);
        }
        if (! $matches) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 300);

            return response()->json(['contract' => 'directory-bridge/1', 'result' => 'invalid', 'data' => null]);
        }
        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $active = array_values(array_filter($matches, fn ($p) => $p['active']));
        if (! $active) {
            return response()->json(['contract' => 'directory-bridge/1', 'result' => 'inactive', 'data' => null, 'type' => $matches[0]['type'], 'status' => $matches[0]['status']]);
        }

        // someone who is both staff and a student signs in as staff
        return response()->json(['contract' => 'directory-bridge/1', 'result' => 'ok', 'data' => $active[0]])->header('Cache-Control', 'no-store');
    }

    /** Current standing of named people: ?type=student|staff|applicant&ids=1,2,3 (at most 500). */
    public function statuses(Request $request): JsonResponse
    {
        return $this->answer($request, function () use ($request) {
            $type = (string) $request->query('type');
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('ids'))), fn ($i) => $i > 0)));
            abort_unless(in_array($type, ['student', 'staff', 'applicant'], true) && count($ids) <= 500, 422, 'Give type and up to 500 ids.');

            return ['data' => $ids === [] ? [] : match ($type) {
                'student' => $this->portal->studentStatuses($ids),
                'staff' => $this->portal->staffStatuses($ids),
                'applicant' => $this->portal->applicantStatuses($ids),
            }];
        });
    }
}
