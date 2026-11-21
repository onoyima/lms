import { router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { useState } from 'react';
import { textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

export type ReportGroup = {
    question_id: number; type: string; stem_html: string; options: { id: number; html: string; correct: boolean }[];
    count: number; open: number; status: string; resolution: string | null; response: string | null;
    reasons: Record<string, number>; notes: { note: string; reason: string; by: string | null }[];
};

const RESOLVED: Record<string, string> = {
    full_mark: 'Everyone was given full marks', drop: 'Taken out for everyone', fix_key: 'Answer corrected and everyone marked again', dismiss: 'Kept as it is',
};

/** Students' reports of faulty questions, by question, with the four ways to settle them. */
export function QuestionReportsPanel({ examId, groups, reasons }: { examId: number; groups: ReportGroup[]; reasons: Record<string, string> }) {
    if (!groups.length) {
        return (
            <div className="surface flex items-center gap-3 p-6 text-sm text-muted-foreground">
                <CheckCircle2 className="size-5 text-status-good" /> No student has reported a question on this exam. Students can report a question from the exam screen while they write.
            </div>
        );
    }

    return (
        <div className="grid gap-4">
            <p className="text-sm text-muted-foreground">Settle each report before approving results. Whatever you choose applies to every student who had the question, and their scripts are marked again.</p>
            {groups.map((g) => (
                <ReportCard key={g.question_id} examId={examId} g={g} reasons={reasons} />
            ))}
        </div>
    );
}

function ReportCard({ examId, g, reasons }: { examId: number; g: ReportGroup; reasons: Record<string, string> }) {
    const [response, setResponse] = useState('');
    const [fixing, setFixing] = useState(false);
    const [correct, setCorrect] = useState<number[]>(g.options.filter((o) => o.correct).map((o) => o.id));
    const objective = ['single_choice', 'multiple_choice', 'true_false', 'multi_true_false'].includes(g.type);
    const single = g.type === 'single_choice' || g.type === 'true_false';
    const settle = (action: string, extra: Record<string, unknown> = {}) =>
        router.post(`/exams/${examId}/question-reports/${g.question_id}`, { action, response, ...extra } as never, { preserveScroll: true });

    return (
        <section className={`surface grid gap-3 p-5 ${g.open ? 'border-status-warning/50' : ''}`}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <p className="flex items-center gap-2 font-medium">
                    {g.open ? <AlertTriangle className="size-4 text-status-warning" /> : <CheckCircle2 className="size-4 text-status-good" />}
                    {g.count} report{g.count === 1 ? '' : 's'}
                    {g.open > 0 && g.open !== g.count && <span className="text-sm text-muted-foreground">({g.open} new)</span>}
                </p>
                {!g.open && g.resolution && <span className="rounded-full bg-status-good/15 px-2.5 py-0.5 text-xs font-semibold text-status-good">{RESOLVED[g.resolution]}</span>}
            </div>
            <RichContent html={g.stem_html} className="text-sm" />
            {g.options.length > 0 && (
                <ul className="grid gap-1 text-sm">
                    {g.options.map((o, i) => (
                        <li key={o.id} className={`flex items-start gap-2 rounded-lg px-2 py-1 ${o.correct ? 'bg-status-good/10' : ''}`}>
                            {fixing && objective ? (
                                <input type={single ? 'radio' : 'checkbox'} name={`fix-${g.question_id}`} className="mt-1" checked={correct.includes(o.id)}
                                    onChange={(e) => setCorrect(single ? [o.id] : e.target.checked ? [...correct, o.id] : correct.filter((x) => x !== o.id))} aria-label={`Option ${String.fromCharCode(65 + i)} is ${g.type === 'multi_true_false' ? 'true' : 'right'}`} />
                            ) : (
                                <span className="w-4 font-semibold">{String.fromCharCode(65 + i)}</span>
                            )}
                            <RichContent html={o.html} className="flex-1" />
                            {o.correct && <span className="text-xs text-status-good">{g.type === 'multi_true_false' ? 'true' : 'marked right'}</span>}
                        </li>
                    ))}
                </ul>
            )}
            <div className="flex flex-wrap gap-1.5">
                {Object.entries(g.reasons).map(([r, n]) => (
                    <span key={r} className="rounded-md border border-border bg-card px-2 py-0.5 text-xs">
                        {n} × {reasons[r] ?? r}
                    </span>
                ))}
            </div>
            {g.notes.length > 0 && (
                <details>
                    <summary className="cursor-pointer text-sm font-medium">What students wrote ({g.notes.length})</summary>
                    <ul className="mt-2 grid gap-1.5 text-sm">
                        {g.notes.map((n, i) => (
                            <li key={i} className="rounded-lg bg-muted/50 px-3 py-2">
                                &ldquo;{n.note}&rdquo; <span className="text-xs text-muted-foreground">— {n.by ?? 'a student'}</span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
            {g.open > 0 ? (
                <div className="grid gap-2 border-t border-border pt-3">
                    <textarea className={`${textareaClass} min-h-16`} placeholder="Your decision, in a sentence (kept with the reports)" value={response} onChange={(e) => setResponse(e.target.value)} />
                    <div className="flex flex-wrap gap-2">
                        {objective && !fixing && <Button size="sm" variant="outline" onClick={() => setFixing(true)}>Correct the answer…</Button>}
                        {fixing && (
                            <>
                                <Button size="sm" disabled={g.type !== 'multi_true_false' && !correct.length} onClick={() => confirm('Correct the answer and mark every script again?') && settle('fix_key', { correct })}>Save the corrected answer</Button>
                                <Button size="sm" variant="ghost" onClick={() => setFixing(false)}>Cancel</Button>
                            </>
                        )}
                        <Button size="sm" variant="outline" onClick={() => confirm('Give every student full marks for this question?') && settle('full_mark')}>Full marks for everyone</Button>
                        <Button size="sm" variant="outline" onClick={() => confirm('Take this question out for everyone? The total goes down by its mark.') && settle('drop')}>Take the question out</Button>
                        <Button size="sm" variant="ghost" onClick={() => settle('dismiss')}>Keep it as it is</Button>
                    </div>
                </div>
            ) : (
                g.response && <p className="text-sm text-muted-foreground">Decision: {g.response}</p>
            )}
        </section>
    );
}
