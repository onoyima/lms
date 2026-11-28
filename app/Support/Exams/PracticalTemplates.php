<?php

namespace App\Support\Exams;

/**
 * Starting points for practical exams in different disciplines. Each is a set of stations (timed tasks)
 * with the lines an examiner marks. A line is marked on a scale, as done / not done ("check"), or from a
 * reading compared with the expected value ("measure"). Critical lines zero their station when failed.
 * Examiners edit everything after loading a template: values, tolerances and wording are examples.
 */
class PracticalTemplates
{
    public static function all(): array
    {
        $s = fn (string $text, float $max = 2, ?string $guide = null, bool $critical = false) => ['text' => $text, 'kind' => 'score', 'allowed_scores' => range(0, (int) $max), 'max_score' => $max, 'guide' => $guide, 'critical' => $critical];
        $c = fn (string $text, float $max = 1, ?string $guide = null, bool $critical = false) => ['text' => $text, 'kind' => 'check', 'allowed_scores' => null, 'max_score' => $max, 'guide' => $guide, 'critical' => $critical];
        $m = fn (string $text, float $expected, float $tol, string $unit, float $max = 4, ?string $guide = null) => ['text' => $text, 'kind' => 'measure', 'allowed_scores' => null, 'max_score' => $max, 'expected' => $expected, 'tolerance' => $tol, 'unit' => $unit, 'guide' => $guide, 'critical' => false];

        return [
            'nursing' => ['name' => 'Nursing procedure', 'hint' => 'Procedure checklist, 0 / 0.5 / 1 per step (the current portal style).', 'evidence' => false, 'stations' => [
                ['name' => 'Procedure', 'minutes' => 15, 'criteria' => [
                    ['text' => 'Identifies the patient and explains the procedure', 'kind' => 'score', 'allowed_scores' => [0, 0.5, 1], 'max_score' => 1, 'critical' => true, 'guide' => 'Two identifiers; consent obtained.'],
                    ['text' => 'Performs hand hygiene', 'kind' => 'score', 'allowed_scores' => [0, 0.5, 1], 'max_score' => 1, 'critical' => true],
                    ['text' => 'Prepares the equipment in order', 'kind' => 'score', 'allowed_scores' => [0, 0.5, 1], 'max_score' => 1],
                    ['text' => 'Carries out the procedure correctly', 'kind' => 'score', 'allowed_scores' => [0, 0.5, 1], 'max_score' => 1],
                    ['text' => 'Leaves the patient comfortable and documents', 'kind' => 'score', 'allowed_scores' => [0, 0.5, 1], 'max_score' => 1],
                ]],
            ]],
            'clinical' => ['name' => 'Medical / clinical (OSCE)', 'hint' => 'Timed stations: history, examination, procedure, communication.', 'evidence' => false, 'stations' => [
                ['name' => 'History taking', 'minutes' => 8, 'criteria' => [$s('Introduces self, confirms identity, gains consent', 2, null, true), $s('Presenting complaint and its history', 4), $s('Past medical, drug and allergy history', 2), $s('Summarises and checks understanding', 2)]],
                ['name' => 'Physical examination', 'minutes' => 8, 'criteria' => [$c('Hand hygiene before touching the patient', 1, null, true), $s('Inspection, palpation, percussion, auscultation in order', 4), $s('Correct technique and patient comfort', 2), $s('Presents findings clearly', 2)]],
                ['name' => 'Clinical procedure', 'minutes' => 8, 'criteria' => [$c('Aseptic technique kept throughout', 2, null, true), $s('Prepares equipment', 2), $s('Performs the procedure', 4), $c('Safe disposal of sharps', 1, null, true)]],
                ['name' => 'Communication', 'minutes' => 8, 'criteria' => [$s('Explains in plain language', 2), $s('Shows empathy and responds to concerns', 2), $s('Agrees a plan with the patient', 2)]],
            ]],
            'chemistry' => ['name' => 'Chemistry laboratory', 'hint' => 'Titration with titre readings, technique, calculations and safety.', 'evidence' => true, 'evidence_types' => ['pdf', 'jpg', 'jpeg', 'png'], 'stations' => [
                ['name' => 'Safety', 'minutes' => 0, 'criteria' => [$c('Wears goggles and lab coat throughout', 1, null, true), $c('Handles acids and bases safely', 1, null, true)]],
                ['name' => 'Titration', 'minutes' => 45, 'criteria' => [
                    $c('Rinses the burette with the acid and the pipette with the base'),
                    $c('Reads the meniscus at eye level'),
                    $m('Accurate titre (average of concordant readings)', 24.50, 0.10, 'cm³', 6, 'Full mark within ±0.10 cm³ of the supervisor\'s value; half within ±0.20 cm³.'),
                    $s('Concordant readings (within 0.10 cm³ of each other)', 2),
                ]],
                ['name' => 'Calculations and report', 'minutes' => 30, 'criteria' => [$s('Correct equation and mole ratio', 2), $s('Correct calculation of concentration', 4), $m('Final concentration', 0.100, 0.002, 'mol/dm³', 4), $s('Clear, tidy results table', 2)]],
            ]],
            'physics' => ['name' => 'Physics laboratory', 'hint' => 'Set-up, readings, graph, result within tolerance, sources of error.', 'evidence' => true, 'evidence_types' => ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv'], 'stations' => [
                ['name' => 'Set-up', 'minutes' => 10, 'criteria' => [$c('Apparatus set up as in the diagram', 2), $c('Instruments zeroed / checked before use', 1)]],
                ['name' => 'Readings', 'minutes' => 30, 'criteria' => [$s('At least six sets of readings over a good range', 3), $s('Readings repeated and averaged', 2), $s('Correct units and significant figures', 2)]],
                ['name' => 'Graph', 'minutes' => 20, 'criteria' => [$s('Axes labelled with quantities and units', 2), $s('Suitable scale (over half the grid)', 2), $s('Points plotted accurately, line of best fit', 2), $s('Gradient worked out with a large triangle', 2)]],
                ['name' => 'Result', 'minutes' => 10, 'criteria' => [$m('Value obtained (e.g. g from a pendulum)', 9.81, 0.15, 'm/s²', 4), $s('Sources of error and precautions', 2)]],
            ]],
            'hardware' => ['name' => 'Electronics / computer hardware', 'hint' => 'Circuit build, soldering, testing with a meter, fault-finding, safety.', 'evidence' => true, 'evidence_types' => ['jpg', 'jpeg', 'png', 'pdf', 'sch', 'brd', 'ino', 'zip'], 'stations' => [
                ['name' => 'Safety', 'minutes' => 0, 'criteria' => [$c('Power off before handling the circuit', 1, null, true), $c('Uses ESD strap / handles boards by the edges', 1)]],
                ['name' => 'Assembly', 'minutes' => 40, 'criteria' => [$c('Components placed as in the schematic', 2), $c('Polarity correct (diodes, capacitors, ICs)', 2, null, true), $s('Soldering quality (shiny, no bridges)', 3), $s('Neat wiring and layout', 2)]],
                ['name' => 'Testing', 'minutes' => 20, 'criteria' => [$m('Output voltage', 5.00, 0.10, 'V', 4, 'Measured at the test point with a multimeter.'), $m('Current drawn', 20, 2, 'mA', 2), $c('Circuit works as specified', 3)]],
                ['name' => 'Fault-finding', 'minutes' => 20, 'criteria' => [$s('Uses a logical method to find the fault', 3), $c('Fault found and fixed', 3)]],
            ]],
            'software' => ['name' => 'Software engineering / programming', 'hint' => 'Working program, tests, code quality, version control, explanation. Candidates hand in their code.', 'evidence' => true, 'evidence_types' => ['zip', 'py', 'java', 'c', 'cpp', 'js', 'ts', 'php', 'ipynb', 'sql', 'pdf', 'md', 'txt'], 'stations' => [
                ['name' => 'Working solution', 'minutes' => 90, 'criteria' => [$s('Meets the requirements', 6, 'Run the program against the task sheet.'), $s('Test cases passed (score out of the tests given)', 10), $s('Handles invalid input and edge cases', 4)]],
                ['name' => 'Code quality', 'minutes' => 0, 'criteria' => [$s('Clear names, structure and comments', 4), $s('No duplicated code; sensible functions/classes', 3), $c('Version control used (several meaningful commits)', 2), $c('README explains how to run it', 1)]],
                ['name' => 'Explanation', 'minutes' => 10, 'criteria' => [$s('Explains design decisions', 3), $s('Answers questions about the code', 3), $c('Code is the candidate\'s own (can modify it live)', 2, null, true)]],
            ]],
            'biology' => ['name' => 'Biology / microbiology laboratory', 'hint' => 'Aseptic technique, staining, microscopy, recording results.', 'evidence' => true, 'evidence_types' => ['jpg', 'jpeg', 'png', 'pdf'], 'stations' => [
                ['name' => 'Aseptic technique', 'minutes' => 20, 'criteria' => [$c('Disinfects the bench and flames the loop', 2, null, true), $c('Works near the flame; lids kept on', 2, null, true), $s('Inoculates the plate correctly (streaking)', 3)]],
                ['name' => 'Staining and microscopy', 'minutes' => 30, 'criteria' => [$s('Prepares and fixes the smear', 2), $s('Gram stain steps in order and timed', 3), $s('Focuses correctly, oil immersion used safely', 2), $s('Identifies the organism / structures', 3)]],
                ['name' => 'Results', 'minutes' => 15, 'criteria' => [$s('Accurate labelled drawing or photo', 3), $s('Correct conclusion', 2)]],
            ]],
            'pharmacy' => ['name' => 'Pharmacy dispensing', 'hint' => 'Prescription check, calculations, labelling, counselling.', 'evidence' => false, 'stations' => [
                ['name' => 'Prescription check', 'minutes' => 10, 'criteria' => [$c('Identifies the prescription error / interaction', 2, null, true), $s('Checks patient details, dose and frequency', 3)]],
                ['name' => 'Calculation and preparation', 'minutes' => 20, 'criteria' => [$m('Quantity to dispense', 21, 0, 'tablets', 4), $s('Weighs / measures accurately', 3)]],
                ['name' => 'Labelling and counselling', 'minutes' => 10, 'criteria' => [$s('Label complete and correct', 3), $s('Explains use, side effects and storage', 3)]],
            ]],
            'workshop' => ['name' => 'Engineering workshop', 'hint' => 'Measured dimensions within tolerance, finish, safety, time.', 'evidence' => true, 'evidence_types' => ['jpg', 'jpeg', 'png', 'pdf', 'dxf', 'dwg', 'stl'], 'stations' => [
                ['name' => 'Safety', 'minutes' => 0, 'criteria' => [$c('Wears PPE; machine guards in place', 2, null, true)]],
                ['name' => 'Job', 'minutes' => 120, 'criteria' => [$m('Length', 100.00, 0.05, 'mm', 4), $m('Diameter', 25.00, 0.02, 'mm', 4), $s('Surface finish', 3), $s('Follows the drawing (features, chamfers)', 3)]],
                ['name' => 'Workmanship', 'minutes' => 0, 'criteria' => [$s('Correct use of tools and machines', 3), $c('Finished within the time', 1)]],
            ]],
            'blank' => ['name' => 'Start from scratch', 'hint' => 'One empty station to fill in yourself.', 'evidence' => false, 'stations' => [
                ['name' => 'Station 1', 'minutes' => 15, 'criteria' => [$s('First thing to mark', 2)]],
            ]],
        ];
    }

    /** Name and hint of each, for the picker. */
    public static function list(): array
    {
        return collect(self::all())->map(fn ($t, $k) => ['key' => $k, 'name' => $t['name'], 'hint' => $t['hint'], 'stations' => count($t['stations']),
            'lines' => collect($t['stations'])->sum(fn ($st) => count($st['criteria']))])->values()->all();
    }
}
