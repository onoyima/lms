import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Eye, Lock, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { Field, inputClass, PageHeader, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { isOnScreen, paperLook } from '@/lib/papers';

type Props = {
    courses: { id: number; code: string; title: string }[];
    semesters: { id: number; name: string; current: boolean }[];
    venues: { id: number; name: string }[];
    papers: { type: string; label: string }[];
    defaults: { course_id: number | null };
};
type Paper = { type: string; category: string; course_id: number | '';  title: string; duration: number; total_mark: number; starts_at: string; mode: 'hall' | 'remote'; venue_id: number | ''; protection: 'light' | 'standard' | 'strict' };

const onScreen = isOnScreen;
const levels = [
    { value: 'light', icon: Eye, label: 'Light', text: 'Tab and copy checks' },
    { value: 'standard', icon: ShieldCheck, label: 'Standard', text: 'Camera pictures, face checks' },
    { value: 'strict', icon: Lock, label: 'Strict', text: 'Camera, mic and screen recorded' },
] as const;

/** New exam category (Nursing exam, Semester exam, GST…): name it, then add its exams, each with a time. */
const KINDS = [['exam', 'Exam'], ['test', 'Test'], ['quiz', 'Quiz'], ['mock', 'Mock exam'], ['practice', 'Practice'], ['promotion', 'Staff promotion exam']] as const;
export default function NewExam({ courses, semesters, venues, papers, defaults }: Props) {
    const form = useForm({
        title: '',
        code: '',
        course_id: (defaults.course_id ?? '') as number | '',
        semester_id: (semesters.find((s) => s.current)?.id ?? '') as number | '',
        description: '',
        papers: [] as Paper[],
    });
    const add = (type: string) => form.setData('papers', [...form.data.papers, { type, category: 'exam', course_id: '', title: '', duration: paperLook[type].minutes, total_mark: 100, starts_at: '', mode: 'hall', venue_id: '', protection: 'standard' }]);
    const set = (i: number, patch: Partial<Paper>) => form.setData('papers', form.data.papers.map((p, n) => (n === i ? { ...p, ...patch } : p)));
    const err = form.errors as Record<string, string | undefined>;

    return (
        <>
            <Head title="New exam category" />
            <form className="mx-auto flex w-full max-w-5xl flex-col gap-7 p-6 md:p-10" onSubmit={(e) => (e.preventDefault(), form.post('/exam-groups'))}>
                <Link href="/exams" className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground"><ArrowLeft className="size-4" /> All exams</Link>
                <PageHeader title="New exam category" lead="A category groups related exams, such as a Nursing exam, a semester exam, a departmental exam or GST. Add its exams below; each gets its own questions, students, examiners, time and proctoring on the next page." />

                <section className="surface grid gap-4 p-6 md:grid-cols-6">
                    <div className="md:col-span-4">
                        <Field label="Category name" error={form.errors.title}>
                            <input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="Nursing exam, 2026 Semester exam, GST…" required autoFocus />
                        </Field>
                    </div>
                    <div className="md:col-span-2">
                        <Field label="Code (optional)" error={form.errors.code}>
                            <input className={inputClass} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="NUR 401" />
                        </Field>
                    </div>
                    <div className="md:col-span-3">
                        <Field label="Course (optional)" hint="Used by every exam unless an exam has its own course below." error={form.errors.course_id}>
                            <select className={inputClass} value={form.data.course_id} onChange={(e) => form.setData('course_id', e.target.value ? Number(e.target.value) : '')}>
                                <option value="">No single course</option>
                                {courses.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.title}</option>)}
                            </select>
                        </Field>
                    </div>
                    <div className="md:col-span-3">
                        <Field label="Semester" error={form.errors.semester_id}>
                            <select className={inputClass} value={form.data.semester_id} onChange={(e) => form.setData('semester_id', e.target.value ? Number(e.target.value) : '')}>
                                <option value="">—</option>
                                {semesters.map((s) => <option key={s.id} value={s.id}>{s.name}{s.current ? ' (current)' : ''}</option>)}
                            </select>
                        </Field>
                    </div>
                </section>

                <section className="grid gap-3">
                    <div>
                        <h2 className="text-xl font-semibold">Exams in this category</h2>
                        <p className="text-sm text-muted-foreground">Add as many as you need, of any type, more than one of a type too. You can add more later.</p>
                    </div>
                    <div className="grid grid-cols-2 gap-3 md:grid-cols-3">
                        {papers.map((p) => {
                            const L = paperLook[p.type];
                            const count = form.data.papers.filter((x) => x.type === p.type).length;

                            return (
                                <button key={p.type} type="button" onClick={() => add(p.type)}
                                    className="surface group flex items-start gap-3 p-4 text-left transition-[transform,box-shadow] duration-200 hover:-translate-y-0.5 hover:shadow-[0_18px_40px_-24px_var(--brand)]">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-brand-soft text-brand-ink transition-colors group-hover:bg-brand group-hover:text-brand-foreground"><L.icon className="size-5" /></span>
                                    <span className="min-w-0 flex-1">
                                        <span className="flex items-center gap-2 font-semibold">{p.label}{count > 0 && <span className="rounded-full bg-brand px-1.5 text-[11px] leading-5 text-brand-foreground">{count}</span>}</span>
                                        <span className="block text-[13px] text-muted-foreground">{L.hint}</span>
                                    </span>
                                    <Plus className="mt-0.5 size-4 text-muted-foreground transition-transform group-hover:rotate-90" />
                                </button>
                            );
                        })}
                    </div>
                    {err.papers && <p className="text-sm text-status-critical">{err.papers}</p>}
                </section>

                {form.data.papers.length > 0 && (
                    <section className="grid gap-3">
                        {form.data.papers.map((p, i) => {
                            const L = paperLook[p.type];

                            return (
                                <div key={i} className="surface grid gap-4 p-5 [animation:rise-in_.2s_ease-out]">
                                    <div className="flex items-center gap-3">
                                        <span className="grid size-9 place-items-center rounded-xl bg-brand-soft text-brand-ink"><L.icon className="size-[18px]" /></span>
                                        <input className="h-9 flex-1 rounded-lg bg-transparent px-1 font-display text-lg font-semibold outline-none hover:bg-card/70 focus:bg-card" value={p.title} placeholder={papers.find((x) => x.type === p.type)?.label} onChange={(e) => set(i, { title: e.target.value })} aria-label="Exam name" />
                                        <button type="button" aria-label="Remove exam" className="grid size-9 place-items-center rounded-lg text-muted-foreground hover:bg-status-critical/10 hover:text-status-critical" onClick={() => form.setData('papers', form.data.papers.filter((_, n) => n !== i))}><Trash2 className="size-4" /></button>
                                    </div>
                                    <div className="grid gap-4 md:grid-cols-5">
                                        <Field label="Kind">
                                            <select className={inputClass} value={p.category} onChange={(e) => set(i, { category: e.target.value })}>
                                                {KINDS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
                                            </select>
                                        </Field>
                                        <div className="md:col-span-2">
                                            <Field label="Course for this exam" error={err[`papers.${i}.course_id`]}>
                                                <select className={inputClass} value={p.course_id} onChange={(e) => set(i, { course_id: e.target.value ? Number(e.target.value) : '' })}>
                                                    <option value="">{form.data.course_id ? 'Same as the category' : 'No single course'}</option>
                                                    {courses.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.title}</option>)}
                                                </select>
                                            </Field>
                                        </div>
                                        <Field label="Minutes" error={err[`papers.${i}.duration`]}>
                                            <input type="number" min={1} max={600} className={inputClass} value={p.duration} onChange={(e) => set(i, { duration: Number(e.target.value) })} />
                                        </Field>
                                        <Field label="Total marks" error={err[`papers.${i}.total_mark`]}>
                                            <input type="number" min={1} className={inputClass} value={p.total_mark} onChange={(e) => set(i, { total_mark: Number(e.target.value) })} />
                                        </Field>
                                        <div className="md:col-span-2">
                                            <Field label="Date and time (optional now)" error={err[`papers.${i}.starts_at`]}>
                                                <input type="datetime-local" className={inputClass} value={p.starts_at} onChange={(e) => set(i, { starts_at: e.target.value })} />
                                            </Field>
                                        </div>
                                        <Field label="Where">
                                            <select className={inputClass} value={p.mode === 'remote' ? 'remote' : String(p.venue_id || 'hall')} onChange={(e) => set(i, e.target.value === 'remote' ? { mode: 'remote', venue_id: '' } : { mode: 'hall', venue_id: e.target.value === 'hall' ? '' : Number(e.target.value) })}>
                                                <option value="hall">In a hall</option>
                                                {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                                                {onScreen(p.type) && <option value="remote">Online (from anywhere)</option>}
                                            </select>
                                        </Field>
                                    </div>
                                    {onScreen(p.type) && (
                                        <div className="flex flex-wrap gap-2" role="radiogroup" aria-label="Monitoring">
                                            {levels.map((l) => (
                                                <button key={l.value} type="button" role="radio" aria-checked={p.protection === l.value} onClick={() => set(i, { protection: l.value })}
                                                    className={`flex items-center gap-2 rounded-xl border px-3 py-2 text-sm transition-colors ${p.protection === l.value ? 'border-brand bg-brand-soft text-foreground' : 'border-border bg-card/70 text-muted-foreground hover:text-foreground'}`}>
                                                    <l.icon className="size-4" /> <b className="font-semibold">{l.label}</b> <span className="hidden sm:inline">· {l.text}</span>
                                                </button>
                                            ))}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </section>
                )}

                <section className="surface p-6">
                    <Field label="Notes for staff (optional)" error={form.errors.description}>
                        <textarea className={textareaClass} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} placeholder="e.g. Final professional examination, second sitting" />
                    </Field>
                </section>

                <div className="flex flex-wrap items-center gap-4">
                    <Button size="lg" disabled={form.processing || form.data.papers.length === 0}><Plus className="size-4" /> Create category</Button>
                    <span className="text-sm text-muted-foreground">{form.data.papers.length === 0 ? 'Add at least one exam.' : `${form.data.papers.length} ${form.data.papers.length === 1 ? 'exam' : 'exams'}. Next: questions, students and examiners for each.`}</span>
                </div>
            </form>
        </>
    );
}
