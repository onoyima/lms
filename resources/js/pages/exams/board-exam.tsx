import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Download, MonitorPlay, Search, Upload, UserPlus } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Field, formatDateTime, inputClass, PageHeader, Stat, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Exam = {
    id: number; title: string; code: string | null; type: string; status: string; duration_minutes: number; max_attempts: number;
    course: { code: string; title: string } | null; total_mark: number | null; pass_mark: number | null; pass_percent: number;
    editable: boolean; on_screen: boolean; questions: number;
};
type Summary = {
    candidates: number; started: number; writing: number; submitted: number; not_started: number; marking: number;
    mean: number | null; median: number | null; highest: number | null; lowest: number | null; std_dev: number | null; passed: number; failed: number;
};
type Band = { label: string; from: number; to: number; count: number; pass: boolean };
type Sitting = { id: number; name: string | null; venue: string | null; mode: string; status: string; starts_at: string; ends_at: string; candidates: number; writing: number; running: boolean };
type Row = {
    candidate_id: number; name: string; identifier: string | null; sitting: string | null; session_id: number; status: string; attempts: number;
    started_at: string | null; submitted_at: string | null; minutes: number | null; raw: number | null; max: number | null; percent: number | null; scaled: number | null; marking: boolean;
};
type Props = { exam: Exam; scale: number; summary: Summary; distribution: Band[]; sessions: Sitting[]; board: Row[]; can: { manage: boolean; rescale: boolean; add_questions: boolean } };

const STATUS_LABEL: Record<string, string> = {
    in_progress: 'writing', paused: 'paused', locked: 'locked', submitted: 'submitted', auto_submitted: 'time up', terminated: 'terminated',
    not_started: 'not started', absent: 'absent', scored: 'scored', not_scored: 'not scored',
};
const FILTERS = [
    ['all', 'Everyone'],
    ['writing', 'Writing now'],
    ['submitted', 'Submitted'],
    ['not_started', 'Not started'],
] as const;

