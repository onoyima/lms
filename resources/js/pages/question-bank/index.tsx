import { Head, Link, router } from '@inertiajs/react';
import { Download, FileSpreadsheet, FileUp, Pencil, Plus } from 'lucide-react';
import { useState } from 'react';
import { Flash, inputClass, PageHeader } from '@/components/platform/ui';
import { QuestionView } from '@/components/question/question-view';
import { Button } from '@/components/ui/button';
import type { Paginated, QuestionOption } from '@/types';

type Row = {
    id: number;
    type: string;
    stem_html: string;
    mark: number;
    status: string;
    topic: string | null;
    difficulty: string | null;
    outcome: string | null;
    course: string | null;
    version: number;
    options: QuestionOption[];
    matching: { left: { key: string; html: string }[]; right: { key: string; html: string }[]; answer: Record<string, string> } | null;
    accepted: string[] | null;
    numeric: { value: number; tolerance?: number } | null;
    mine: boolean;
};
type Filters = { course_id?: string; type?: string; status?: string; topic?: string; difficulty?: string; q?: string };

const TYPES = ['single_choice', 'multiple_choice', 'true_false', 'matching', 'numeric', 'short_answer', 'essay'];

function answerText(q: Row): string | null {
    if (q.matching) {
        return q.matching.left
            .map((l) => `${l.html.replace(/<[^>]+>/g, '')} → ${(q.matching!.right.find((r) => r.key === q.matching!.answer[l.key])?.html ?? '').replace(/<[^>]+>/g, '')}`)
            .join(' · ');
    }

    if (q.accepted) {
        return q.accepted.join(' / ');
    }

    if (q.numeric) {
        return `${q.numeric.value}${q.numeric.tolerance ? ` ± ${q.numeric.tolerance}` : ''}`;
    }

    return null;
}

