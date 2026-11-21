import { Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, Database, Download, Eraser, Pencil, Plus, Trash2, Upload } from 'lucide-react';
import { useState } from 'react';
import { QuestionUpload, TemplateLinks } from '@/components/exam/question-upload';
import { Field, inputClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

export type Bank = { id: number; name: string; topic: string | null; description: string | null; questions: number; scope: 'exam' | 'group' | 'course'; scope_label: string; types: Record<string, number> };
type ExamLite = { id: number; title: string; exam_group_id?: number | null; course_id: number | null; can_edit: boolean };
type SectionLite = { id: number; title: string };
type BankQuestion = { id: number; type: string; stem_html: string; mark: number; topic: string | null; difficulty: string | null; status: string };

const TYPE_SHORT: Record<string, string> = {
    single_choice: 'single', multiple_choice: 'multiple', true_false: 'T/F', multi_true_false: 'multi T/F', matching: 'matching', ordering: 'order',
    fill_blank: 'blanks', numeric: 'numeric', short_answer: 'short', essay: 'essay', file_upload: 'upload',
};

/** The exam's question banks: make one per topic, upload into it, download it, empty it. */
export function BanksPanel({ exam, banks, sections }: { exam: ExamLite; banks: Bank[]; sections: SectionLite[] }) {
    const [uploading, setUploading] = useState<number | null>(null);
    const [toSection, setToSection] = useState<string>('');
    const form = useForm({ name: '', topic: '', scope: 'exam', exam_id: exam.id });

    return (
        <div className="grid gap-6">
            <section className="surface grid gap-4 p-6">
                <div>
                    <h3 className="text-lg font-medium">Question banks for this paper</h3>
                    <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                        A paper can have several banks, for example one per topic. Upload questions into each bank from a text file, a spreadsheet (CSV), Word or PDF, then on the
                        Paper tab choose whether students get every question, questions you pick, or a random draw with at least a set number from each bank.
                    </p>
                </div>
                <div>
                    <p className="eyebrow mb-2">Templates</p>
                    <TemplateLinks />
                </div>
                {exam.can_edit && (
                    <form className="grid gap-3 rounded-xl border border-border p-4 md:grid-cols-[1.3fr_1fr_1fr_auto] md:items-end" onSubmit={(e) => (e.preventDefault(), form.post('/question-banks', { preserveScroll: true, onSuccess: () => form.reset('name', 'topic') }))}>
                        <Field label="New bank" error={form.errors.name}>
                            <input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="e.g. Topic 3: Injections" required />
                        </Field>
                        <Field label="Topic (optional)">
                            <input className={inputClass} value={form.data.topic} onChange={(e) => form.setData('topic', e.target.value)} placeholder="Given to its questions" />
                        </Field>
                        <Field label="Belongs to">
                            <select className={inputClass} value={form.data.scope} onChange={(e) => form.setData('scope', e.target.value)}>
                                <option value="exam">This paper only</option>
                                {exam.exam_group_id && <option value="group">Every paper in this exam category</option>}
                                {exam.course_id && <option value="course">The course (other exams can use it)</option>}
                            </select>
                        </Field>
                        <Button disabled={form.processing}>
                            <Plus className="size-4" /> Make bank
                        </Button>
                    </form>
                )}
            </section>

            {banks.length === 0 && (
                <div className="surface flex items-center gap-3 p-6 text-sm text-muted-foreground">
                    <Database className="size-5 text-brand" /> No banks yet. Make one above, or add questions straight to the paper on the Paper tab.
                </div>
            )}

            <div className="grid gap-4 lg:grid-cols-2">
                {banks.map((b) => (
                    <section key={b.id} className="surface grid content-start gap-3 p-5">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="min-w-0">
                                <h4 className="truncate text-base font-semibold">{b.name}</h4>
                                <p className="text-sm text-muted-foreground">
                                    {b.questions} question{b.questions === 1 ? '' : 's'} · {b.scope_label}
                                    {b.topic && ` · topic ${b.topic}`}
                                </p>
                            </div>
                            <span className="rounded-full bg-brand-soft px-2.5 py-0.5 text-xs font-semibold text-brand-ink">{b.questions}</span>
                        </div>
                        {Object.keys(b.types).length > 0 && (
                            <div className="flex flex-wrap gap-1.5">
                                {Object.entries(b.types).map(([t, n]) => (
                                    <span key={t} className="rounded-md border border-border px-2 py-0.5 text-xs text-muted-foreground">
                                        {n} {TYPE_SHORT[t] ?? t}
                                    </span>
                                ))}
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button size="sm" onClick={() => setUploading(uploading === b.id ? null : b.id)}>
                                <Upload className="size-4" /> Upload questions
                            </Button>
                            <Button size="sm" variant="outline" asChild>
                                <Link href={`/question-bank?bank_id=${b.id}`}>See questions</Link>
                            </Button>
                            <Button size="sm" variant="outline" asChild>
                                <a href={`/question-banks/${b.id}/export.txt`}>
                                    <Download className="size-4" /> .txt
                                </a>
                            </Button>
                            <Button size="sm" variant="outline" asChild>
                                <a href={`/question-banks/${b.id}/export.csv`}>
                                    <Download className="size-4" /> .csv
                                </a>
                            </Button>
                            <Button size="sm" variant="ghost" aria-label={`Rename ${b.name}`} onClick={() => {
                                const name = prompt('Bank name', b.name);

                                if (name && name !== b.name) {
router.put(`/question-banks/${b.id}`, { name, topic: b.topic, description: b.description }, { preserveScroll: true });
}
                            }}>
                                <Pencil className="size-4" />
                            </Button>
                            {b.questions > 0 && (
                                <Button size="sm" variant="ghost" onClick={() => confirm(`Remove all ${b.questions} questions from "${b.name}"? Papers already written keep their copies.`) && router.post(`/question-banks/${b.id}/clear`, {}, { preserveScroll: true })}>
                                    <Eraser className="size-4" /> Empty
                                </Button>
                            )}
                            <Button size="sm" variant="ghost" aria-label={`Delete ${b.name}`} onClick={() => confirm(b.questions ? `Delete "${b.name}" and its ${b.questions} questions?` : `Delete "${b.name}"?`) && router.delete(`/question-banks/${b.id}`, { data: { with_questions: b.questions > 0 }, preserveScroll: true })}>
                                <Trash2 className="size-4" />
                            </Button>
                        </div>
                        {uploading === b.id && (
                            <div className="grid gap-2">
                                {exam.can_edit && sections.length > 0 && (
                                    <label className="flex flex-wrap items-center gap-2 text-sm">
                                        <span>Also put them on the paper:</span>
                                        <select className={`${inputClass} h-9 !w-auto`} value={toSection} onChange={(e) => setToSection(e.target.value)}>
                                            <option value="">No, keep them in the bank</option>
                                            {sections.map((s) => (
                                                <option key={s.id} value={s.id}>
                                                    Yes, in {s.title}
                                                </option>
                                            ))}
                                        </select>
                                    </label>
                                )}
                                <QuestionUpload bankId={b.id} sectionId={toSection ? Number(toSection) : undefined} onCancel={() => setUploading(null)} />
                            </div>
                        )}
                    </section>
                ))}
            </div>

            <section className="surface flex flex-wrap items-center gap-2 p-5">
                <span className="mr-auto text-sm">Everything on this paper and in its own banks:</span>
                <Button size="sm" variant="outline" asChild>
                    <a href={`/exams/${exam.id}/questions.txt`}>
                        <Download className="size-4" /> Download .txt
                    </a>
                </Button>
                <Button size="sm" variant="outline" asChild>
                    <a href={`/exams/${exam.id}/questions.csv`}>
                        <Download className="size-4" /> Download .csv
                    </a>
                </Button>
                {exam.can_edit && (
                    <Button size="sm" variant="ghost" onClick={() => confirm('Take every question off the paper? They stay in their banks.') && router.post(`/exams/${exam.id}/clear-paper`, {}, { preserveScroll: true })}>
                        <Eraser className="size-4" /> Clear the paper
                    </Button>
                )}
            </section>
        </div>
    );
}

/** Problems that would stop candidates starting (a bank too small for its rule), shown before publishing. */
export function PaperProblems({ problems }: { problems: string[] }) {
    if (!problems.length) {
return null;
}

    return (
        <div className="flex gap-3 rounded-xl border border-status-warning/40 bg-status-warning/10 p-4 text-sm" role="alert">
            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warning" />
            <div>
                <p className="font-medium">Fix these before submitting the paper:</p>
                <ul className="mt-1 list-disc pl-5">
                    {problems.map((p) => (
                        <li key={p}>{p}</li>
                    ))}
                </ul>
            </div>
        </div>
    );
}

/** Choose banks (one or several) as chips. */
export function BankChips({ banks, value, onChange, disabled }: { banks: Bank[]; value: number[]; onChange: (v: number[]) => void; disabled?: boolean }) {
    return (
        <div className="flex flex-wrap gap-1.5">
            {banks.map((b) => {
                const on = value.includes(b.id);

                return (
                    <button key={b.id} type="button" disabled={disabled} aria-pressed={on} onClick={() => onChange(on ? value.filter((x) => x !== b.id) : [...value, b.id])}
                        className={`rounded-full border px-3 py-1 text-xs font-semibold transition ${on ? 'border-brand bg-brand text-brand-foreground shadow-[0_4px_12px_-6px_var(--brand)]' : 'border-border bg-card text-foreground hover:border-brand/60'}`}>
                        {b.name} <span className={on ? 'opacity-80' : 'text-muted-foreground'}>({b.questions})</span>
                    </button>
                );
            })}
        </div>
    );
}

/** Pick questions from one or more banks onto the paper, or put whole banks on it. */
export function BankPicker({ examId, sectionId, banks, onDone }: { examId: number; sectionId: number; banks: Bank[]; onDone: () => void }) {
    const [chosenBanks, setChosenBanks] = useState<number[]>(banks.length === 1 ? [banks[0].id] : []);
    const [list, setList] = useState<BankQuestion[]>([]);
    const [chosen, setChosen] = useState<number[]>([]);
    const [loading, setLoading] = useState(false);

    const load = async (ids: number[]) => {
        setChosenBanks(ids);
        setLoading(true);
        const all = await Promise.all(ids.map((id) => fetch(`/question-banks/${id}/questions`, { headers: { Accept: 'application/json' } }).then((r) => r.json() as Promise<BankQuestion[]>)));
        setList(all.flat());
        setLoading(false);
    };

    return (
        <div className="grid gap-3">
            <BankChips banks={banks} value={chosenBanks} onChange={(ids) => void load(ids)} />
            {chosenBanks.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" onClick={() => router.post(`/exams/${examId}/sections/${sectionId}/banks`, { bank_ids: chosenBanks }, { preserveScroll: true, onSuccess: onDone })}>
                        Put every question from {chosenBanks.length === 1 ? 'this bank' : `these ${chosenBanks.length} banks`} on the paper
                    </Button>
                    {list.length > 0 && (
                        <Button size="sm" variant="ghost" onClick={() => setChosen(chosen.length === list.length ? [] : list.map((q) => q.id))}>
                            {chosen.length === list.length ? 'Unselect all' : 'Select all'}
                        </Button>
                    )}
                </div>
            )}
            {loading && <p className="text-sm text-muted-foreground">Loading questions…</p>}
            <ul className="max-h-96 space-y-2 overflow-y-auto">
                {list.map((r) => (
                    <li key={r.id}>
                        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-border bg-card p-2 text-sm hover:border-brand/50">
                            <input type="checkbox" className="mt-1" checked={chosen.includes(r.id)} onChange={(e) => setChosen(e.target.checked ? [...chosen, r.id] : chosen.filter((x) => x !== r.id))} />
                            <RichContent html={r.stem_html} className="flex-1" />
                            <span className="text-xs whitespace-nowrap text-muted-foreground">
                                {TYPE_SHORT[r.type] ?? r.type} · {r.mark} mk
                            </span>
                        </label>
                    </li>
                ))}
            </ul>
            {list.length > 0 && (
                <Button className="w-fit" disabled={!chosen.length} onClick={() => router.post(`/exams/${examId}/sections/${sectionId}/questions`, { question_ids: chosen }, { preserveScroll: true, onSuccess: onDone })}>
                    Add {chosen.length} chosen question{chosen.length === 1 ? '' : 's'}
                </Button>
            )}
        </div>
    );
}
