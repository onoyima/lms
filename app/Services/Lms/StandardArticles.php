<?php

namespace App\Services\Lms;

use App\Models\HelpArticle;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Help-centre articles every institution starts with: the written procedure for flags, false
 * positives, appeals and accessibility exceptions (LMS 115), and the device compatibility list
 * (Target 169). Institutions may edit them; an edited article is never overwritten.
 */
class StandardArticles
{
    public function ensure(Tenant $tenant): int
    {
        return app(TenantContext::class)->run($tenant, function () {
            $made = 0;
            foreach ($this->articles() as $slug => $a) {
                $existing = HelpArticle::where('slug', $slug)->first();
                if ($existing && $existing->updated_by !== null) {
                    continue; // edited by the institution
                }
                HelpArticle::updateOrCreate(['slug' => $slug], $a + ['published' => true, 'updated_by' => null]);
                $made++;
            }

            return $made;
        });
    }

    public function articles(): array
    {
        return [
            'exam-incidents-procedure' => ['title' => 'Exam flags, false positives, appeals and accessibility exceptions: the procedure', 'category' => 'Exams office', 'audience' => 'staff', 'body_html' => $this->procedure()],
            'exam-flags-and-appeals' => ['title' => 'If your exam is flagged: what happens and how to appeal', 'category' => 'Exams', 'audience' => 'all', 'body_html' => $this->studentGuide()],
            'device-compatibility' => ['title' => 'Computers, browsers and cameras that work for exams', 'category' => 'Exams', 'audience' => 'all', 'body_html' => $this->compatibility()],
        ];
    }

    private function procedure(): string
    {
        return <<<'HTML'
<p>This is the exams office procedure for anything the proctoring system flags. It applies to every exam on the platform, online or in a hall. <strong>No flag is a finding of malpractice.</strong> Flags are reasons to look; only people decide.</p>
<h2>1. During the exam</h2>
<ol>
<li>The proctor looks at the flag and the evidence attached (snapshot, recording, event list) before acting.</li>
<li>If it is clearly harmless (a cough picked up as noise, a candidate looking down at rough paper allowed by the exam), the proctor dismisses it with a short reason. The dismissal is recorded.</li>
<li>If it may matter, the proctor sends a message or warning through the console. Pausing or ending a candidate's attempt needs the chief invigilator or the exams officer, except for an obvious emergency.</li>
<li>For technical problems (power, network, camera failure), the proctor records a <em>technical</em> incident, not malpractice, and the candidate's time is restored if it was lost.</li>
<li>Call the exam-day contacts listed on the console for anything you cannot settle within five minutes.</li>
</ol>
<h2>2. After the exam: review</h2>
<ol>
<li>Every open incident is assigned to a reviewer who was not the proctor for that candidate.</li>
<li>The reviewer looks at all the evidence, including the synchronised recordings, the candidate's statement if any, and the statistical review of the paper.</li>
<li>The reviewer chooses: <em>dismiss</em> (false positive), <em>resolve with no action</em>, or <em>escalate</em> to the examination misconduct panel. The decision and reason are recorded and cannot be edited later.</li>
<li>Results for a candidate with an escalated incident are withheld; all other results are released as normal.</li>
</ol>
<h2>3. False positives</h2>
<ul>
<li>Common causes: poor lighting, glasses, head coverings, background noise, slow networks, older computers. None of these is evidence of misconduct.</li>
<li>When a type of flag proves unreliable for an exam or a group of candidates, the exams officer lowers its weight under Policy &gt; Event severity. The change is versioned and can be rolled back.</li>
<li>Candidates are never told they were flagged unless an incident is escalated; dismissed flags do not appear on their record.</li>
</ul>
<h2>4. Appeals</h2>
<ol>
<li>A candidate may appeal any decision within 14 days of results, from <em>My results</em> or the exceptions form.</li>
<li>The appeal is decided by someone who took no part in the original decision. The candidate can see the evidence used, except where it would identify another candidate.</li>
<li>The decision, with reasons, is sent to the candidate and kept with the incident.</li>
</ol>
<h2>5. Accessibility and hardware exceptions</h2>
<ul>
<li>Extra time, larger text, rest breaks and readers are set as accommodations on the candidate before the exam.</li>
<li>A candidate who cannot use a webcam, microphone or second camera (disability, no suitable device) is given a <em>hardware exception</em> under Administration &gt; Hardware exceptions. A second person approves it; the proctor sees that the check is switched off for that candidate.</li>
<li>Requests come from the student through the exceptions form, with evidence where needed (medical letter, disability office referral). Evidence is stored privately and seen only by the decision-maker.</li>
</ul>
<h2>6. Records</h2>
<p>All actions above are in the audit log. Camera and identity evidence is kept only for the period in the institution's evidence policy, then deleted automatically, unless an incident or appeal is still open.</p>
HTML;
    }

    private function studentGuide(): string
    {
        return <<<'HTML'
<p>During a proctored exam, the system may notice things like the exam window losing focus, another face on camera or noise in the room. These are only prompts for a person to look. <strong>A flag on its own never leads to a penalty.</strong></p>
<h2>What happens</h2>
<ul>
<li>A proctor checks the flag, usually within minutes. Most are dismissed because they are harmless.</li>
<li>If the proctor needs to, they will message you in the exam window. Read the message and carry on unless asked to do something.</li>
<li>If something technical goes wrong (power, network), your answers are saved and your time is restored when you come back.</li>
</ul>
<h2>If an incident is raised</h2>
<ul>
<li>You will be told, and you can give your side in writing.</li>
<li>Someone who was not your proctor reviews all the evidence before deciding.</li>
<li>You can appeal the decision within 14 days of results from <em>My results</em> or the exceptions form.</li>
</ul>
<h2>Accessibility and equipment</h2>
<p>If you need extra time, a reader or rest breaks, or you cannot use a webcam or second camera, use <em>Request an exception</em> before the exam. Attach any letter from the disability office or a doctor; only the person deciding sees it.</p>
HTML;
    }

    private function compatibility(): string
    {
        $c = config('compatibility');
        $table = function (array $rows, array $cols) {
            $h = '<table><thead><tr>'.implode('', array_map(fn ($x) => '<th>'.e($x).'</th>', array_values($cols))).'</tr></thead><tbody>';
            foreach ($rows as $r) {
                $h .= '<tr>'.implode('', array_map(fn ($k) => '<td>'.e((string) ($r[$k] ?? '')).'</td>', array_keys($cols))).'</tr>';
            }

            return $h.'</tbody></table>';
        };
        $browsers = array_map(fn ($b) => $b + ['min' => $b['minimum'] ? $b['minimum'].' or later' : '—'], $c['browsers']);

        return '<p>Check your computer from <em>My exams</em> at least a day before the exam. For proctored exams in a hall, the computers are already approved.</p>'
            .'<h2>Browsers</h2>'.$table($browsers, ['name' => 'Browser', 'min' => 'Version', 'status' => 'Status', 'note' => 'Notes'])
            .'<h2>Operating systems</h2>'.$table($c['systems'], ['name' => 'System', 'status' => 'Status', 'note' => 'Notes'])
            .'<h2>Hardware</h2>'.$table($c['hardware'], ['name' => 'Item', 'status' => 'Needed', 'note' => 'Notes']);
    }
}