export default function QuestionBank({
    questions,
    courses,
    topics,
    filters,
    canCreate,
    canModerate,
}: {
    questions: Paginated<Row>;
    courses: { id: number; code: string; title: string }[];
    topics: string[];
    filters: Filters;
    canCreate: boolean;
    canModerate: boolean;
}) {
    const [f, setF] = useState<Filters>(filters);
    const [selected, setSelected] = useState<number[]>([]);
    const apply = (next: Filters) => {
        setF(next);
        router.get('/question-bank', Object.fromEntries(Object.entries(next).filter(([, v]) => v)), { preserveState: true, preserveScroll: true });
    };
    const qs = new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString();
    const moderate = (status: string) =>
        router.post('/question-bank/moderate', { ids: selected, status }, { preserveScroll: true, onSuccess: () => setSelected([]) });

    return (
        <>
            <Head title="Question bank" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title={questions.total}
                    accent="questions"
                    lead="Write, import, review and export questions. Edits create a new version; papers already sat keep the version candidates saw."
                    actions={
                        <>
                            <a href={`/question-bank/export?${qs}`} className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm whitespace-nowrap">
                                <Download className="size-4" /> Export (text)
                            </a>
                            <a href={`/question-bank/export?format=xlsx&${qs}`} className="flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm whitespace-nowrap">
                                <FileSpreadsheet className="size-4" /> Excel
                            </a>
                            {canCreate && (
                                <>
                                    <Button asChild variant="outline">
                                        <Link href="/question-bank/import">
                                            <FileUp className="size-4" /> Import
                                        </Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href="/question-bank/questions/create">
                                            <Plus className="size-4" /> New question
                                        </Link>
                                    </Button>
                                </>
                            )}
                        </>
                    }
                />
                <Flash />
                <div className="surface grid gap-2 p-4 md:grid-cols-6">
                    <input className={`${inputClass} md:col-span-2`} placeholder="Search question text" defaultValue={f.q} onKeyDown={(e) => e.key === 'Enter' && apply({ ...f, q: (e.target as HTMLInputElement).value })} aria-label="Search" />
                    <select className={inputClass} value={f.course_id ?? ''} aria-label="Course" onChange={(e) => apply({ ...f, course_id: e.target.value })}>
                        <option value="">All courses</option>
                        {courses.map((c) => (
                            <option key={c.id} value={c.id}>{c.code}</option>
                        ))}
                    </select>
                    <select className={inputClass} value={f.type ?? ''} aria-label="Type" onChange={(e) => apply({ ...f, type: e.target.value })}>
                        <option value="">All types</option>
                        {TYPES.map((t) => (
                            <option key={t} value={t}>{t.replace('_', ' ')}</option>
                        ))}
                    </select>
                    <select className={inputClass} value={f.status ?? ''} aria-label="Status" onChange={(e) => apply({ ...f, status: e.target.value })}>
                        <option value="">Any status</option>
                        {['draft', 'in_review', 'approved', 'retired'].map((t) => (
                            <option key={t} value={t}>{t.replace('_', ' ')}</option>
                        ))}
                    </select>
                    <select className={inputClass} value={f.topic ?? ''} aria-label="Topic" onChange={(e) => apply({ ...f, topic: e.target.value })}>
                        <option value="">All topics</option>
                        {topics.map((t) => (
                            <option key={t} value={t}>{t}</option>
                        ))}
                    </select>
                </div>

                {canModerate && selected.length > 0 && (
                    <div className="surface sticky top-2 z-10 flex flex-wrap items-center gap-2 p-3 text-sm">
                        <span>{selected.length} selected</span>
                        <Button size="sm" onClick={() => moderate('approved')}>Approve</Button>
                        <Button size="sm" variant="outline" onClick={() => moderate('in_review')}>Send back for review</Button>
                        <Button size="sm" variant="outline" onClick={() => moderate('retired')}>Retire</Button>
                        <span className="text-xs text-muted-foreground">You cannot approve questions you wrote — another moderator must.</span>
                    </div>
                )}

                {questions.data.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-10 text-center text-muted-foreground">
                        No questions match. Write one or import a Word document with your questions and equations.
                    </div>
                ) : (
                    <div className="grid gap-4">
                        {questions.data.map((q) => (
                            <div key={q.id} className="relative">
                                <div className="absolute top-5 right-5 z-[1] flex items-center gap-3">
                                    {canModerate && (
                                        <input
                                            type="checkbox"
                                            aria-label={`Select question ${q.id}`}
                                            checked={selected.includes(q.id)}
                                            onChange={() => setSelected(selected.includes(q.id) ? selected.filter((x) => x !== q.id) : [...selected, q.id])}
                                        />
                                    )}
                                    {canCreate && (
                                        <Link href={`/question-bank/questions/${q.id}/edit`} className="text-muted-foreground hover:text-brand" aria-label={`Edit question ${q.id}`}>
                                            <Pencil className="size-4" />
                                        </Link>
                                    )}
                                </div>
                                <QuestionView
                                    number={q.id}
                                    type={q.type}
                                    stemHtml={q.stem_html}
                                    options={q.options}
                                    mark={q.mark}
                                    answerText={answerText(q)}
                                    meta={[q.course, q.topic, q.difficulty, q.outcome, q.status, `v${q.version}`, q.mine ? 'yours' : null]}
                                />
                            </div>
                        ))}
                    </div>
                )}

                {questions.last_page > 1 && (
                    <nav className="flex flex-wrap gap-2">
                        {questions.links.map((l, i) =>
                            l.url ? (
                                <Link
                                    key={i}
                                    href={l.url}
                                    preserveScroll
                                    className={`rounded-lg border px-3 py-1.5 text-sm ${l.active ? 'border-brand bg-brand-soft' : 'border-border'}`}
                                    dangerouslySetInnerHTML={{ __html: l.label }}
                                />
                            ) : null,
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

QuestionBank.layout = { breadcrumbs: [{ title: 'Question bank', href: '/question-bank' }] };
