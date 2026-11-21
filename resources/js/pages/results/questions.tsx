import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Download, FileText } from 'lucide-react';
import { useState } from 'react';
import { inputClass, PageHeader } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Q = {
    n: number; id: number; type: string | null; stem_html: string | null; topic: string | null; mark: number; seen: number; answered: number; full_marks: number;
    full_percent: number | null; average: number | null; facility: number | null; discrimination: number | null; flag: string | null; reports: number;
    options: { label: string; html: string; correct: boolean; count: number; percent: number }[];
    statements: { label: string; html: string; true: boolean; percent: number }[];
};
type Script = { attempt_id: number; name: string | null; identifier: string | null; raw: number; max: number };

const TYPE: Record<string, string> = {
    single_choice: 'Single answer', multiple_choice: 'Multiple answers', true_false: 'True / false', multi_true_false: 'Multiple true / false', matching: 'Matching',
    ordering: 'Put in order', fill_blank: 'Fill in the blanks', numeric: 'Numeric', short_answer: 'Short answer', essay: 'Essay', file_upload: 'File upload',
};

/** Facility as a coloured bar: low means most students lost marks (check the question or the teaching). */
function Bar({ value }: { value: number | null }) {
    if (value === null) {
return <span className="text-muted-foreground">—</span>;
}

    const tone = value < 30 ? 'bg-status-critical' : value < 50 ? 'bg-status-warning' : 'bg-status-good';

    return (
        <span className="flex items-center gap-2">
            <span className="h-2 w-24 overflow-hidden rounded-full bg-muted"><span className={`block h-full ${tone}`} style={{ width: `${Math.min(100, value)}%` }} /></span>
            <span className="tabular-nums">{value}%</span>
        </span>
    );
}

