<?php

namespace App\Services\Exams;

use App\Models\Exam;
use App\Models\MarkingCriterion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Saves how a practical, viva or defence is marked: its lines (grouped into stations; marked on a scale,
 * as done / not done, or from a reading), and for practicals the station timings and handing in of work.
 * Used by the exams office (exam page) and by lecturers for practical tests in their courses.
 */
class PracticalSetup
{
    public static function rules(): array
    {
        return [
            'criteria' => ['present', 'array', 'max:200'],
            'criteria.*.id' => ['nullable', 'integer'],
            'criteria.*.text' => ['required', 'string', 'max:500'],
            'criteria.*.allowed_scores' => ['nullable', 'string', 'max:100'], // "0,0.25,0.5" like the legacy portal
            'criteria.*.max_score' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            // stations, how a line is marked, readings against an expected value, critical steps, guides
            'criteria.*.station' => ['nullable', 'string', 'max:120'],
            'criteria.*.kind' => ['nullable', Rule::in(MarkingCriterion::KINDS)],
            'criteria.*.expected' => ['nullable', 'numeric'],
            'criteria.*.tolerance' => ['nullable', 'numeric', 'min:0'],
            'criteria.*.unit' => ['nullable', 'string', 'max:20'],
            'criteria.*.critical' => ['nullable', 'boolean'],
            'criteria.*.guide' => ['nullable', 'string', 'max:1000'],
            'stations' => ['nullable', 'array', 'max:50'],
            'stations.*.name' => ['required', 'string', 'max:120'],
            'stations.*.minutes' => ['nullable', 'integer', 'min:0', 'max:600'],
            'evidence' => ['nullable', 'boolean'],
            'evidence_types' => ['nullable', 'array', 'max:60'],
            'evidence_types.*' => ['string', 'alpha_num', 'max:8'],
            'discipline' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function save(Exam $exam, array $data): void
    {
        DB::transaction(function () use ($exam, $data) {
            $keep = [];
            foreach ($data['criteria'] as $i => $c) {
                $kind = $c['kind'] ?? 'score';
                if (is_array($c['allowed_scores'] ?? null)) {
                    $c['allowed_scores'] = implode(',', $c['allowed_scores']); // templates give a list
                }
                $allowed = $kind === 'score' && isset($c['allowed_scores']) && trim((string) $c['allowed_scores']) !== ''
                    ? array_values(array_unique(array_map(fn ($v) => (float) trim($v), explode(',', (string) $c['allowed_scores']))))
                    : null;
                if ($allowed) {
                    sort($allowed);
                }
                $max = $allowed ? max($allowed) : (float) ($c['max_score'] ?? 0);
                abort_if($max <= 0, 422, "Criterion \"{$c['text']}\" needs allowed scores or a maximum.");
                abort_if($kind === 'measure' && (! isset($c['expected']) || $c['expected'] === null || ! isset($c['tolerance'])), 422,
                    "\"{$c['text']}\" is marked from a reading: give the expected value and the tolerance.");
                $row = MarkingCriterion::updateOrCreate(
                    ['id' => $c['id'] ?? null, 'exam_id' => $exam->id],
                    ['text' => $c['text'], 'allowed_scores' => $allowed, 'max_score' => $max, 'position' => $i, 'kind' => $kind,
                        'station' => isset($c['station']) && trim((string) $c['station']) !== '' ? trim((string) $c['station']) : null,
                        'expected' => $kind === 'measure' ? (float) $c['expected'] : null, 'tolerance' => $kind === 'measure' ? (float) $c['tolerance'] : null,
                        'unit' => $kind === 'measure' ? ($c['unit'] ?? null) : null, 'critical' => (bool) ($c['critical'] ?? false), 'guide' => $c['guide'] ?? null]
                );
                $keep[] = $row->id;
            }
            $exam->criteria()->whereNotIn('id', $keep ?: [0])->delete();
            if ($exam->type === 'practical') {
                $settings = (array) $exam->settings;
                $settings['practical'] = [
                    'discipline' => $data['discipline'] ?? data_get($settings, 'practical.discipline'),
                    'stations' => array_values(array_map(fn ($st) => ['name' => $st['name'], 'minutes' => (int) ($st['minutes'] ?? 0)], $data['stations'] ?? [])),
                    'evidence' => (bool) ($data['evidence'] ?? false),
                    'evidence_types' => array_values(array_unique(array_map('strtolower', $data['evidence_types'] ?? []))),
                ];
                $exam->forceFill(['settings' => $settings])->save();
            }
        });
    }
}
