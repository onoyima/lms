import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { BankChips, BankPicker, BanksPanel, PaperProblems  } from '@/components/exam/banks';
import type {Bank} from '@/components/exam/banks';
import type { PracticalCriterion, PracticalSetup } from '@/components/exam/practical-editor';
import { PracticalEditor } from '@/components/exam/practical-editor';
import { QuestionReportsPanel  } from '@/components/exam/question-reports';
import type {ReportGroup} from '@/components/exam/question-reports';
import { QuestionUpload } from '@/components/exam/question-upload';
import { Field, formatDateTime, inputClass, PageHeader, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Q = { id: number; type: string; stem_html: string; mark: number; topic: string | null; status: string; difficulty?: string | null };
type Rule = { topic?: string | null; difficulty?: string | null; type?: string | null; count: number; mark?: number | null; draw?: number; bank_ids?: number[]; min_each?: number | null };
type Section = { id: number; title: string; instructions: string | null; blueprint: Rule[]; answer_any: number | null; questions: Q[] };
type Criterion = PracticalCriterion;
type Sitting = { id: number; name: string | null; mode: string; status: string; venue: string | null; starts_at: string; ends_at: string; candidates: number; attempts: number; is_practice: boolean };
type ExamData = {
    id: number; title: string; code: string | null; type: string; category: string; kind: string; parent_exam_id: number | null; exam_group_id?: number | null; status: string; instructions: string | null;
    total_mark: string | null; pass_mark: string | null; duration_minutes: number; max_attempts: number; score_policy: string; marking_policy: string;
    version: number; course_id: number | null; semester_id: number | null; channels: string[]; grading_scales?: string[]; certificate_template_id?: number | null; certificate_templates?: { id: number; name: string; kind: string; min_percent: number }[]; pre_check_options?: { key: string; label: string; hint: string }[];
    settings: Record<string, unknown> & { seb?: { config_key?: string; browser_exam_keys?: string[]; quit_password?: string; has_settings_password?: boolean } };
    proctoring: Record<string, unknown>; is_panel: boolean; can_edit: boolean; needs_approval?: boolean;
};

const tabs = ['Overview', 'Paper', 'Question banks', 'Rules & proctoring', 'Sittings', 'Examiners', 'Question reports', 'Integrity review', 'History'] as const;

type Anomaly = { id: number; attempt_id: number; score: number; reasons: { feature: string; z: number; value: number }[]; review: string; candidate: { name: string; identifier: string | null } | null; session_id: number | null };
const FEATURE: Record<string, string> = { speed_vs_score: 'Scored far above what the time taken predicts', hard_items_fast: 'Hardest questions answered correctly very quickly', wrong_answer_overlap: 'Many identical wrong answers shared with one other candidate', late_burst: 'Most answers saved in the last minutes', proctoring_risk: 'High proctoring risk score' };

/** Statistical review: unusual attempts for a person to look at (Target 171). */
function IntegrityReview({ examId, anomalies }: { examId: number; anomalies: Anomaly[] }) {
    return (
        <section className="grid gap-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="max-w-2xl text-sm text-muted-foreground">Each attempt is compared with everyone else who sat this exam. A flag is a statistical signal, never evidence on its own: clear it, or open an incident so the full procedure applies. Marks are never changed here.</p>
                <Button variant="outline" onClick={() => router.post(`/exams/${examId}/anomalies`, {}, { preserveScroll: true })}>Run the review now</Button>
            </div>
            {anomalies.length === 0 && <p className="text-sm text-muted-foreground">No unusual attempts found (the review needs at least 8 submitted attempts).</p>}
            {anomalies.map((a) => (
                <article key={a.id} className="surface grid gap-2 p-4 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <p className="font-medium">{a.candidate?.name} <span className="font-mono text-xs text-muted-foreground">{a.candidate?.identifier}</span></p>
                        <span className="flex items-center gap-2"><span className="tabular-nums text-xs">score {a.score}</span><Status value={a.review === 'open' ? 'pending' : a.review === 'cleared' ? 'dismissed' : 'escalated'} /></span>
                    </div>
                    <ul className="list-disc pl-5 text-muted-foreground">{a.reasons.map((r) => <li key={r.feature}>{FEATURE[r.feature] ?? r.feature} (z = {r.z})</li>)}</ul>
                    {a.review === 'open' && (
                        <div className="flex gap-2">
                            {a.session_id && <Link className="text-brand" href={`/proctor/sessions/${a.session_id}`}>Open the sitting</Link>}
                            <Button size="sm" variant="outline" onClick={() => router.post(`/anomalies/${a.id}`, { action: 'clear' }, { preserveScroll: true })}>Clear</Button>
                            <Button size="sm" onClick={() => router.post(`/anomalies/${a.id}`, { action: 'refer' }, { preserveScroll: true })}>Open an incident</Button>
                        </div>
                    )}
                </article>
            ))}
        </section>
    );
}

export default function ExamShow(props: {
    exam: ExamData; sections: Section[]; criteria: Criterion[]; examiners: { id: number; name: string; email: string; pivot: { role: string } }[];
    sessions: Sitting[]; approvals: { step: string; decision: string; comment: string | null; at: string; user: string | null }[];
    courses: { id: number; code: string; title: string }[]; semesters: { id: number; name: string; academic_session: { name: string } | null }[];
    topics: string[]; staff: { id: number; name: string; email: string }[];
    examinerAccounts: ExaminerAccount[]; examinerAccountsEnd: string | null;
    parent: { id: number; title: string } | null; group?: { id: number; title: string } | null; followUps: { id: number; title: string; kind: string; status: string }[];
    anomalies?: Anomaly[];
    banks?: Bank[]; problems?: string[]; questionReports?: ReportGroup[]; reportReasons?: Record<string, string>;
    practical?: PracticalSetup | null;
}) {
    const { exam } = props;
    const { flash } = usePage().props;
    // ?tab=paper|rules|sittings|examiners opens a tab directly (links from the exam's papers overview)
    const wanted = typeof window !== 'undefined' ? new URLSearchParams(window.location.search).get('tab') : null;
    const fromQuery = ({ overview: 'Overview', paper: 'Paper', banks: 'Question banks', reports: 'Question reports', rules: 'Rules & proctoring', sittings: 'Sittings', examiners: 'Examiners' } as Record<string, (typeof tabs)[number]>)[wanted ?? ''];
    const [tab, setTab] = useState<(typeof tabs)[number]>(fromQuery ?? 'Paper');
    const act = (action: string, comment?: string) => router.post(`/exams/${exam.id}/transition`, { action, comment }, { preserveScroll: true });

    return (
        <>
            <Head title={exam.title} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                {props.group && (
                    <Link href={`/exam-groups/${props.group.id}`} className="inline-flex w-fit items-center gap-1.5 text-sm text-muted-foreground hover:text-foreground">
                        <ArrowLeft className="size-4" /> {props.group.title}
                    </Link>
                )}
                <PageHeader
                    eyebrow={`${exam.type} · ${exam.category}${exam.kind !== 'regular' ? ` · ${exam.kind}` : ''} · version ${exam.version}`}
                    title={exam.title}
                    lead={
                        <span className="flex flex-wrap items-center gap-3">
                            <Status value={exam.status} />
                            {props.parent && (
                                <Link href={`/exams/${props.parent.id}`} className="text-sm text-brand">
                                    Original: {props.parent.title}
                                </Link>
                            )}
                            {props.followUps.map((f) => (
                                <Link key={f.id} href={`/exams/${f.id}`} className="text-sm text-brand">
                                    {f.kind}: <Status value={f.status} />
                                </Link>
                            ))}
                        </span>
                    }
                    actions={
                        <>
                            {['draft', 'returned'].includes(exam.status) &&
                                (exam.needs_approval ? <Button onClick={() => act('submit')}>Submit for approval</Button> : <Button onClick={() => act('publish')}>Publish</Button>)}
                            {exam.status === 'submitted' && (
                                <>
                                    <Button onClick={() => act('approve')}>Approve paper</Button>
                                    <Button variant="outline" onClick={() => act('return', prompt('What needs changing?') ?? undefined)}>
                                        Return
                                    </Button>
                                </>
                            )}
                            {['scheduled'].includes(exam.status) && (
                                <Button variant="outline" onClick={() => act('reopen')}>
                                    {exam.needs_approval ? 'Reopen for editing' : 'Edit the paper'}
                                </Button>
                            )}
                            {['scheduled', 'live'].includes(exam.status) && (
                                <Button variant="outline" onClick={() => act('close')}>
                                    Close exam
                                </Button>
                            )}
                            <Button variant="outline" asChild>
                                <Link href={exam.is_panel ? `/marking/${exam.id}/panel` : `/marking/${exam.id}/essays`}>Marking</Link>
                            </Button>
                            <Button variant="outline" asChild>
                                <Link href={`/results/${exam.id}`}>Results</Link>
                            </Button>
                            {exam.kind === 'regular' && ['closed', 'results_published', 'scheduled', 'live'].includes(exam.status) && (
                                <>
                                    <Button variant="outline" onClick={() => confirm('Create a resit for students who fail this exam?') && router.post(`/exams/${exam.id}/follow-up`, { kind: 'resit' })}>
                                        Create resit
                                    </Button>
                                    <Button variant="outline" onClick={() => confirm('Create a supplementary exam for students who fail or miss this exam?') && router.post(`/exams/${exam.id}/follow-up`, { kind: 'supplementary' })}>
                                        Create supplementary
                                    </Button>
                                </>
                            )}
                        </>
                    }
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}

                <SetupSteps exam={exam} sections={props.sections} criteria={props.criteria} sessions={props.sessions} problems={props.problems ?? []} go={setTab} publish={() => act(exam.needs_approval ? 'submit' : 'publish')} />

                <nav className="flex flex-wrap gap-1 border-b border-border">
                    {tabs.map((t) => {
                        const open = t === 'Question reports' ? (props.questionReports ?? []).reduce((n, g) => n + g.open, 0) : 0;

                        return (
                            <button key={t} role="tab" aria-selected={tab === t} onClick={() => setTab(t)} className={`-mb-px rounded-t-lg border-b-2 px-4 py-2 text-sm transition-colors ${tab === t ? 'border-brand bg-brand-soft/40 font-medium text-foreground' : 'border-transparent text-muted-foreground hover:bg-muted/60 hover:text-foreground'}`}>
                                {t}
                                {open > 0 && <span className="ml-1.5 rounded-full bg-status-warning px-1.5 py-0.5 text-[10px] font-bold text-white">{open}</span>}
                            </button>
                        );
                    })}
                </nav>

                {tab === 'Overview' && <Overview {...props} />}
                {tab === 'Paper' && (exam.is_panel ? <PracticalEditor exam={exam} criteria={props.criteria} practical={props.practical ?? null} /> : <PaperEditor exam={exam} sections={props.sections} topics={props.topics} banks={props.banks ?? []} problems={props.problems ?? []} />)}
                {tab === 'Question reports' && <QuestionReportsPanel examId={exam.id} groups={props.questionReports ?? []} reasons={props.reportReasons ?? {}} />}
                {tab === 'Question banks' && (exam.is_panel ? <p className="text-sm text-muted-foreground">This paper is scored by examiners with a checklist (Paper tab); it has no question banks.</p> : <BanksPanel exam={exam} banks={props.banks ?? []} sections={props.sections} />)}
                {tab === 'Rules & proctoring' && <RulesEditor exam={exam} />}
                {tab === 'Sittings' && <Sittings exam={exam} sessions={props.sessions} />}
                {tab === 'Examiners' && (
                    <div className="grid gap-10">
                        {['viva', 'practical', 'research'].includes(exam.type) && <ExaminerAccounts exam={exam} accounts={props.examinerAccounts ?? []} endsAt={props.examinerAccountsEnd} />}
                        <Examiners exam={exam} examiners={props.examiners} staff={props.staff} />
                    </div>
                )}
                {tab === 'Integrity review' && <IntegrityReview examId={exam.id} anomalies={props.anomalies ?? []} />}
                {tab === 'History' && (
                    <Table head={['When', 'Step', 'Decision', 'By', 'Comment']}>
                        {props.approvals.map((a, i) => (
                            <tr key={i}>
                                <Td className="text-xs">{a.at}</Td>
                                <Td>{a.step}</Td>
                                <Td>
                                    <Status value={a.decision} />
                                </Td>
                                <Td>{a.user}</Td>
                                <Td className="text-muted-foreground">{a.comment}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </>
    );
}

/**
 * The four things an exam needs, in order, each a click away: questions, students and a time,
 * rules, then publish. Ticks show what is done, so setting up never means hunting through tabs.
 */
function SetupSteps({ exam, sections, criteria, sessions, problems, go, publish }: {
    exam: ExamData; sections: Section[]; criteria: Criterion[]; sessions: Sitting[]; problems: string[];
    go: (t: (typeof tabs)[number]) => void; publish: () => void;
}) {
    const questions = exam.is_panel ? criteria.length : sections.reduce((n, s) => n + s.questions.length + s.blueprint.reduce((m, r) => m + Number(r.count || 0), 0), 0);
    const candidates = sessions.reduce((n, s) => n + s.candidates, 0);
    const published = !['draft', 'returned', 'submitted'].includes(exam.status);
    const steps = [
        { done: questions > 0 && problems.length === 0, title: exam.is_panel ? 'Checklist' : 'Questions', text: questions ? `${questions} ${exam.is_panel ? 'steps' : 'per student'}${problems.length ? ' · needs a fix' : ''}` : exam.is_panel ? 'Upload or type the checklist' : 'Upload a file or choose from banks', tab: 'Paper' as const },
        { done: sessions.length > 0 && candidates > 0, title: 'Students and time', text: sessions.length ? `${sessions.length} sitting${sessions.length === 1 ? '' : 's'} · ${candidates} students` : 'Set a date and add students', tab: 'Sittings' as const },
        { done: true, title: 'Rules', text: `${exam.duration_minutes} minutes${exam.settings.shuffle_questions ? ' · shuffled' : ''}`, tab: 'Rules & proctoring' as const },
    ];

    return (
        <ol className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4" aria-label="Setting up this exam">
            {steps.map((st, i) => (
                <li key={st.title}>
                    <button type="button" onClick={() => go(st.tab)} className="surface flex h-full w-full items-start gap-3 p-4 text-left transition hover:-translate-y-0.5 hover:border-brand/50 hover:shadow-[0_14px_30px_-20px_var(--brand)]">
                        <span className={`grid size-7 shrink-0 place-items-center rounded-full text-sm font-bold ${st.done ? 'bg-status-good text-white' : 'bg-brand-soft text-brand-ink'}`}>{st.done ? '✓' : i + 1}</span>
                        <span className="min-w-0">
                            <span className="block font-medium">{st.title}</span>
                            <span className="block text-sm text-muted-foreground">{st.text}</span>
                        </span>
                    </button>
                </li>
            ))}
            <li>
                <div className={`surface flex h-full items-start gap-3 p-4 ${published ? '' : 'border-brand/40'}`}>
                    <span className={`grid size-7 shrink-0 place-items-center rounded-full text-sm font-bold ${published ? 'bg-status-good text-white' : 'bg-brand-soft text-brand-ink'}`}>{published ? '✓' : 4}</span>
                    <span className="grid min-w-0 gap-2">
                        <span className="font-medium">{published ? 'Published' : exam.status === 'submitted' ? 'Waiting for approval' : 'Publish'}</span>
                        {!published && exam.status !== 'submitted' && (
                            <Button size="sm" className="w-fit" disabled={!steps[0].done} onClick={publish}>
                                {exam.needs_approval ? 'Submit for approval' : 'Publish now'}
                            </Button>
                        )}
                        {published && <span className="text-sm text-muted-foreground">Students can write it at the sitting time.</span>}
                    </span>
                </div>
            </li>
        </ol>
    );
}

function Overview({ exam, courses, semesters }: { exam: ExamData; courses: { id: number; code: string; title: string }[]; semesters: { id: number; name: string; academic_session: { name: string } | null }[] }) {
    const form = useForm({
        title: exam.title, code: exam.code ?? '', course_id: exam.course_id ?? '', semester_id: exam.semester_id ?? '', instructions: exam.instructions ?? '',
        duration_minutes: exam.duration_minutes, total_mark: exam.total_mark ?? '', pass_mark: exam.pass_mark ?? '', max_attempts: exam.max_attempts,
        score_policy: exam.score_policy, marking_policy: exam.marking_policy,
    });

    return (
        <form className="surface grid max-w-4xl gap-5 p-6 md:grid-cols-2" onSubmit={(e) => (e.preventDefault(), form.put(`/exams/${exam.id}`, { preserveScroll: true }))}>
            <Field label="Title" error={form.errors.title}>
                <input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} />
            </Field>
            <Field label="Code">
                <input className={inputClass} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} />
            </Field>
            <Field label="Course" hint="Candidates can be added from this course's registrations">
                <select className={inputClass} value={form.data.course_id} onChange={(e) => form.setData('course_id', e.target.value ? Number(e.target.value) : '')}>
                    <option value="">—</option>
                    {courses.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.code} — {c.title}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Semester">
                <select className={inputClass} value={form.data.semester_id} onChange={(e) => form.setData('semester_id', e.target.value ? Number(e.target.value) : '')}>
                    <option value="">—</option>
                    {semesters.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.academic_session?.name} {s.name}
                        </option>
                    ))}
                </select>
            </Field>
            {!exam.is_panel && (
                <>
                    <Field label="Duration (minutes)">
                        <input type="number" className={inputClass} value={form.data.duration_minutes} onChange={(e) => form.setData('duration_minutes', Number(e.target.value))} />
                    </Field>
                    <Field label="Attempts allowed">
                        <input type="number" min={1} className={inputClass} value={form.data.max_attempts} onChange={(e) => form.setData('max_attempts', Number(e.target.value))} />
                    </Field>
                    <Field label="Which attempt counts">
                        <select className={inputClass} value={form.data.score_policy} onChange={(e) => form.setData('score_policy', e.target.value)}>
                            {['highest', 'first', 'last', 'average'].map((p) => (
                                <option key={p}>{p}</option>
                            ))}
                        </select>
                    </Field>
                </>
            )}
            {exam.is_panel && (
                <Field label="Several examiners: combine by" hint="Applied per criterion">
                    <select className={inputClass} value={form.data.marking_policy} onChange={(e) => form.setData('marking_policy', e.target.value)}>
                        {['average', 'highest', 'first', 'median'].map((p) => (
                            <option key={p}>{p}</option>
                        ))}
                    </select>
                </Field>
            )}
            <Field label="Scale final score to" hint="Leave empty to keep raw marks">
                <input type="number" className={inputClass} value={form.data.total_mark} onChange={(e) => form.setData('total_mark', e.target.value)} />
            </Field>
            <Field label="Pass mark">
                <input type="number" className={inputClass} value={form.data.pass_mark} onChange={(e) => form.setData('pass_mark', e.target.value)} />
            </Field>
            <div className="md:col-span-2">
                <Field label="Instructions shown to candidates">
                    <textarea className={textareaClass} value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} />
                </Field>
            </div>
            <div className="md:col-span-2">
                <Button disabled={form.processing}>Save</Button>
            </div>
        </form>
    );
}

