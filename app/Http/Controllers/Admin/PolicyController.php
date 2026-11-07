<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faculty;
use App\Services\Audit\AuditLogger;
use App\Support\Exams\ExamSettings;
use App\Models\SettingsVersion;
use App\Services\Settings\SettingsHistory;
use App\Support\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** University-wide policy screen (LMS 4, 176, 202, 207, 213; Target 104-106, 207). Every change is audit-logged with before/after. */
class PolicyController extends Controller
{
    public function __construct(private AuditLogger $audit, private SettingsHistory $history) {}

    private const CONTACT_RULES = [
        'incident_contacts' => ['array', 'max:12'], 'incident_contacts.*.role' => ['required', 'string', 'max:80'], 'incident_contacts.*.name' => ['required', 'string', 'max:120'],
        'incident_contacts.*.phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9 +()-]*$/'], 'incident_contacts.*.email' => ['nullable', 'email', 'max:190'],
        'incident_contacts.*.hours' => ['nullable', 'string', 'max:80'],
        'exam_day_notice' => ['nullable', 'string', 'max:2000'],
    ];

    public function show(): Response
    {
        return Inertia::render('admin/policy', [
            'policy' => Policy::all(),
            'eventTypes' => array_keys(ExamSettings::EVENT_WEIGHTS),
            'defaultSeverity' => collect(array_keys(ExamSettings::EVENT_WEIGHTS))->mapWithKeys(fn ($t) => [$t => ExamSettings::severity($t)]),
            'proctoringKeys' => array_keys(ExamSettings::PROCTORING),
            'faculties' => Faculty::orderBy('name')->get(['id', 'name']),
            'roles' => \App\Support\Roles::names(),
            'roleLabels' => \App\Support\Roles::labels(),
            'campuses' => \App\Models\Venue::whereNotNull('campus')->where('campus', '!=', '')->distinct()->orderBy('campus')->pluck('campus')
                ->map(fn ($c) => ['name' => $c, 'key' => Policy::campusKey($c), 'settings' => (array) data_get(tenant()->settings, 'campus_policy.'.Policy::campusKey($c), [])]),
            'campusKeys' => Policy::CAMPUS_KEYS,
            'history' => SettingsVersion::with('user:id,name')->latest('id')->limit(40)->get()->values()->map(function ($v, $i) {
                $prev = SettingsVersion::where('scope', $v->scope)->where('id', '<', $v->id)->latest('id')->first();

                return ['id' => $v->id, 'scope' => $v->scope, 'note' => $v->note, 'by' => $v->user?->name, 'at' => $v->created_at,
                    'changed' => $prev ? SettingsHistory::diff($prev->data, $v->data) : array_keys($v->data)];
            }),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'grading_scale' => ['required', 'array', 'min:2', 'max:12'], 'grading_scale.*' => ['numeric', 'min:0', 'max:100'],
            'grade_points' => ['required', 'array'], 'grade_points.*' => ['numeric', 'min:0', 'max:10'],
            'pass_grade_min' => ['required', 'string', 'max:3'],
            'email_domains' => ['array', 'max:20'], 'email_domains.*' => ['string', 'max:120', 'regex:/^[a-z0-9.-]+\.[a-z]{2,}$/i'],
            'severity_overrides' => ['array'], 'severity_overrides.*' => ['nullable', 'integer', 'min:1', 'max:5'],
            'faculty_exam_defaults' => ['array'], 'exam_type_defaults' => ['array'],
            'min_attendance_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
            'password_min' => ['required', 'integer', 'min:8', 'max:64'],
            'password_mixed_case' => ['boolean'], 'password_numbers' => ['boolean'], 'password_symbols' => ['boolean'],
            'mfa_required_roles' => ['array'], 'mfa_required_roles.*' => ['string', Rule::in(\App\Support\Roles::names())],
            'export_approval_rows' => ['required', 'integer', 'min:0', 'max:1000000'],
            'max_carryover_units' => ['required', 'integer', 'min:0', 'max:30'],
            'resit_max_grade' => ['nullable', 'string', 'max:4'],
            'max_exams_per_day' => ['nullable', 'integer', 'min:1', 'max:4'],
            'access_review_days' => ['required', 'integer', 'min:30', 'max:365'],
            'auto_rollover' => ['boolean'], 'auto_rollover_copy_content' => ['boolean'],
        ] + self::CONTACT_RULES);
        $data['auto_rollover'] = (bool) ($data['auto_rollover'] ?? false);
        $data['auto_rollover_copy_content'] = (bool) ($data['auto_rollover_copy_content'] ?? true);
        $data['incident_contacts'] = array_values($data['incident_contacts'] ?? []);
        $data['exam_day_notice'] = trim((string) ($data['exam_day_notice'] ?? ''));
        abort_unless(in_array(0, array_map('floatval', $data['grading_scale']), true) || min($data['grading_scale']) == 0, 422, 'The lowest grade must start at 0.');
        abort_unless(array_key_exists($data['pass_grade_min'], $data['grading_scale']), 422, 'The pass grade must be one of the grades.');
        abort_if(! empty($data['resit_max_grade']) && ! array_key_exists($data['resit_max_grade'], $data['grading_scale']), 422, 'The resit grade cap must be one of the grades.');
        $data['max_exams_per_day'] ??= 2;
        $allowed = array_keys(ExamSettings::PROCTORING);
        foreach (['faculty_exam_defaults', 'exam_type_defaults'] as $k) {
            // keep known keys only, cast to the type of the platform default
            $data[$k] = collect($data[$k] ?? [])->map(fn ($v) => collect(array_intersect_key((array) $v, array_flip($allowed)))
                ->reject(fn ($x) => $x === null)
                ->map(fn ($x, $key) => match (gettype(ExamSettings::PROCTORING[$key])) {
                    'boolean' => (bool) $x, 'integer' => max(0, (int) $x), default => in_array($x, ['none', 'photo_match', 'proctor_review', 'warn', 'lock', 'submit'], true) ? $x : ExamSettings::PROCTORING[$key],
                })->all())->filter()->all();
        }
        $data['severity_overrides'] = array_filter($data['severity_overrides'] ?? []);
        $data['email_domains'] = array_values(array_unique(array_map('strtolower', $data['email_domains'] ?? [])));

        $this->history->save('policy', $data, $request->input('note'));
        $this->audit->record('policy.updated', tenant(), ['changed' => SettingsHistory::diff(Policy::DEFAULTS, $data)]);

        return back()->with('success', 'Policy saved. The previous version is kept under History.');
    }

    /** A campus's own settings on top of the institution's (Target 99). Empty fields fall back to the institution. */
    public function updateCampus(Request $request, string $campus): RedirectResponse
    {
        $name = \App\Models\Venue::whereNotNull('campus')->get(['campus'])->pluck('campus')->first(fn ($c) => Policy::campusKey($c) === $campus);
        abort_unless($name, 404);
        $data = $request->validate(self::CONTACT_RULES + [
            'max_exams_per_day' => ['nullable', 'integer', 'min:1', 'max:4'], 'min_attendance_pct' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);
        $data = array_intersect_key($data, array_flip(Policy::CAMPUS_KEYS));
        $data['incident_contacts'] = array_values($data['incident_contacts'] ?? []);
        $this->history->save('campus:'.$campus, $data, "Settings for $name");

        return back()->with('success', "Settings for $name saved.");
    }

    public function restore(SettingsVersion $version): RedirectResponse
    {
        $this->history->restore($version);

        return back()->with('success', "Rolled back to version {$version->id}. The rollback is itself a new version you can undo.");
    }
}
