import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Check, Download, FileSpreadsheet, FileText, Plus, Send, Trash2, UsersRound, X } from 'lucide-react';
import { useState } from 'react';
import { Field, formatDateTime, inputClass, PageHeader, Status, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { isOnScreen, paperLook } from '@/lib/papers';

type Step = { key: 'paper' | 'sittings' | 'students' | 'examiners' | 'rules' | 'approval'; label: string; done: boolean; detail: string; optional?: boolean };
type Paper = {
    id: number; type: string; label: string; kind_label: string; category: string; course: string | null; results: number; title: string; status: string; duration: number; total_mark: number; panel: boolean;
    steps: Step[]; ready: boolean; next: Step['key'] | null; sittings: { id: number; starts_at: string; ends_at: string }[];
    progress: { writing: number; submitted: number; candidates: number };
};
type Props = {
    group: { id: number; title: string; code: string | null; description: string | null; course: string | null; course_id: number | null; semester: string | null };
    papers: Paper[];
    paperTypes: { type: string; label: string }[];
    venues: { id: number; name: string }[];
    programmes: { id: number; name: string }[];
    levels: number[];
    students: { count: number; list: { id: number; name: string; identifier: string | null }[] };
    courses: { id: number; code: string; title: string }[];
    canExport: boolean;
};

const stepAction: Record<Step['key'], string> = {
    paper: 'Add questions', sittings: 'Set the date', students: 'Add students', examiners: 'Assign examiners', rules: 'Check the rules', approval: 'Send for approval',
};

/** Where each step is done: the paper's own page, on the right tab (or its sitting, for students). */
function stepHref(p: Paper, key: Step['key']): string {
    if (key === 'students' && p.sittings[0]) {
        return `/sessions/${p.sittings[0].id}`;
    }

    const tab = { paper: 'paper', sittings: 'sittings', students: 'sittings', examiners: 'examiners', rules: 'rules', approval: 'overview' }[key];

    return `/exams/${p.id}?tab=${tab}`;
}

function StudentsPanel({ group, papers, programmes, levels, only, onClose }: Props & { only: number | null; onClose: () => void }) {
    const form = useForm({
        target: only ? 'exams' : 'category',
        papers: only ? [only] : papers.filter((p) => p.sittings.length).map((p) => p.id),
        source: group.course_id || papers.some((p) => p.course) ? 'course' : 'programme', programme_id: '' as number | '', level: '' as number | '', identifiers: '',
    });
    const whole = form.data.target === 'category';
    const err = form.errors as Record<string, string | undefined>;
    const sources = [
        (group.course_id || papers.some((p) => p.course)) && { value: 'course', label: 'Course class lists', text: whole ? "Each exam's own course" : group.course ?? "The exam's course" },
        { value: 'programme', label: 'A programme and level', text: 'From the student records' },
        { value: 'list', label: 'Matric numbers or e-mails', text: 'Paste a list' },
    ].filter(Boolean) as { value: string; label: string; text: string }[];

    return (
        <form className="surface grid gap-5 p-6 [animation:rise-in_.18s_ease-out]" onSubmit={(e) => (e.preventDefault(), form.post(`/exam-groups/${group.id}/students`, { preserveScroll: true, onSuccess: onClose }))}>
            <div className="flex items-start justify-between gap-4">
                <div>
                    <h2 className="text-xl font-semibold">Add students</h2>
                    <p className="text-sm text-muted-foreground">To the whole category, or to chosen exams only. Anyone already added is kept once.</p>
                </div>
                <button type="button" aria-label="Close" onClick={onClose} className="grid size-9 place-items-center rounded-lg text-muted-foreground hover:bg-muted"><X className="size-4" /></button>
            </div>
            <div className="grid gap-3 md:grid-cols-2" role="radiogroup" aria-label="Who the students are for">
                {[
                    { value: 'category', label: 'The whole category', text: 'They sit every exam in it, including exams added later.' },
                    { value: 'exams', label: 'Chosen exams only', text: 'Pick the exams below.' },
                ].map((t) => (
                    <label key={t.value} className={`flex cursor-pointer gap-3 rounded-xl border p-3.5 text-sm transition-colors ${form.data.target === t.value ? 'border-brand bg-brand-soft' : 'border-border bg-card/70 hover:border-foreground/20'}`}>
                        <input type="radio" name="target" className="mt-1 accent-[var(--brand)]" checked={form.data.target === t.value} onChange={() => form.setData('target', t.value)} />
                        <span><b className="block font-semibold">{t.label}</b><span className="text-muted-foreground">{t.text}</span></span>
                    </label>
                ))}
            </div>
            {!whole && <div className="flex flex-wrap gap-2">
                {papers.map((p) => {
                    const on = form.data.papers.includes(p.id);
                    const L = paperLook[p.type];

                    return (
                        <button key={p.id} type="button" disabled={!p.sittings.length} title={p.sittings.length ? undefined : 'Set a date for this paper first'}
                            onClick={() => form.setData('papers', on ? form.data.papers.filter((x) => x !== p.id) : [...form.data.papers, p.id])}
                            className={`flex items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors disabled:opacity-45 ${on ? 'border-brand bg-brand-soft font-semibold' : 'border-border bg-card/70 text-muted-foreground hover:text-foreground'}`}>
                            {on ? <Check className="size-4 text-brand-ink" /> : <L.icon className="size-4" />}{p.label}
                        </button>
                    );
                })}
            </div>}
            {err.papers && <p className="text-sm text-status-critical">{err.papers}</p>}
            <div className="grid gap-3 md:grid-cols-3">
                {sources.map((s) => (
                    <label key={s.value} className={`flex cursor-pointer gap-3 rounded-xl border p-3.5 text-sm transition-colors ${form.data.source === s.value ? 'border-brand bg-brand-soft' : 'border-border bg-card/70 hover:border-foreground/20'}`}>
                        <input type="radio" name="source" className="mt-1 accent-[var(--brand)]" checked={form.data.source === s.value} onChange={() => form.setData('source', s.value)} />
                        <span><b className="block font-semibold">{s.label}</b><span className="text-muted-foreground">{s.text}</span></span>
                    </label>
                ))}
            </div>
            {form.data.source === 'programme' && (
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="md:col-span-2">
                        <Field label="Programme" error={err.programme_id}>
                            <select className={inputClass} value={form.data.programme_id} onChange={(e) => form.setData('programme_id', e.target.value ? Number(e.target.value) : '')} required>
                                <option value="">Choose…</option>
                                {programmes.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}
                            </select>
                        </Field>
                    </div>
                    <Field label="Level" error={err.level}>
                        <select className={inputClass} value={form.data.level} onChange={(e) => form.setData('level', e.target.value ? Number(e.target.value) : '')}>
                            <option value="">All levels</option>
                            {levels.map((l) => <option key={l} value={l}>{l} level</option>)}
                        </select>
                    </Field>
                </div>
            )}
            {form.data.source === 'list' && (
                <Field label="Matric numbers or e-mails" hint="One per line, or separated by commas." error={err.identifiers}>
                    <textarea className={textareaClass} value={form.data.identifiers} onChange={(e) => form.setData('identifiers', e.target.value)} placeholder={'VUG/NUR/21/0012\nVUG/NUR/21/0013'} required />
                </Field>
            )}
            <div><Button disabled={form.processing || (!whole && form.data.papers.length === 0)}><UsersRound className="size-4" /> {whole ? 'Add to the category' : 'Add students'}</Button></div>
        </form>
    );
}

function AddPaper({ group, paperTypes, venues, courses, onClose }: Props & { onClose: () => void }) {
    const form = useForm({ type: 'cbt', category: 'exam', course_id: '' as number | '', title: '', duration: 60, total_mark: 100, starts_at: '', mode: 'hall' as 'hall' | 'remote', venue_id: '' as number | '', protection: 'standard' });

    return (
        <form className="surface grid gap-4 p-6 md:grid-cols-6 [animation:rise-in_.18s_ease-out]" onSubmit={(e) => (e.preventDefault(), form.post(`/exam-groups/${group.id}/papers`, { preserveScroll: true, onSuccess: onClose }))}>
            <div className="md:col-span-2">
                <Field label="Type" error={form.errors.type}>
                    <select className={inputClass} value={form.data.type} onChange={(e) => form.setData((d) => ({ ...d, type: e.target.value, duration: paperLook[e.target.value].minutes }))}>
                        {paperTypes.map((t) => <option key={t.type} value={t.type}>{t.label}</option>)}
                    </select>
                </Field>
            </div>
            <div className="md:col-span-2">
                <Field label="Name (optional)" error={form.errors.title}><input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder={paperTypes.find((t) => t.type === form.data.type)?.label} /></Field>
            </div>
            <div className="md:col-span-2">
                <Field label="Kind">
                    <select className={inputClass} value={form.data.category} onChange={(e) => form.setData('category', e.target.value)}>
                        {[['exam', 'Exam'], ['test', 'Test'], ['quiz', 'Quiz'], ['mock', 'Mock exam'], ['practice', 'Practice']].map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                    </select>
                </Field>
            </div>
            <div className="md:col-span-3">
                <Field label="Course for this exam" error={form.errors.course_id}>
                    <select className={inputClass} value={form.data.course_id} onChange={(e) => form.setData('course_id', e.target.value ? Number(e.target.value) : '')}>
                        <option value="">{group.course ? `Same as the category (${group.course})` : 'No single course'}</option>
                        {courses.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.title}</option>)}
                    </select>
                </Field>
            </div>
            <Field label="Minutes" error={form.errors.duration}><input type="number" className={inputClass} value={form.data.duration} onChange={(e) => form.setData('duration', Number(e.target.value))} /></Field>
            <Field label="Total marks" error={form.errors.total_mark}><input type="number" className={inputClass} value={form.data.total_mark} onChange={(e) => form.setData('total_mark', Number(e.target.value))} /></Field>
            <div className="md:col-span-2">
                <Field label="Date and time (optional)" error={form.errors.starts_at}><input type="datetime-local" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></Field>
            </div>
            <div className="md:col-span-3">
                <Field label="Where">
                    <select className={inputClass} value={form.data.mode === 'remote' ? 'remote' : String(form.data.venue_id || 'hall')} onChange={(e) => form.setData((d) => (e.target.value === 'remote' ? { ...d, mode: 'remote', venue_id: '' } : { ...d, mode: 'hall', venue_id: e.target.value === 'hall' ? '' : Number(e.target.value) }))}>
                        <option value="hall">In a hall</option>
                        {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                        {isOnScreen(form.data.type) && <option value="remote">Online (from anywhere)</option>}
                    </select>
                </Field>
            </div>
            <div className="flex items-end gap-2 md:col-span-3">
                <Button disabled={form.processing}><Plus className="size-4" /> Add exam</Button>
                <Button type="button" variant="outline" onClick={onClose}>Cancel</Button>
            </div>
        </form>
    );
}

export default function ExamGroupPage(props: Props) {
    const { group, papers } = props;
    const { flash } = usePage<{ flash?: { success?: string; error?: string } }>().props;
    const [panel, setPanel] = useState<'students' | 'paper' | null>(null);
    const [only, setOnly] = useState<number | null>(null);
    const openStudents = (id: number | null) => (setOnly(id), setPanel('students'));
    const anyResults = papers.some((p) => p.results > 0);
    const ready = papers.filter((p) => p.ready).length;
    const drafts = papers.filter((p) => ['draft', 'returned'].includes(p.status)).length;
    const students = Math.max(0, ...papers.map((p) => p.progress.candidates));

    return (
        <>
            <Head title={group.title} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 md:p-10">
                <Link href="/exams" className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> All exams</Link>
                <PageHeader
                    title={group.title}
                    eyebrow={[group.code, group.course, group.semester].filter(Boolean).join(' · ') || undefined}
                    lead={`Exam category · ${papers.length} ${papers.length === 1 ? 'exam' : 'exams'} · ${ready} ready · ${Math.max(students, props.students.count)} ${Math.max(students, props.students.count) === 1 ? 'student' : 'students'}`}
                    actions={
                        <>
                            <Button variant="outline" onClick={() => setPanel(panel === 'paper' ? null : 'paper')}><Plus className="size-4" /> Add exam</Button>
                            <Button variant="outline" onClick={() => (panel === 'students' && only === null ? setPanel(null) : openStudents(null))}><UsersRound className="size-4" /> Add students</Button>
                            {drafts > 0 && <Button onClick={() => router.post(`/exam-groups/${group.id}/submit`, {}, { preserveScroll: true })}><Send className="size-4" /> Send exams for approval</Button>}
                        </>
                    }
                />
                {flash?.success && <p role="status" className="rounded-xl bg-status-good/10 px-4 py-2.5 text-sm text-status-good">{flash.success}</p>}
                {flash?.error && <p role="alert" className="rounded-xl bg-status-critical/10 px-4 py-2.5 text-sm text-status-critical">{flash.error}</p>}
                {panel === 'students' && <StudentsPanel key={only ?? 'all'} {...props} only={only} onClose={() => setPanel(null)} />}
                {panel === 'paper' && <AddPaper {...props} onClose={() => setPanel(null)} />}

                <div className="grid gap-4">
                    {papers.map((p) => {
                        const L = paperLook[p.type] ?? paperLook.cbt;
                        const done = p.steps.filter((s) => s.done).length;
                        const live = p.progress.writing > 0;
                        const nextStep = p.steps.find((s) => s.key === p.next);

                        return (
                            <article key={p.id} className="surface overflow-hidden">
                                <div className="flex flex-wrap items-center gap-4 p-5 pb-4">
                                    <span className={`grid size-12 shrink-0 place-items-center rounded-2xl ${p.ready ? 'bg-status-good/12 text-status-good' : 'bg-brand-soft text-brand-ink'}`}><L.icon className="size-6" /></span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <h2 className="text-xl font-semibold">{p.label}</h2>
                                            {p.category !== 'exam' && <span className="rounded-full bg-foreground/[.06] px-2 py-0.5 text-xs font-medium capitalize">{p.category}</span>}
                                            <Status value={live ? 'writing' : p.status} />
                                        </div>
                                        <p className="text-sm text-muted-foreground">
                                            {p.kind_label !== p.label ? `${p.kind_label} · ` : ''}{p.course ? `${p.course} · ` : ''}{p.duration} minutes · {p.total_mark} marks{p.sittings[0] ? ` · ${formatDateTime(p.sittings[0].starts_at)}` : ''}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <Button variant="outline" size="sm" onClick={() => openStudents(p.id)}><UsersRound className="size-4" /> Students</Button>
                                        {props.canExport && p.results > 0 && (
                                            <span className="flex items-center gap-1" aria-label={`Download ${p.label} results`}>
                                                {(['pdf', 'xlsx', 'csv', 'json'] as const).map((f) => (
                                                    <a key={f} href={`/results/${p.id}/export?format=${f}`} className="rounded-lg border border-border px-2 py-1 text-xs font-semibold uppercase hover:border-brand">{f === 'xlsx' ? 'Excel' : f}</a>
                                                ))}
                                            </span>
                                        )}
                                        {nextStep ? (
                                            <Button asChild><Link href={stepHref(p, nextStep.key)}>Next: {stepAction[nextStep.key].toLowerCase()} <ArrowRight className="size-4" /></Link></Button>
                                        ) : (
                                            <Button asChild variant="outline"><Link href={`/exams/${p.id}`}>Open paper <ArrowRight className="size-4" /></Link></Button>
                                        )}
                                    </div>
                                </div>
                                <div className="h-1 bg-foreground/[.05]"><div className="h-full bg-[linear-gradient(90deg,var(--brand),var(--glow))] transition-[width] duration-500" style={{ width: `${(done / p.steps.length) * 100}%` }} /></div>
                                <ol className="grid divide-y divide-border sm:grid-cols-2 sm:divide-y-0 lg:grid-cols-6">
                                    {p.steps.map((s, i) => (
                                        <li key={s.key}>
                                            <Link href={stepHref(p, s.key)} className={`group flex h-full gap-3 p-4 transition-colors hover:bg-brand-soft/60 ${s.key === p.next ? 'bg-brand-soft/40' : ''}`}>
                                                <span className={`grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-bold ${s.done ? 'bg-status-good text-white' : s.key === p.next ? 'bg-brand text-brand-foreground' : 'bg-foreground/[.07] text-muted-foreground'}`}>
                                                    {s.done ? <Check className="size-3.5" /> : i + 1}
                                                </span>
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-semibold">{s.label}</span>
                                                    <span className="line-clamp-2 block text-[12.5px] text-muted-foreground">{s.detail}</span>
                                                </span>
                                            </Link>
                                        </li>
                                    ))}
                                </ol>
                                {(live || p.progress.submitted > 0) && (
                                    <div className="flex items-center gap-3 border-t border-border px-5 py-3 text-sm">
                                        {live && <span className="live-dot" />}
                                        <span>{p.progress.writing} writing · {p.progress.submitted} submitted of {p.progress.candidates}</span>
                                        <div className="flex h-1.5 flex-1 overflow-hidden rounded-full bg-foreground/[.06]">
                                            <i className="bg-status-info" style={{ width: `${(p.progress.submitted / Math.max(1, p.progress.candidates)) * 100}%` }} />
                                            <i className="shimmer bg-[var(--live)]" style={{ width: `${(p.progress.writing / Math.max(1, p.progress.candidates)) * 100}%` }} />
                                        </div>
                                        <Link href={p.sittings[0] ? `/proctor/sessions/${p.sittings[0].id}` : '/proctor'} className="font-medium text-brand">Watch live</Link>
                                    </div>
                                )}
                            </article>
                        );
                    })}
                    {papers.length === 0 && (
                        <div className="surface grid place-items-center gap-3 p-10 text-center">
                            <p className="text-muted-foreground">This category has no exams yet.</p>
                            <Button onClick={() => setPanel('paper')}><Plus className="size-4" /> Add the first exam</Button>
                        </div>
                    )}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <section className="surface grid content-start gap-3 p-6">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-lg font-semibold">Combined results</h2>
                                <p className="text-sm text-muted-foreground">One row per student: each exam's score, the total and the percentage. ABS means assigned but did not sit.</p>
                            </div>
                            <Download className="size-5 shrink-0 text-muted-foreground" />
                        </div>
                        {props.canExport ? (
                            <div className="flex flex-wrap gap-2">
                                <Button asChild variant={anyResults ? 'default' : 'outline'}><a href={`/exam-groups/${group.id}/report.pdf`}><FileText className="size-4" /> PDF</a></Button>
                                <Button asChild variant="outline"><a href={`/exam-groups/${group.id}/report.xlsx`}><FileSpreadsheet className="size-4" /> Excel</a></Button>
                                <Button asChild variant="outline"><a href={`/exam-groups/${group.id}/report.csv`}>CSV</a></Button>
                                <Button asChild variant="outline"><a href={`/exam-groups/${group.id}/report.json`}>JSON</a></Button>
                            </div>
                        ) : <p className="text-sm text-muted-foreground">Results can be downloaded by staff who approve or publish results.</p>}
                        <p className="text-xs text-muted-foreground">Each exam's own results are on its card above (PDF, Excel, CSV) once marking has started.</p>
                    </section>
                    <section className="surface grid content-start gap-3 p-6">
                        <div>
                            <h2 className="text-lg font-semibold">Students of the whole category</h2>
                            <p className="text-sm text-muted-foreground">{props.students.count ? `${props.students.count} ${props.students.count === 1 ? 'student sits' : 'students sit'} every exam here, including exams added later.` : 'None yet. Students added to single exams are on those exams.'}</p>
                        </div>
                        {props.students.list.length > 0 && (
                            <ul className="max-h-64 divide-y divide-border overflow-y-auto rounded-xl border border-border text-sm">
                                {props.students.list.map((u) => (
                                    <li key={u.id} className="flex items-center justify-between gap-3 px-3 py-2">
                                        <span><b className="font-medium">{u.name}</b>{u.identifier ? <span className="ml-2 text-muted-foreground">{u.identifier}</span> : null}</span>
                                        <button type="button" aria-label={`Remove ${u.name} from the category`} className="text-muted-foreground hover:text-status-critical"
                                            onClick={() => confirm(`Remove ${u.name} from the category? Exams already given stay; remove them there if needed.`) && router.delete(`/exam-groups/${group.id}/students/${u.id}`, { preserveScroll: true })}>
                                            <Trash2 className="size-4" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                        <div><Button variant="outline" size="sm" onClick={() => openStudents(null)}><UsersRound className="size-4" /> Add students to the category</Button></div>
                    </section>
                </div>
            </div>
        </>
    );
}