/** Results question by question (the old system's statistics per question), and each student's script. */
export default function QuestionResults({ exam, questions, scripts }: { exam: { id: number; title: string }; questions: Q[]; scripts: Script[] }) {
    const [filter, setFilter] = useState<'all' | 'hard' | 'flagged' | 'reported'>('all');
    const [who, setWho] = useState('');
    const shown = questions.filter((q) => filter === 'all' || (filter === 'hard' && (q.facility ?? 100) < 40) || (filter === 'flagged' && q.flag) || (filter === 'reported' && q.reports > 0));
    const people = scripts.filter((s) => !who || `${s.name} ${s.identifier}`.toLowerCase().includes(who.toLowerCase()));

    return (
        <>
            <Head title={`Questions — ${exam.title}`} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title={exam.title}
                    lead="How each question went: how many students had it and answered it, how many got full marks, the average mark, and how often each option was chosen. Q1, Q2… are in the order most students met them."
                    actions={
                        <>
                            <Button variant="outline" asChild><Link href={`/results/${exam.id}`}>Results</Link></Button>
                            <Button asChild><a href={`/results/${exam.id}/questions/export`}><Download className="size-4" /> Statistics (Excel)</a></Button>
                            <Button variant="outline" asChild><a href={`/results/${exam.id}/score-sheet.xlsx`}><Download className="size-4" /> Scores per question</a></Button>
                        </>
                    }
                />

                <div className="grid gap-6 xl:grid-cols-[1fr_300px]">
                    <div className="grid content-start gap-4">
                        <div className="flex flex-wrap gap-1.5" role="tablist">
                            {([['all', `All (${questions.length})`], ['hard', 'Hard (under 40%)'], ['flagged', 'Check these'], ['reported', 'Reported by students']] as const).map(([k, l]) => (
                                <button key={k} role="tab" aria-selected={filter === k} onClick={() => setFilter(k)} className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${filter === k ? 'bg-brand text-brand-foreground' : 'bg-muted/60 hover:bg-muted'}`}>{l}</button>
                            ))}
                        </div>
                        {shown.length === 0 && <p className="surface p-6 text-sm text-muted-foreground">{questions.length ? 'No question matches.' : 'Statistics appear once students submit.'}</p>}
                        {shown.map((q) => (
                            <section key={q.id} className="surface grid gap-3 p-5">
                                <div className="flex flex-wrap items-center gap-2 text-xs">
                                    <span className="rounded-md bg-brand px-2 py-0.5 font-bold text-brand-foreground">Q{q.n}</span>
                                    <span className="rounded-md border border-border px-2 py-0.5">{TYPE[q.type ?? ''] ?? q.type}</span>
                                    <span className="rounded-md border border-border px-2 py-0.5">{q.mark} mark{q.mark === 1 ? '' : 's'}</span>
                                    {q.topic && <span className="rounded-md border border-border px-2 py-0.5">{q.topic}</span>}
                                    {q.reports > 0 && <span className="flex items-center gap-1 rounded-md bg-status-warning/15 px-2 py-0.5 font-semibold text-status-warning"><AlertTriangle className="size-3" /> {q.reports} report{q.reports === 1 ? '' : 's'}</span>}
                                </div>
                                <RichContent html={(q.stem_html ?? '').replace(/\[\[(\d{1,2})\]\]/g, '<span class="blank-chip">($1)</span>')} className="text-sm" />
                                <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                                    <div><dt className="text-xs text-muted-foreground">Students</dt><dd className="font-semibold tabular-nums">{q.seen}</dd></div>
                                    <div><dt className="text-xs text-muted-foreground">Answered</dt><dd className="font-semibold tabular-nums">{q.answered}</dd></div>
                                    <div><dt className="text-xs text-muted-foreground">Full marks</dt><dd className="font-semibold tabular-nums">{q.full_marks} <span className="font-normal text-muted-foreground">({q.full_percent ?? 0}%)</span></dd></div>
                                    <div><dt className="text-xs text-muted-foreground">Average mark</dt><dd className="font-semibold tabular-nums">{q.average ?? '—'} / {q.mark}</dd></div>
                                    <div><dt className="text-xs text-muted-foreground">Facility</dt><dd><Bar value={q.facility} /></dd></div>
                                </dl>
                                {q.options.length > 0 && (
                                    <ul className="grid gap-1 text-sm">
                                        {q.options.map((o) => (
                                            <li key={o.label} className={`grid grid-cols-[1.5rem_1fr_auto] items-center gap-2 rounded-lg px-2 py-1 ${o.correct ? 'bg-status-good/10' : ''}`}>
                                                <b>{o.label}</b>
                                                <span className="relative">
                                                    <span className={`absolute inset-y-0 left-0 rounded ${o.correct ? 'bg-status-good/20' : 'bg-muted'}`} style={{ width: `${o.percent}%` }} aria-hidden />
                                                    <RichContent html={o.html} className="relative" />
                                                </span>
                                                <span className="text-xs tabular-nums text-muted-foreground">{o.count} · {o.percent}%{o.correct ? ' · right' : ''}</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {q.statements.length > 0 && (
                                    <ul className="grid gap-1 text-sm">
                                        {q.statements.map((s) => (
                                            <li key={s.label} className="grid grid-cols-[1.5rem_1fr_auto] items-center gap-2 px-2 py-1">
                                                <b>{s.label})</b>
                                                <RichContent html={s.html} />
                                                <span className="text-xs text-muted-foreground">{s.true ? 'true' : 'false'} · {s.percent}% judged it right</span>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                {q.flag && <p className="text-xs text-status-warning">{q.flag}{q.discrimination !== null ? ` (discrimination ${q.discrimination.toFixed(2)})` : ''}</p>}
                            </section>
                        ))}
                    </div>

                    <aside className="surface grid h-fit content-start gap-3 p-4 xl:sticky xl:top-4">
                        <p className="flex items-center gap-2 font-medium"><FileText className="size-4 text-brand" /> Students&apos; scripts</p>
                        <input className={inputClass} placeholder="Name or matric number" value={who} onChange={(e) => setWho(e.target.value)} />
                        <ul className="grid max-h-[60vh] gap-1 overflow-y-auto">
                            {people.map((s) => (
                                <li key={s.attempt_id}>
                                    <Link href={`/results/${exam.id}/scripts/${s.attempt_id}`} className="flex items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-muted">
                                        <span className="min-w-0"><span className="block truncate">{s.name}</span><span className="font-mono text-xs text-muted-foreground">{s.identifier}</span></span>
                                        <span className="text-xs tabular-nums">{s.raw}/{s.max}</span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </aside>
                </div>
            </div>
        </>
    );
}

QuestionResults.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Question by question', href: '#' }] };