/** One exam: live counts, statistics, grade spread, score board, rescaled download, students and questions. */
export default function ExamBoardExam({ exam, scale, summary, distribution, sessions, board, can }: Props) {
    const { flash } = usePage().props;
    const [scaleInput, setScaleInput] = useState(String(scale));
    const [filter, setFilter] = useState<(typeof FILTERS)[number][0]>('all');
    const [q, setQ] = useState('');
    const live = sessions.some((s) => s.running) || summary.writing > 0;

    // while anyone is writing, the numbers refresh themselves
    useEffect(() => {
        if (!live) {
            return;
        }

        const t = window.setInterval(() => router.reload({ only: ['summary', 'distribution', 'sessions', 'board'] }), 15000);

        return () => window.clearInterval(t);
    }, [live]);

    const rows = useMemo(() => board.filter((r) => {
        const okFilter = filter === 'all'
            || (filter === 'writing' && ['in_progress', 'paused', 'locked'].includes(r.status))
            || (filter === 'submitted' && ['submitted', 'auto_submitted', 'terminated', 'scored'].includes(r.status))
            || (filter === 'not_started' && ['not_started', 'absent', 'not_scored'].includes(r.status));

        return okFilter && (!q || `${r.name} ${r.identifier ?? ''}`.toLowerCase().includes(q.toLowerCase()));
    }), [board, filter, q]);

    const applyView = (v: number) => {
        setScaleInput(String(v));
        router.get(`/exam-board/${exam.id}`, { scale: v }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const scaleLabel = Number.isInteger(scale) ? String(scale) : scale.toFixed(2);

    return (
        <>
            <Head title={`${exam.title} — exam board`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    title={exam.title}
                    eyebrow={[exam.code, exam.course ? `${exam.course.code} ${exam.course.title}` : null, exam.type.toUpperCase(), `${exam.duration_minutes} min`].filter(Boolean).join(' · ')}
                    actions={
                        <>
                            <Button variant="outline" asChild><Link href="/exam-board">All exams</Link></Button>
                            {can.manage && <Button variant="outline" asChild><Link href={`/exams/${exam.id}`}>Exam settings</Link></Button>}
                            <Button asChild><a href={`/exam-board/${exam.id}/export?scale=${scale}&format=xlsx`}><Download className="size-4" /> Excel (out of {scaleLabel})</a></Button>
                            <Button variant="outline" asChild><a href={`/exam-board/${exam.id}/export?scale=${scale}&format=pdf`}>PDF</a></Button>
                            <Button variant="outline" asChild><a href={`/exam-board/${exam.id}/export?scale=${scale}&format=csv`}>CSV</a></Button>
                        </>
                    }
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}

                <section className="grid grid-cols-2 gap-3 md:grid-cols-5">
                    <Stat label="Candidates" value={summary.candidates} />
                    <Stat label="Writing now" value={summary.writing} tone={summary.writing ? 'good' : undefined} />
                    <Stat label="Submitted" value={summary.submitted} />
                    <Stat label="Not started" value={summary.not_started} />
                    <Stat label="Awaiting marking" value={summary.marking} tone={summary.marking ? 'warning' : undefined} />
                </section>

                {/* sittings: monitor and add students */}
                <section className="grid gap-3">
                    <h2 className="text-base font-semibold">Sittings</h2>
                    <Table head={['Sitting', 'When', 'Status', 'Candidates', 'Writing now', '']}>
                        {sessions.map((s) => (
                            <tr key={s.id}>
                                <Td className="font-medium">{s.name ?? 'Sitting'}<span className="block text-xs font-normal text-muted-foreground">{s.venue ?? (s.mode === 'remote' ? 'Online' : '—')}</span></Td>
                                <Td className="text-xs whitespace-nowrap">{formatDateTime(s.starts_at)}<span className="block text-muted-foreground">until {formatDateTime(s.ends_at)}</span></Td>
                                <Td>{s.running ? <Status value="live" /> : <Status value={s.status} />}</Td>
                                <Td className="tabular-nums">{s.candidates}</Td>
                                <Td className="tabular-nums">{s.writing}</Td>
                                <Td><Button size="sm" variant="outline" asChild><Link href={`/proctor/sessions/${s.id}`}><MonitorPlay className="size-4" /> Monitor</Link></Button></Td>
                            </tr>
                        ))}
                        {sessions.length === 0 && <tr><Td className="text-sm text-muted-foreground">No sitting is scheduled yet{can.manage ? ' — add one in the exam settings.' : '.'}</Td></tr>}
                    </Table>
                    {sessions.length > 0 && <AddStudents examId={exam.id} sessions={sessions} />}
                </section>

                {/* statistics and the grade spread */}
                <section className="grid gap-6 lg:grid-cols-[320px_1fr]">
                    <div className="surface grid content-start gap-3 p-5 text-sm">
                        <h2 className="text-base font-semibold">Statistics</h2>
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                            {[
                                ['Average', summary.mean],
                                ['Median', summary.median],
                                ['Highest', summary.highest],
                                ['Lowest', summary.lowest],
                            ].map(([label, v]) => (
                                <div key={label as string}><dt className="text-xs text-muted-foreground">{label}</dt><dd className="text-lg font-semibold tabular-nums">{v === null ? '—' : `${v}%`}</dd></div>
                            ))}
                            <div><dt className="text-xs text-muted-foreground">Spread (std. dev.)</dt><dd className="text-lg font-semibold tabular-nums">{summary.std_dev ?? '—'}</dd></div>
                            <div><dt className="text-xs text-muted-foreground">Pass mark</dt><dd className="text-lg font-semibold tabular-nums">{exam.pass_percent}%</dd></div>
                        </dl>
                        <div className="mt-1 grid grid-cols-2 gap-2 border-t border-border pt-3">
                            <p><span className="text-2xl font-semibold tabular-nums">{summary.passed}</span> <span className="text-muted-foreground">passed</span></p>
                            <p><span className="text-2xl font-semibold tabular-nums">{summary.failed}</span> <span className="text-muted-foreground">below the pass mark</span></p>
                        </div>
                    </div>
                    <Distribution bands={distribution} passPercent={exam.pass_percent} />
                </section>

                {/* rescaling */}
                <section className="surface grid gap-4 p-5">
                    <div>
                        <h2 className="text-base font-semibold">Scores out of</h2>
                        <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                            Every score below is shown out of the number you choose here, and the download uses it too. For example, a paper of 240 questions marked out of 240 can be shown out of 100, 70 or any number.
                            {exam.total_mark ? ` The exam's official total is ${exam.total_mark}.` : ' The exam has no official total yet, so results are out of the marks in the paper.'}
                        </p>
                    </div>
                    <form className="flex flex-wrap items-end gap-2" onSubmit={(e) => (e.preventDefault(), Number(scaleInput) > 0 && applyView(Number(scaleInput)))}>
                        <Field label="Out of">
                            <input type="number" min={1} max={1000} step="any" className={`${inputClass} w-28 tabular-nums`} value={scaleInput} onChange={(e) => setScaleInput(e.target.value)} />
                        </Field>
                        <Button type="submit" variant="outline">Show</Button>
                        {[100, 70, 60, 40, 30].map((v) => (
                            <button key={v} type="button" onClick={() => applyView(v)} className={`h-10 rounded-md px-3 text-sm tabular-nums ${scale === v ? 'bg-foreground text-background' : 'border border-border hover:bg-muted'}`}>{v}</button>
                        ))}
                        {can.rescale && (
                            <Button
                                type="button"
                                className="ml-auto"
                                onClick={() => confirm(`Make ${scaleLabel} the official total of this exam? Results and transcripts will use it.`) && router.post(`/exam-board/${exam.id}/scale`, { total_mark: scale }, { preserveScroll: true })}
                                disabled={exam.total_mark === scale}
                            >
                                Use {scaleLabel} as the official total
                            </Button>
                        )}
                    </form>
                    {!can.rescale && <p className="text-xs text-muted-foreground">Results are approved, so the official total cannot change here. You can still view and download any scale.</p>}
                </section>

                {/* score board */}
                <section className="grid gap-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-base font-semibold">Score board {live && <span className="ml-2 text-xs font-normal text-status-good">updating live</span>}</h2>
                        <div className="flex flex-wrap items-center gap-2 text-sm">
                            {FILTERS.map(([k, label]) => (
                                <button key={k} onClick={() => setFilter(k)} className={`rounded-md px-3 py-1.5 ${filter === k ? 'bg-foreground text-background' : 'border border-border'}`}>{label}</button>
                            ))}
                            <div className="relative w-56">
                                <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                                <input className={`${inputClass} pl-9`} placeholder="Name or matric number" value={q} onChange={(e) => setQ(e.target.value)} />
                            </div>
                        </div>
                    </div>
                    <Table head={['#', 'Name', 'Matric number', 'Sitting', 'Status', 'Started', 'Submitted', 'Time', 'Score', '%', `Out of ${scaleLabel}`, 'Result']}>
                        {rows.map((r, i) => (
                            <tr key={r.candidate_id}>
                                <Td className="text-xs text-muted-foreground tabular-nums">{i + 1}</Td>
                                <Td className="font-medium">{r.name}{r.attempts > 1 && <span className="block text-xs font-normal text-muted-foreground">{r.attempts} attempts</span>}</Td>
                                <Td className="font-mono text-xs">{r.identifier ?? '—'}</Td>
                                <Td className="text-xs">{r.sitting ?? '—'}</Td>
                                <Td><Status value={STATUS_LABEL[r.status] ?? r.status} /></Td>
                                <Td className="text-xs whitespace-nowrap">{r.started_at ? new Date(r.started_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}</Td>
                                <Td className="text-xs whitespace-nowrap">{r.submitted_at ? new Date(r.submitted_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '—'}</Td>
                                <Td className="text-xs tabular-nums">{r.minutes !== null ? `${r.minutes} min` : '—'}</Td>
                                <Td className="tabular-nums">{r.raw !== null ? `${r.raw} / ${r.max}` : r.marking ? <span className="text-xs text-status-warning">marking</span> : ''}</Td>
                                <Td className="tabular-nums">{r.percent !== null ? r.percent : ''}</Td>
                                <Td className="font-semibold tabular-nums">{r.scaled !== null ? r.scaled : ''}</Td>
                                <Td>{r.percent === null ? '' : r.percent >= exam.pass_percent ? <span className="text-xs text-status-good">pass</span> : <span className="text-xs text-status-critical">fail</span>}</Td>
                            </tr>
                        ))}
                        {rows.length === 0 && <tr><Td className="text-sm text-muted-foreground">Nobody here.</Td></tr>}
                    </Table>
                </section>

                {exam.on_screen && <UploadQuestions exam={exam} allowed={can.add_questions} />}
            </div>
        </>
    );
}

/** Grade spread in ten bands. One series, so no legend box; bars below the pass mark are muted. */
function Distribution({ bands, passPercent }: { bands: Band[]; passPercent: number }) {
    const top = Math.max(1, ...bands.map((b) => b.count));
    const max = top * 1.15; // room above the tallest bar for its number
    const ticks = [...new Set([top, top > 1 ? Math.round(top / 2) : null, 0].filter((v): v is number => v !== null))];
    const total = bands.reduce((n, b) => n + b.count, 0);
    const [hover, setHover] = useState<number | null>(null);

    return (
        <figure className="surface grid gap-3 p-5">
            <figcaption className="flex flex-wrap items-baseline justify-between gap-2">
                <span className="text-base font-semibold">Grade spread</span>
                <span className="flex items-center gap-4 text-xs text-muted-foreground">
                    <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-brand" /> at or above the pass mark ({passPercent}%)</span>
                    <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-sm bg-muted-foreground/35" /> below</span>
                </span>
            </figcaption>
            {total === 0 ? (
                <p className="py-10 text-center text-sm text-muted-foreground">Scores appear here as students submit.</p>
            ) : (
                <div className="grid grid-cols-[auto_1fr] gap-x-2">
                    <div className="relative h-52 w-6 text-right text-[11px] text-muted-foreground tabular-nums">
                        {ticks.map((t) => <span key={t} className="absolute right-0 translate-y-1/2" style={{ bottom: `${(t / max) * 100}%` }}>{t}</span>)}
                    </div>
                    <div className="relative h-52 border-b border-border">
                        {ticks.filter((t) => t > 0).map((t) => <div key={t} className="pointer-events-none absolute inset-x-0 border-t border-dashed border-border" style={{ bottom: `${(t / max) * 100}%` }} />)}
                        <div className="absolute inset-0 grid grid-cols-10 items-end gap-[2px]">
                            {bands.map((b, i) => (
                                <div
                                    key={b.label}
                                    className="relative flex h-full items-end justify-center"
                                    onMouseEnter={() => setHover(i)}
                                    onMouseLeave={() => setHover(null)}
                                    role="img"
                                    aria-label={`${b.label}%: ${b.count} students`}
                                >
                                    {b.count > 0 && <span className="absolute text-[11px] text-foreground tabular-nums" style={{ bottom: `calc(${(b.count / max) * 100}% + 2px)` }}>{b.count}</span>}
                                    <div
                                        className={`w-[70%] rounded-t-[4px] transition-opacity ${b.pass ? 'bg-brand' : 'bg-muted-foreground/35'} ${hover !== null && hover !== i ? 'opacity-50' : ''}`}
                                        style={{ height: `${(b.count / max) * 100}%`, minHeight: b.count ? 2 : 0 }}
                                    />
                                    {hover === i && (
                                        <div className="absolute bottom-full z-10 mb-6 rounded-md border border-border bg-card px-2.5 py-1.5 text-xs whitespace-nowrap shadow">
                                            <strong className="tabular-nums">{b.count}</strong> student{b.count === 1 ? '' : 's'} scored {b.label}%
                                            <span className="block text-muted-foreground">{Math.round((b.count / total) * 100)}% of those scored</span>
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    </div>
                    <span />
                    <div className="mt-1 grid grid-cols-10 gap-[2px] text-center text-[11px] text-muted-foreground tabular-nums">
                        {bands.map((b) => <span key={b.label}>{b.label}</span>)}
                    </div>
                    <span />
                    <p className="mt-1 text-center text-xs text-muted-foreground">Score (% of the maximum) · {total} students scored</p>
                </div>
            )}
        </figure>
    );
}

function AddStudents({ examId, sessions }: { examId: number; sessions: Sitting[] }) {
    const [open, setOpen] = useState(false);
    const form = useForm({ session_id: sessions[0]?.id ?? 0, identifiers: '', from_enrolment: false });

    if (!open) {
        return <div><Button variant="outline" size="sm" onClick={() => setOpen(true)}><UserPlus className="size-4" /> Add students</Button></div>;
    }

    return (
        <form className="surface grid gap-3 p-5 md:max-w-2xl" onSubmit={(e) => (e.preventDefault(), form.post(`/exam-board/${examId}/candidates`, { preserveScroll: true, onSuccess: () => form.reset('identifiers') }))}>
            <h3 className="font-medium">Add students</h3>
            <Field label="To the sitting">
                <select className={inputClass} value={form.data.session_id} onChange={(e) => form.setData('session_id', Number(e.target.value))}>
                    {sessions.map((s) => <option key={s.id} value={s.id}>{s.name ?? 'Sitting'} · {formatDateTime(s.starts_at)}</option>)}
                </select>
            </Field>
            <Field label="Matric numbers or emails" hint="One per line, or separated by commas.">
                <textarea className={`${textareaClass} font-mono`} value={form.data.identifiers} onChange={(e) => form.setData('identifiers', e.target.value)} />
            </Field>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.from_enrolment} onChange={(e) => form.setData('from_enrolment', e.target.checked)} /> Also add everyone registered for the course this semester</label>
            <div className="flex gap-2">
                <Button disabled={form.processing}>Add</Button>
                <Button type="button" variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
            </div>
        </form>
    );
}

function UploadQuestions({ exam, allowed }: { exam: Exam; allowed: boolean }) {
    const form = useForm<{ file: File | null; reader: 'science' | 'standard' }>({ file: null, reader: 'science' });

    return (
        <section className="surface grid gap-3 p-5 md:max-w-3xl">
            <div>
                <h2 className="text-base font-semibold">Questions</h2>
                <p className="mt-1 text-sm text-muted-foreground">This exam has {exam.questions} fixed question{exam.questions === 1 ? '' : 's'}. Upload a Word or text file to add more; you will see every question, with its symbols and equations, before anything is saved.</p>
            </div>
            {allowed ? (
                <form className="grid gap-3" onSubmit={(e) => (e.preventDefault(), form.post(`/exam-board/${exam.id}/questions`, { forceFormData: true }))}>
                    <Field label="File" hint="Word (.docx), text (.txt), Markdown or HTML." error={form.errors.file}>
                        <input type="file" accept=".docx,.txt,.md,.html,.htm" className="text-sm" onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)} required />
                    </Field>
                    <Field label="How to read it">
                        <select className={`${inputClass} max-w-md`} value={form.data.reader} onChange={(e) => form.setData('reader', e.target.value as 'science' | 'standard')}>
                            <option value="science">Science & maths — keeps Word equations, Greek letters, symbols, sub- and superscripts</option>
                            <option value="standard">Standard</option>
                        </select>
                    </Field>
                    <div><Button disabled={form.processing || !form.data.file}><Upload className="size-4" /> Upload and preview</Button></div>
                </form>
            ) : (
                <p className="text-sm text-muted-foreground">Questions can be added while the exam is a draft. Ask the exams office to reopen it if you need to change the paper.</p>
            )}
        </section>
    );
}

ExamBoardExam.layout = { breadcrumbs: [{ title: 'Exam board', href: '/exam-board' }, { title: 'Exam', href: '#' }] };