function PaperEditor({ exam, sections, topics, banks, problems }: { exam: ExamData; sections: Section[]; topics: string[]; banks: Bank[]; problems: string[] }) {
    const [picker, setPicker] = useState<{ section: number; how: 'banks' | 'search' | 'upload' } | null>(null);
    const open = (section: number, how: 'banks' | 'search' | 'upload') => setPicker(picker?.section === section && picker.how === how ? null : { section, how });

    return (
        <div className="grid gap-6">
            {!exam.can_edit && <p className="text-sm text-muted-foreground">This paper is approved. Reopen it for editing to change questions.</p>}
            <PaperProblems problems={problems} />
            {sections.map((s) => (
                <section key={s.id} className="surface p-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <h3 className="text-lg font-medium">{s.title}</h3>
                            <p className="text-sm text-muted-foreground">
                                {s.questions.length} fixed questions · {s.blueprint.reduce((n, r) => n + Number(r.count || 0), 0)} drawn at random per candidate
                                {s.blueprint.find((r) => r.draw) && ` · each candidate sees ${s.blueprint.find((r) => r.draw)?.draw} of the fixed questions`}
                            </p>
                        </div>
                        {exam.can_edit && (
                            <div className="flex flex-wrap gap-2">
                                <Button variant={picker?.section === s.id && picker.how === 'upload' ? 'default' : 'outline'} onClick={() => open(s.id, 'upload')}>
                                    <Plus className="size-4" /> Upload a file
                                </Button>
                                {banks.length > 0 && (
                                    <Button variant={picker?.section === s.id && picker.how === 'banks' ? 'default' : 'outline'} onClick={() => open(s.id, 'banks')}>
                                        <Plus className="size-4" /> From banks
                                    </Button>
                                )}
                                <Button variant={picker?.section === s.id && picker.how === 'search' ? 'default' : 'outline'} onClick={() => open(s.id, 'search')}>
                                    <Plus className="size-4" /> Search the course
                                </Button>
                                <Button variant="ghost" aria-label={`Delete ${s.title}`} onClick={() => confirm('Delete this section?') && router.delete(`/exams/${exam.id}/sections/${s.id}`, { preserveScroll: true })}>
                                    <Trash2 className="size-4" />
                                </Button>
                            </div>
                        )}
                    </div>
                    {picker?.section === s.id && (
                        <div className="mt-4 rounded-xl border border-brand/40 p-4">
                            {picker.how === 'upload' && (
                                <>
                                    <p className="mb-3 text-sm text-muted-foreground">The questions go on this section of the paper and into the paper&apos;s bank &quot;Uploaded questions&quot;. To upload into a topic bank, use the Question banks tab.</p>
                                    <QuestionUpload sectionId={s.id} onCancel={() => setPicker(null)} />
                                </>
                            )}
                            {picker.how === 'banks' && <BankPicker examId={exam.id} sectionId={s.id} banks={banks} onDone={() => setPicker(null)} />}
                            {picker.how === 'search' && <QuestionPicker exam={exam} section={s} onDone={() => setPicker(null)} />}
                        </div>
                    )}
                    <Blueprint exam={exam} section={s} topics={topics} banks={banks} />
                    <ol className="mt-4 grid gap-2">
                        {s.questions.map((q, i) => (
                            <li key={q.id} className="flex items-start gap-3 rounded-xl border border-border bg-card/60 p-3">
                                <span className="font-mono text-xs text-muted-foreground">{i + 1}</span>
                                <RichContent html={q.stem_html} className="flex-1 text-sm" />
                                <span className="text-xs whitespace-nowrap text-muted-foreground">
                                    {q.type} · {q.mark} mk
                                </span>
                                {exam.can_edit && (
                                    <Button size="icon" variant="ghost" className="size-8" onClick={() => router.delete(`/exams/${exam.id}/sections/${s.id}/questions/${q.id}`, { preserveScroll: true })} aria-label="Remove question">
                                        <Trash2 className="size-4" />
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ol>
                </section>
            ))}
            {exam.can_edit && (
                <Button variant="outline" className="w-fit" onClick={() => router.post(`/exams/${exam.id}/sections`, { title: `Section ${String.fromCharCode(65 + sections.length)}` }, { preserveScroll: true })}>
                    <Plus className="size-4" /> Add section
                </Button>
            )}
        </div>
    );
}

function Blueprint({ exam, section, topics, banks }: { exam: ExamData; section: Section; topics: string[]; banks: Bank[] }) {
    const [rules, setRules] = useState<Rule[]>(section.blueprint.filter((r) => r.draw === undefined));
    const [draw, setDraw] = useState<string>(String(section.blueprint.find((r) => r.draw !== undefined)?.draw ?? ''));
    const set = (i: number, patch: Partial<Rule>) => setRules(rules.map((r, j) => (j === i ? { ...r, ...patch } : r)));

    return (
        <div className="mt-4 rounded-xl bg-muted/40 p-4">
            <p className="eyebrow mb-1">Random questions per candidate</p>
            <p className="mb-3 text-xs text-muted-foreground">Each rule draws a number of questions at random, from chosen banks or from the course&apos;s approved questions. With banks you can ask for at least a set number from each bank, so every topic is covered.</p>
            {rules.map((r, i) => {
                const fromBanks = (r.bank_ids ?? []).length > 0;

                return (
                    <div key={i} className="mb-3 grid gap-2 rounded-lg border border-border bg-card/70 p-3">
                        {banks.length > 0 && (
                            <div className="grid gap-1.5">
                                <span className="text-xs text-muted-foreground">{fromBanks ? 'From these banks' : 'From the course pool, or choose banks:'}</span>
                                <BankChips banks={banks} value={r.bank_ids ?? []} disabled={!exam.can_edit} onChange={(ids) => set(i, { bank_ids: ids, min_each: ids.length ? r.min_each : null })} />
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-2 md:grid-cols-7">
                            <select className={inputClass} value={r.topic ?? ''} disabled={!exam.can_edit} onChange={(e) => set(i, { topic: e.target.value || null })} aria-label="Topic">
                                <option value="">Any topic</option>
                                {topics.map((t) => (
                                    <option key={t}>{t}</option>
                                ))}
                            </select>
                            <select className={inputClass} value={r.difficulty ?? ''} disabled={!exam.can_edit} onChange={(e) => set(i, { difficulty: e.target.value || null })} aria-label="Difficulty">
                                <option value="">Any difficulty</option>
                                {['easy', 'medium', 'hard'].map((d) => (
                                    <option key={d}>{d}</option>
                                ))}
                            </select>
                            <select className={inputClass} value={r.type ?? ''} disabled={!exam.can_edit} onChange={(e) => set(i, { type: e.target.value || null })} aria-label="Type">
                                <option value="">Any type</option>
                                {['single_choice', 'multiple_choice', 'true_false', 'multi_true_false', 'matching', 'ordering', 'fill_blank', 'numeric', 'short_answer', 'essay', 'file_upload'].map((t) => (
                                    <option key={t}>{t}</option>
                                ))}
                            </select>
                            <label className="grid gap-0.5 text-xs text-muted-foreground">How many
                                <input type="number" min={1} className={inputClass} value={r.count} disabled={!exam.can_edit} onChange={(e) => set(i, { count: Number(e.target.value) })} />
                            </label>
                            <label className="grid gap-0.5 text-xs text-muted-foreground">At least from each bank
                                <input type="number" min={0} className={inputClass} value={r.min_each ?? ''} placeholder={fromBanks ? '0' : 'choose banks'} disabled={!exam.can_edit || !fromBanks} onChange={(e) => set(i, { min_each: e.target.value === '' ? null : Number(e.target.value) })} />
                            </label>
                            <label className="grid gap-0.5 text-xs text-muted-foreground">Mark each
                                <input type="number" min={0} step="0.25" className={inputClass} placeholder="as set" value={r.mark ?? ''} disabled={!exam.can_edit} onChange={(e) => set(i, { mark: e.target.value === '' ? null : Number(e.target.value) })} />
                            </label>
                            {exam.can_edit && (
                                <Button variant="ghost" className="self-end" onClick={() => setRules(rules.filter((_, j) => j !== i))}>
                                    Remove
                                </Button>
                            )}
                        </div>
                    </div>
                );
            })}
            <label className="mt-3 mb-3 flex flex-wrap items-center gap-2 text-sm">
                <span>Each candidate sees</span>
                <input type="number" min={1} className={`${inputClass} !w-24`} value={draw} disabled={!exam.can_edit} onChange={(e) => setDraw(e.target.value)} placeholder="all" />
                <span className="text-muted-foreground">of this section&apos;s fixed questions, picked at random</span>
            </label>
            {exam.can_edit && (
                <div className="flex flex-wrap gap-2">
                    <Button variant="outline" size="sm" onClick={() => setRules([...rules, { count: 10, bank_ids: banks.length ? banks.filter((b) => b.scope === 'exam').map((b) => b.id) : [] }])}>
                        Add rule
                    </Button>
                    <Button size="sm" onClick={() => router.put(`/exams/${exam.id}/sections/${section.id}`, { blueprint: [...rules, ...(Number(draw) > 0 ? [{ draw: Number(draw) }] : [])] } as never, { preserveScroll: true })}>
                        Save
                    </Button>
                </div>
            )}
        </div>
    );
}

function QuestionPicker({ exam, section, onDone }: { exam: ExamData; section: Section; onDone: () => void }) {
    const [q, setQ] = useState('');
    const [results, setResults] = useState<Q[]>([]);
    const [chosen, setChosen] = useState<number[]>([]);
    const search = async () => {
        const res = await fetch(`/exams/${exam.id}/question-search?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });
        setResults(await res.json());
    };

    return (
        <div>
            <div className="flex gap-2">
                <input className={inputClass} placeholder="Search approved questions in this course" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), search())} />
                <Button variant="outline" onClick={search}>
                    Search
                </Button>
            </div>
            <ul className="mt-3 max-h-96 space-y-2 overflow-y-auto">
                {results.map((r) => (
                    <li key={r.id}>
                        <label className="flex cursor-pointer items-start gap-3 rounded-lg border border-border p-2 text-sm">
                            <input type="checkbox" checked={chosen.includes(r.id)} onChange={(e) => setChosen(e.target.checked ? [...chosen, r.id] : chosen.filter((x) => x !== r.id))} />
                            <RichContent html={r.stem_html} className="flex-1" />
                            <span className="text-xs text-muted-foreground">
                                {r.type} · {r.topic}
                            </span>
                        </label>
                    </li>
                ))}
            </ul>
            <Button className="mt-3" disabled={!chosen.length} onClick={() => router.post(`/exams/${exam.id}/sections/${section.id}/questions`, { question_ids: chosen }, { preserveScroll: true, onSuccess: onDone })}>
                Add {chosen.length} questions
            </Button>
        </div>
    );
}


const settingLabels: Record<string, string> = {
    shuffle_questions: 'Shuffle question order per candidate', shuffle_options: 'Shuffle answer options', show_score: 'Show objective score right after submitting',
    show_review: 'Let candidates review answers after results', allow_navigation: 'Free navigation between questions', allow_flagging: 'Flag questions for review',
    calculator: 'On-screen calculator', partial_credit: 'Partial credit on multiple-answer questions',
};
const proctorLabels: Record<string, string> = {
    require_consent: 'Monitoring notice must be accepted', require_fullscreen: 'Require full screen', detect_tab_switch: 'Detect tab switching', detect_focus_loss: 'Detect leaving the window',
    block_copy_paste: 'Block copy and paste', block_shortcuts: 'Block keyboard shortcuts', detect_multiple_displays: 'Detect extra monitors / projection',
    webcam: 'Webcam snapshots', face_detection: 'Face presence & multiple-face alerts', microphone: 'Microphone', screen_share: 'Screen sharing', second_camera: 'Second camera (phone/USB)',
    live_video: 'Live video to proctors', record_video: 'Record camera', record_screen: 'Record screen', device_whitelist: 'Approved hall computers only (desktop app)', ip_approval: 'Only computers approved by IP address (see Exam network)',
    single_session: 'One active window per candidate', low_bandwidth: 'Low-bandwidth mode', gaze_tracking: 'Looking-away alerts (head turned from the screen)', close_banned_apps: 'Desktop app closes screen-sharing and remote-control apps',
};

/** The checks a candidate must pass before this exam can start: all, some, or none. */
function PreCheckPicker({ options, value, onChange }: { options: { key: string; label: string; hint: string }[]; value: string[]; onChange: (v: string[]) => void }) {
    const set = new Set(value);

    return (
        <section className="surface p-6 lg:col-span-2">
            <div className="mb-4 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p className="eyebrow mb-1">Before the exam starts</p>
                    <p className="text-sm text-muted-foreground">The candidate cannot start until every chosen check has passed on their screen. Works for remote and hall exams.</p>
                </div>
                <div className="flex gap-2">
                    <Button type="button" size="sm" variant="outline" onClick={() => onChange(options.map((o) => o.key))}>Select all</Button>
                    <Button type="button" size="sm" variant="outline" onClick={() => onChange([])}>None</Button>
                </div>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {options.map((o) => (
                    <label key={o.key} className={`flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 text-sm transition-colors ${set.has(o.key) ? 'border-brand/50 bg-brand/[.06]' : 'border-border hover:border-brand/30'}`}>
                        <input type="checkbox" className="mt-0.5" checked={set.has(o.key)} onChange={(e) => onChange(e.target.checked ? [...value, o.key] : value.filter((k) => k !== o.key))} />
                        <span><b className="block font-medium">{o.label}</b><span className="text-xs text-muted-foreground">{o.hint}</span></span>
                    </label>
                ))}
            </div>
            <p className="mt-3 text-xs text-muted-foreground">{value.length === 0 ? 'No checks: candidates start straight away.' : `${value.length} of ${options.length} checks chosen.`}</p>
        </section>
    );
}

function RulesEditor({ exam }: { exam: ExamData }) {
    const [settings, setSettings] = useState(exam.settings);
    const [proctoring, setProctoring] = useState(exam.proctoring);
    const [channels, setChannels] = useState(exam.channels ?? ['web', 'desktop', 'seb']);
    const [certificate, setCertificate] = useState<number | ''>(exam.certificate_template_id ?? '');
    const [seb, setSeb] = useState({ config_key: exam.settings.seb?.config_key ?? '', browser_exam_keys: (exam.settings.seb?.browser_exam_keys ?? []).join('\n'), quit_password: exam.settings.seb?.quit_password ?? '', settings_password: '', clear_settings_password: false });
    const toggle = (obj: Record<string, unknown>, set: (v: Record<string, unknown>) => void, key: string) => set({ ...obj, [key]: !obj[key] });
    const save = () =>
        router.put(`/exams/${exam.id}`, {
            settings, proctoring, channels, certificate_template_id: certificate || null,
            seb: { config_key: seb.config_key || null, browser_exam_keys: seb.browser_exam_keys.split(/\s+/).filter(Boolean), quit_password: seb.quit_password || null, settings_password: seb.settings_password || null, clear_settings_password: seb.clear_settings_password || null },
        } as never, { preserveScroll: true });

    return (
        <div className="grid gap-6 lg:grid-cols-2">
            <section className="surface p-6">
                <p className="eyebrow mb-4">Exam rules</p>
                {Object.keys(settingLabels).map((k) => (
                    <label key={k} className="flex items-center justify-between gap-3 border-b border-border py-2 text-sm last:border-0">
                        {settingLabels[k]}
                        <input type="checkbox" checked={Boolean(settings[k])} onChange={() => toggle(settings, setSettings, k)} />
                    </label>
                ))}
                <div className="mt-3 grid grid-cols-2 gap-3">
                    <Field label="Negative mark per wrong answer">
                        <input type="number" step="0.25" min={0} className={inputClass} value={Number(settings.negative_marking ?? 0)} onChange={(e) => setSettings({ ...settings, negative_marking: Number(e.target.value) })} />
                    </Field>
                    <Field label="Certificate or badge">
                        <select className={inputClass} value={certificate} onChange={(e) => setCertificate(e.target.value ? Number(e.target.value) : '')}>
                            <option value="">None</option>
                            {(exam.certificate_templates ?? []).map((t) => <option key={t.id} value={t.id}>{t.name} ({t.kind}, from {t.min_percent}%)</option>)}
                        </select>
                    </Field>
                    <Field label="Grading scale">
                        <select className={inputClass} value={String(settings.grading_scale ?? '')} onChange={(e) => setSettings({ ...settings, grading_scale: e.target.value })}>
                            <option value="">The institution's main scale</option>
                            {(exam.grading_scales ?? []).map((n) => <option key={n} value={n}>{n}</option>)}
                        </select>
                    </Field>
                    <Field label="Multiple true/false scoring">
                        <select className={inputClass} value={String(settings.mtf_scoring ?? 'per_statement')} onChange={(e) => setSettings({ ...settings, mtf_scoring: e.target.value })}>
                            <option value="per_statement">Each statement earns its share</option>
                            <option value="all_or_nothing">Marks only when every statement is right</option>
                        </select>
                    </Field>
                    <Field label="Autosave every (s)">
                        <input type="number" min={3} className={inputClass} value={Number(settings.autosave_seconds ?? 10)} onChange={(e) => setSettings({ ...settings, autosave_seconds: Number(e.target.value) })} />
                    </Field>
                </div>
                <fieldset className="mt-4 grid gap-2 border-t border-border pt-4 text-sm">
                    <legend className="font-medium">Students who arrive late</legend>
                    <label className="flex items-start gap-2">
                        <input type="radio" className="mt-1" name="late_start" checked={(settings.late_start ?? 'sitting') === 'sitting'} onChange={() => setSettings({ ...settings, late_start: 'sitting' })} />
                        <span>Use each sitting's late-entry time</span>
                    </label>
                    <label className="flex items-start gap-2">
                        <input type="radio" className="mt-1" name="late_start" checked={settings.late_start === 'any_time'} onChange={() => setSettings({ ...settings, late_start: 'any_time' })} />
                        <span>Allow them to start at any time while the sitting is open, even 30 minutes or more into the exam (they still finish when the sitting ends)</span>
                    </label>
                    <label className="flex flex-wrap items-center gap-2">
                        <input type="radio" name="late_start" checked={settings.late_start === 'cutoff'} onChange={() => setSettings({ ...settings, late_start: 'cutoff' })} />
                        <span>Do not allow starting after</span>
                        <input type="number" min={1} max={600} className={`${inputClass} !w-20`} value={Number(settings.late_start_minutes ?? 30)} onChange={(e) => setSettings({ ...settings, late_start: 'cutoff', late_start_minutes: Number(e.target.value) })} />
                        <span>minutes into the exam</span>
                    </label>
                </fieldset>
            </section>
            <section className="surface p-6">
                <p className="eyebrow mb-4">Proctoring policy</p>
                <p className="mb-2 rounded-md bg-muted/60 p-2 text-xs text-muted-foreground">AI checks (faces, looking away, noise, covered camera) are confirmed over several seconds and frames before anything is reported, and the picture is kept. They never count as a warning strike: they are put in front of a person, who decides.</p>
                {Object.keys(proctorLabels).map((k) => (
                    <label key={k} className="flex items-center justify-between gap-3 border-b border-border py-2 text-sm last:border-0">
                        {proctorLabels[k]}
                        <input type="checkbox" checked={Boolean(proctoring[k])} onChange={() => toggle(proctoring, setProctoring, k)} />
                    </label>
                ))}
                <div className="mt-3 grid grid-cols-3 gap-3">
                    <Field label="Warnings (strikes) allowed">
                        <input type="number" min={1} className={inputClass} value={Number(proctoring.violation_threshold ?? 5)} onChange={(e) => setProctoring({ ...proctoring, violation_threshold: Number(e.target.value) })} />
                    </Field>
                    <Field label="Then">
                        <select className={inputClass} value={String(proctoring.violation_action ?? 'lock')} onChange={(e) => setProctoring({ ...proctoring, violation_action: e.target.value })}>
                            <option value="warn">warn only</option>
                            <option value="lock">lock (invigilator unlocks)</option>
                            <option value="submit">submit automatically</option>
                        </select>
                    </Field>
                    <Field label="Recording (when the camera or screen is recorded)">
                        <select className={inputClass} value={String(proctoring.recording_mode ?? 'institution')} onChange={(e) => setProctoring({ ...proctoring, recording_mode: e.target.value })}>
                            <option value="institution">As the institution has chosen</option>
                            <option value="stream">Live streaming (supervisors can watch; recorded as it goes)</option>
                            <option value="progressive">Recorded on the computer, uploaded as it goes</option>
                            <option value="on_submit">Recorded on the computer, uploaded after submitting</option>
                            <option value="triggered">Short clips only when something happens</option>
                        </select>
                    </Field>
                    <Field label="Snapshot every (s)">
                        <input type="number" min={10} className={inputClass} value={Number(proctoring.snapshot_seconds ?? 60)} onChange={(e) => setProctoring({ ...proctoring, snapshot_seconds: Number(e.target.value) })} />
                    </Field>
                    <Field label="Identity check">
                        <select className={inputClass} value={String(proctoring.identity_check ?? 'none')} onChange={(e) => setProctoring({ ...proctoring, identity_check: e.target.value })}>
                            <option value="none">none</option>
                            <option value="photo_match">face matched to enrolled face / ID photo</option>
                            <option value="proctor_review">photo taken for a proctor to check</option>
                        </select>
                    </Field>
                    <Field label="Repeat the face check every (min, 0 = entry only)">
                        <input type="number" min={0} className={inputClass} value={Number(proctoring.identity_recheck_minutes ?? 0)} onChange={(e) => setProctoring({ ...proctoring, identity_recheck_minutes: Number(e.target.value) })} />
                    </Field>
                </div>
            </section>
            <section className={`surface p-6 lg:col-span-2 ${settings.emergency ? 'ring-2 ring-status-warning/60' : ''}`}>
                <label className="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" role="switch" className="mt-1 size-4" checked={!!settings.emergency} onChange={(e) => setSettings({ ...settings, emergency: e.target.checked })} />
                    <span>
                        <b className="block font-medium">Emergency exam</b>
                        <span className="text-sm text-muted-foreground">
                            While this exam is open, its students see only this exam when they sign in and start it with one press. No checks, monitoring notice,
                            camera, microphone or approved-computer rules. Time it in the room; the on-screen timer still runs. A sitting PIN, if set, is still asked for.
                        </span>
                    </span>
                </label>
                {!!settings.emergency && <p className="mt-3 rounded-xl bg-status-warning/12 p-3 text-sm text-status-warning">On: the checks and monitoring settings on this page are ignored for this exam.</p>}
            </section>
            <PreCheckPicker options={exam.pre_check_options ?? []} value={Array.isArray(proctoring.pre_checks) ? (proctoring.pre_checks as string[]) : []} onChange={(v) => setProctoring({ ...proctoring, pre_checks: v })} />
            <section className="surface p-6 lg:col-span-2">
                <p className="eyebrow mb-1">Connection and power cuts</p>
                <p className="mb-4 text-sm text-muted-foreground">Candidates who lose the internet keep writing; their answers stay on their computer and are sent when it returns. Time is given back only when the computer itself went off.</p>
                <div className="grid gap-3 md:grid-cols-2">
                    <Field label="Time given back for a computer that went off (minutes, whole exam; 0 = none)">
                        <input type="number" min={0} max={240} className={inputClass} value={Number(settings.power_cut_minutes ?? 30)} onChange={(e) => setSettings({ ...settings, power_cut_minutes: Number(e.target.value) })} />
                    </Field>
                    <Field label="Wait for a disconnected computer after time is up (minutes)">
                        <input type="number" min={10} max={4320} className={inputClass} value={Number(settings.offline_sync_minutes ?? 120)} onChange={(e) => setSettings({ ...settings, offline_sync_minutes: Number(e.target.value) })} />
                    </Field>
                </div>
            </section>
            <section className="surface p-6 lg:col-span-2">
                <p className="eyebrow mb-4">How candidates may take it</p>
                <div className="flex flex-wrap gap-4 text-sm">
                    {[
                        ['web', 'Web browser'],
                        ['desktop', 'Desktop exam app'],
                        ['seb', 'Safe Exam Browser'],
                    ].map(([k, label]) => (
                        <label key={k} className="flex items-center gap-2">
                            <input type="checkbox" checked={channels.includes(k)} onChange={(e) => setChannels(e.target.checked ? [...channels, k] : channels.filter((c) => c !== k))} />
                            {label}
                        </label>
                    ))}
                </div>
                {channels.includes('seb') && (
                    <div className="mt-4 grid gap-3 md:grid-cols-3">
                        <Field label="SEB Config Key" hint="From SEB Config Tool, optional">
                            <input className={`${inputClass} font-mono`} value={seb.config_key} onChange={(e) => setSeb({ ...seb, config_key: e.target.value })} />
                        </Field>
                        <Field label="Browser Exam Keys" hint="One per line (per SEB version/OS)">
                            <textarea className={`${textareaClass} font-mono`} value={seb.browser_exam_keys} onChange={(e) => setSeb({ ...seb, browser_exam_keys: e.target.value })} />
                        </Field>
                        <Field label="SEB quit password" hint="Empty: the institution's quit password">
                            <input className={inputClass} value={seb.quit_password} onChange={(e) => setSeb({ ...seb, quit_password: e.target.value })} />
                        </Field>
                        <Field label="SEB password for this exam" hint={exam.settings.seb?.has_settings_password ? 'Set. Type a new one to change it.' : "Empty: the school's SEB password (Safe Exam Browser page)"}>
                            <input type="password" autoComplete="new-password" className={inputClass} value={seb.settings_password} onChange={(e) => setSeb({ ...seb, settings_password: e.target.value, clear_settings_password: false })} />
                        </Field>
                        {exam.settings.seb?.has_settings_password && (
                            <label className="flex items-center gap-2 text-sm md:col-span-3">
                                <input type="checkbox" checked={seb.clear_settings_password} onChange={(e) => setSeb({ ...seb, clear_settings_password: e.target.checked })} /> Use the school's SEB password instead of this exam's own
                            </label>
                        )}
                    </div>
                )}
            </section>
            <div>
                <Button onClick={save}>Save rules</Button>
            </div>
        </div>
    );
}

function Sittings({ exam, sessions }: { exam: ExamData; sessions: Sitting[] }) {
    const form = useForm({ name: '', mode: 'hall', starts_at: '', ends_at: '', late_entry_minutes: 15, pin: '', is_practice: false });

    return (
        <div className="grid gap-6">
            <Table head={['Sitting', 'Mode', 'When', 'Candidates', 'Attempts', 'Status', '']}>
                {sessions.map((s) => (
                    <tr key={s.id}>
                        <Td>
                            <Link href={`/sessions/${s.id}`} className="font-medium hover:text-brand">
                                {s.name || `Sitting #${s.id}`}
                            </Link>
                            {s.is_practice && <span className="ml-2 text-xs text-muted-foreground">practice</span>}
                            <div className="text-xs text-muted-foreground">{s.venue ?? (s.mode === 'remote' ? 'Remote' : '—')}</div>
                        </Td>
                        <Td>{s.mode}</Td>
                        <Td className="text-xs">
                            {formatDateTime(s.starts_at)} → {formatDateTime(s.ends_at)}
                        </Td>
                        <Td className="tabular-nums">{s.candidates}</Td>
                        <Td className="tabular-nums">{s.attempts}</Td>
                        <Td>
                            <Status value={s.status} />
                        </Td>
                        <Td>
                            <span className="flex flex-wrap gap-2">
                                <Button size="sm" asChild>
                                    <Link href={`/sessions/${s.id}`}>{s.candidates ? 'Students' : 'Add students'}</Link>
                                </Button>
                                <Button size="sm" variant="outline" asChild>
                                    <Link href={`/proctor/sessions/${s.id}`}>Console</Link>
                                </Button>
                                <Button size="sm" variant="ghost" title="A link and code students can open to start this sitting" onClick={() => router.post(`/sessions/${s.id}/share`)}>
                                    Share link
                                </Button>
                            </span>
                        </Td>
                    </tr>
                ))}
            </Table>
                <form className="surface grid gap-4 p-6 md:grid-cols-4" onSubmit={(e) => (e.preventDefault(), form.post(`/exams/${exam.id}/sessions`))}>
                    <p className="eyebrow md:col-span-4">Schedule a sitting</p>
                    <Field label="Name">
                        <input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Batch A" />
                    </Field>
                    <Field label="Mode">
                        <select className={inputClass} value={form.data.mode} onChange={(e) => form.setData('mode', e.target.value)}>
                            <option value="hall">CBT hall</option>
                            <option value="remote">Remote (proctored online)</option>
                        </select>
                    </Field>
                    <Field label="Opens" error={form.errors.starts_at}>
                        <input type="datetime-local" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} required />
                    </Field>
                    <Field label="Closes" error={form.errors.ends_at}>
                        <input type="datetime-local" className={inputClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} required />
                    </Field>
                    <Field label="Late entry allowed (min)">
                        <input type="number" min={0} className={inputClass} value={form.data.late_entry_minutes} onChange={(e) => form.setData('late_entry_minutes', Number(e.target.value))} />
                    </Field>
                    <Field label="Session PIN (optional)" hint="Announced in the hall">
                        <input className={inputClass} value={form.data.pin} onChange={(e) => form.setData('pin', e.target.value)} />
                    </Field>
                    <label className="flex items-center gap-2 self-end pb-2 text-sm">
                        <input type="checkbox" checked={form.data.is_practice} onChange={(e) => form.setData('is_practice', e.target.checked)} /> Practice / dry run
                    </label>
                    <div className="self-end">
                        <Button disabled={form.processing}>Schedule</Button>
                    </div>
                </form>
        </div>
    );
}

type ExaminerAccount = { id: number; first_name: string; last_name: string; username: string; signed_in: boolean; last_seen_at: string | null; last_ip: string | null; over: boolean };

/** Examiners made only for this exam: first name, last name, username, password. One computer at a time; they stop working when the exam is over. */
function ExaminerAccounts({ exam, accounts, endsAt }: { exam: ExamData; accounts: ExaminerAccount[]; endsAt: string | null }) {
    const form = useForm({ first_name: '', last_name: '', username: '', password: '' });
    const [pw, setPw] = useState<{ id: number; value: string } | null>(null);

    return (
        <section className="grid max-w-4xl gap-4">
            <div>
                <h2 className="text-base font-semibold">Examiner accounts for this exam only</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    These examiners sign in with the username and password you set. They see only this exam's scoring sheet (and its meeting room), on one computer at a time, and cannot sign in once the exam is over
                    {endsAt ? ` (${formatDateTime(endsAt)}, when the last sitting ends)` : ' (when it is closed, or its last sitting ends)'}.
                </p>
            </div>
            {accounts.length > 0 && (
                <Table head={['Examiner', 'Username', 'Signed in', '']}>
                    {accounts.map((a) => (
                        <tr key={a.id}>
                            <Td>{a.first_name} {a.last_name}</Td>
                            <Td className="font-mono text-xs">{a.username}</Td>
                            <Td>
                                {a.over ? <Status value="ended" /> : a.signed_in ? <span className="text-sm text-status-good">Signed in{a.last_ip ? ` · ${a.last_ip}` : ''}</span> : <span className="text-sm text-muted-foreground">{a.last_seen_at ? `Last seen ${formatDateTime(a.last_seen_at)}` : 'Not yet'}</span>}
                            </Td>
                            <Td>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button size="sm" variant="outline" disabled={!a.signed_in} onClick={() => confirm(`Sign ${a.first_name} out everywhere so they can sign in again?`) && router.post(`/exams/${exam.id}/examiner-accounts/${a.id}/reset-session`, {}, { preserveScroll: true })}>Reset session</Button>
                                    {pw?.id === a.id ? (
                                        <form className="flex gap-1" onSubmit={(e) => (e.preventDefault(), router.put(`/exams/${exam.id}/examiner-accounts/${a.id}/password`, { password: pw.value }, { preserveScroll: true, onSuccess: () => setPw(null) }))}>
                                            <input aria-label="New password" type="text" minLength={8} className={`${inputClass} h-8 !w-36`} value={pw.value} onChange={(e) => setPw({ id: a.id, value: e.target.value })} required />
                                            <Button size="sm">Set</Button>
                                        </form>
                                    ) : (
                                        <Button size="sm" variant="ghost" onClick={() => setPw({ id: a.id, value: '' })}>New password</Button>
                                    )}
                                    <button className="text-sm text-muted-foreground hover:text-status-critical" onClick={() => confirm(`Remove ${a.username}? They will not be able to sign in. Scores already given stay.`) && router.delete(`/exams/${exam.id}/examiner-accounts/${a.id}`, { preserveScroll: true })}>Remove</button>
                                </div>
                            </Td>
                        </tr>
                    ))}
                </Table>
            )}
            <form className="grid gap-3 rounded-lg border border-border bg-card p-4 sm:grid-cols-2 lg:grid-cols-5" onSubmit={(e) => (e.preventDefault(), form.post(`/exams/${exam.id}/examiner-accounts`, { preserveScroll: true, onSuccess: () => form.reset() }))}>
                <Field label="First name" error={form.errors.first_name}><input className={inputClass} value={form.data.first_name} onChange={(e) => form.setData('first_name', e.target.value)} required /></Field>
                <Field label="Last name" error={form.errors.last_name}><input className={inputClass} value={form.data.last_name} onChange={(e) => form.setData('last_name', e.target.value)} required /></Field>
                <Field label="Username" error={form.errors.username}><input className={inputClass} value={form.data.username} onChange={(e) => form.setData('username', e.target.value.replace(/\s/g, ''))} autoComplete="off" required /></Field>
                <Field label="Password" hint="at least 8 characters" error={form.errors.password}><input type="text" className={inputClass} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} autoComplete="new-password" minLength={8} required /></Field>
                <div className="flex items-end"><Button disabled={form.processing}>Create examiner</Button></div>
            </form>
        </section>
    );
}

function Examiners({ exam, examiners, staff }: { exam: ExamData; examiners: { id: number; name: string; email: string; pivot: { role: string } }[]; staff: { id: number; name: string; email: string }[] }) {
    const form = useForm({ user_id: '', role: 'examiner' });

    return (
        <div className="grid max-w-3xl gap-4">
            <h2 className="text-base font-semibold">Staff examiners</h2>
            <Table head={['Examiner', 'Role', '']}>
                {examiners.map((e) => (
                    <tr key={e.id}>
                        <Td>
                            {e.name}
                            <div className="text-xs text-muted-foreground">{e.email}</div>
                        </Td>
                        <Td>{e.pivot.role}</Td>
                        <Td>
                            <button className="text-muted-foreground hover:text-status-critical" onClick={() => router.delete(`/exams/${exam.id}/examiners/${e.id}`, { preserveScroll: true })}>
                                Remove
                            </button>
                        </Td>
                    </tr>
                ))}
            </Table>
            <form className="flex flex-wrap gap-2" onSubmit={(e) => (e.preventDefault(), form.post(`/exams/${exam.id}/examiners`, { preserveScroll: true }))}>
                <select className={`${inputClass} max-w-xs`} value={form.data.user_id} onChange={(e) => form.setData('user_id', e.target.value)} required>
                    <option value="">Choose staff…</option>
                    {staff.map((s) => (
                        <option key={s.id} value={s.id}>
                            {s.name}
                        </option>
                    ))}
                </select>
                <select className={`${inputClass} max-w-[160px]`} value={form.data.role} onChange={(e) => form.setData('role', e.target.value)}>
                    <option value="examiner">examiner</option>
                    <option value="moderator">moderator</option>
                    <option value="external">external</option>
                </select>
                <Button>Add</Button>
            </form>
        </div>
    );
}

ExamShow.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Exam', href: '#' }] };
