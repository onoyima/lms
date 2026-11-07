import { Head, Link, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Field, inputClass, PageHeader, sendJson, textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Opt = { html: string; correct: boolean };
type Pair = { left: string; right: string };
type Q = {
    id: number;
    type: string;
    stem_html: string;
    explanation_html: string | null;
    mark: number;
    topic: string | null;
    difficulty: string | null;
    outcome: string | null;
    course_id: number | null;
    status: string;
    version: number;
    options: Opt[];
    numeric: { value: number; tolerance?: number } | null;
    accepted: { answers: string[]; case_sensitive?: boolean } | null;
    pairs: Pair[];
    used: boolean;
};
type Props = {
    question: Q | null;
    courses: { id: number; code: string; title: string; learning_outcomes: string[] | string | null }[];
    topics: string[];
};

const TYPES: [string, string][] = [
    ['single_choice', 'Single answer'],
    ['multiple_choice', 'Multiple answers'],
    ['true_false', 'True / false'],
    ['matching', 'Matching'],
    ['numeric', 'Numeric'],
    ['short_answer', 'Short answer'],
    ['essay', 'Essay / theory'],
];

export default function Editor({ question, courses, topics }: Props) {
    const [type, setType] = useState(question?.type ?? 'single_choice');
    const [stem, setStem] = useState(question?.stem_html ?? '');
    const [explanation, setExplanation] = useState(question?.explanation_html ?? '');
    const [mark, setMark] = useState(question?.mark ?? 1);
    const [topic, setTopic] = useState(question?.topic ?? '');
    const [difficulty, setDifficulty] = useState(question?.difficulty ?? '');
    const [outcome, setOutcome] = useState(question?.outcome ?? '');
    const [courseId, setCourseId] = useState<number | ''>(question?.course_id ?? '');
    const [options, setOptions] = useState<Opt[]>(
        question?.options?.length ? question.options : [{ html: '', correct: true }, { html: '', correct: false }, { html: '', correct: false }, { html: '', correct: false }],
    );
    const [numeric, setNumeric] = useState({ value: question?.numeric?.value ?? '', tolerance: question?.numeric?.tolerance ?? 0 });
    const [accepted, setAccepted] = useState<string>((question?.accepted?.answers ?? []).join('\n'));
    const [caseSensitive, setCaseSensitive] = useState(!!question?.accepted?.case_sensitive);
    const [pairs, setPairs] = useState<Pair[]>(question?.pairs?.length ? question.pairs : [{ left: '', right: '' }, { left: '', right: '' }, { left: '', right: '' }]);
    const [distractors, setDistractors] = useState('');
    const [preview, setPreview] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    const changeType = (t: string) => {
        setType(t);

        if (t === 'true_false') {
            setOptions([{ html: 'True', correct: true }, { html: 'False', correct: false }]);
        }
    };

    const choice = ['single_choice', 'multiple_choice', 'true_false'].includes(type);
    const previewSources = useMemo(() => [stem, ...(choice ? options.map((o) => o.html) : [])], [stem, options, choice]);

    // the preview is cleaned on the server, so pasted HTML is never rendered unsanitised
    useEffect(() => {
        const t = setTimeout(async () => {
            const res = await sendJson<{ html: string[] }>('/question-bank/preview', 'POST', { html: previewSources });

            if (res.ok) {
                setPreview(res.data.html);
            }
        }, 400);

        return () => clearTimeout(t);
    }, [previewSources]);

    const course = courses.find((c) => c.id === courseId);
    const outcomes: string[] = Array.isArray(course?.learning_outcomes)
        ? course.learning_outcomes
        : typeof course?.learning_outcomes === 'string'
          ? course.learning_outcomes.split('\n').filter(Boolean)
          : [];

    const setOpt = (i: number, patch: Partial<Opt>) =>
        setOptions(
            options.map((o, j) =>
                j === i ? { ...o, ...patch } : patch.correct && type !== 'multiple_choice' ? { ...o, correct: false } : o,
            ),
        );

    const save = () => {
        const payload = {
            type,
            stem_html: stem,
            explanation_html: explanation || null,
            mark,
            topic: topic || null,
            difficulty: difficulty || null,
            outcome: outcome || null,
            course_id: courseId || null,
            options: choice ? options.filter((o) => o.html.trim() !== '') : [],
            numeric: type === 'numeric' ? { value: numeric.value, tolerance: numeric.tolerance } : null,
            accepted: type === 'short_answer' ? accepted.split('\n').map((s) => s.trim()).filter(Boolean) : [],
            case_sensitive: caseSensitive,
            pairs: type === 'matching' ? pairs.filter((p) => p.left.trim() && p.right.trim()) : [],
            distractors: type === 'matching' ? distractors.split('\n').map((s) => s.trim()).filter(Boolean) : [],
        };
        const opts = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onError: (e: Record<string, string>) => setErrors(e),
        };

        if (question) {
            router.put(`/question-bank/questions/${question.id}`, payload, opts);
        } else {
            router.post('/question-bank/questions', payload, opts);
        }
    };

    return (
        <>
            <Head title={question ? `Edit question ${question.id}` : 'New question'} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title={question ? `Question ${question.id}` : 'New question'}
                    accent={question ? `v${question.version}` : undefined}
                    lead={
                        question?.used
                            ? 'Candidates have already sat this question. Saving creates a new version for future papers; past scripts keep the version they saw. An approved question goes back for review.'
                            : 'Write maths and chemistry in LaTeX: \\( x^2 \\), \\[ \\frac{a}{b} \\], \\ce{H2SO4}. The preview on the right shows exactly what candidates will see.'
                    }
                />
                <div className="grid gap-6 lg:grid-cols-2">
                    <div className="surface grid content-start gap-4 p-6">
                        <div className="grid gap-3 md:grid-cols-3">
                            <Field label="Type">
                                <select className={inputClass} value={type} onChange={(e) => changeType(e.target.value)}>
                                    {TYPES.map(([v, l]) => (
                                        <option key={v} value={v}>{l}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Mark" error={errors.mark}>
                                <input type="number" step="0.5" min={0} className={inputClass} value={mark} onChange={(e) => setMark(Number(e.target.value))} />
                            </Field>
                            <Field label="Difficulty">
                                <select className={inputClass} value={difficulty} onChange={(e) => setDifficulty(e.target.value)}>
                                    <option value="">—</option>
                                    <option value="easy">Easy</option>
                                    <option value="medium">Medium</option>
                                    <option value="hard">Hard</option>
                                </select>
                            </Field>
                            <Field label="Course">
                                <select className={inputClass} value={courseId} onChange={(e) => setCourseId(e.target.value ? Number(e.target.value) : '')}>
                                    <option value="">—</option>
                                    {courses.map((c) => (
                                        <option key={c.id} value={c.id}>{c.code}</option>
                                    ))}
                                </select>
                            </Field>
                            <Field label="Topic">
                                <input className={inputClass} list="topics" value={topic} onChange={(e) => setTopic(e.target.value)} />
                                <datalist id="topics">
                                    {topics.map((t) => (
                                        <option key={t} value={t} />
                                    ))}
                                </datalist>
                            </Field>
                            <Field label="Learning outcome">
                                <input className={inputClass} list="outcomes" value={outcome} onChange={(e) => setOutcome(e.target.value)} />
                                <datalist id="outcomes">
                                    {outcomes.map((t) => (
                                        <option key={t} value={t} />
                                    ))}
                                </datalist>
                            </Field>
                        </div>
                        <Field label="Question" error={errors.stem_html}>
                            <textarea className={`${textareaClass} min-h-40 font-mono`} value={stem} onChange={(e) => setStem(e.target.value)} />
                        </Field>

                        {choice && (
                            <div className="grid gap-2">
                                <p className="eyebrow">Options — tick the correct {type === 'multiple_choice' ? 'ones' : 'one'}</p>
                                {options.map((o, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <input
                                            type={type === 'multiple_choice' ? 'checkbox' : 'radio'}
                                            name="correct"
                                            checked={o.correct}
                                            onChange={(e) => setOpt(i, { correct: e.target.checked })}
                                            aria-label={`Option ${String.fromCharCode(65 + i)} is correct`}
                                        />
                                        <span className="w-5 font-mono text-sm">{String.fromCharCode(65 + i)}</span>
                                        <input className={inputClass} value={o.html} disabled={type === 'true_false'} onChange={(e) => setOpt(i, { html: e.target.value })} />
                                        {type !== 'true_false' && options.length > 2 && (
                                            <button type="button" onClick={() => setOptions(options.filter((_, j) => j !== i))} aria-label="Remove option" className="text-muted-foreground">
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </div>
                                ))}
                                {type !== 'true_false' && options.length < 10 && (
                                    <Button type="button" size="sm" variant="outline" className="w-fit" onClick={() => setOptions([...options, { html: '', correct: false }])}>
                                        <Plus className="size-4" /> Option
                                    </Button>
                                )}
                            </div>
                        )}

                        {type === 'matching' && (
                            <div className="grid gap-2">
                                <p className="eyebrow">Pairs — candidates match each left item to a right item (the right side is shuffled)</p>
                                {pairs.map((p, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        <input className={inputClass} placeholder="Item" value={p.left} onChange={(e) => setPairs(pairs.map((x, j) => (j === i ? { ...x, left: e.target.value } : x)))} />
                                        <span className="text-muted-foreground">→</span>
                                        <input className={inputClass} placeholder="Match" value={p.right} onChange={(e) => setPairs(pairs.map((x, j) => (j === i ? { ...x, right: e.target.value } : x)))} />
                                        {pairs.length > 2 && (
                                            <button type="button" onClick={() => setPairs(pairs.filter((_, j) => j !== i))} aria-label="Remove pair" className="text-muted-foreground">
                                                <Trash2 className="size-4" />
                                            </button>
                                        )}
                                    </div>
                                ))}
                                <Button type="button" size="sm" variant="outline" className="w-fit" onClick={() => setPairs([...pairs, { left: '', right: '' }])}>
                                    <Plus className="size-4" /> Pair
                                </Button>
                                <Field label="Extra wrong matches (one per line, optional)">
                                    <textarea className={textareaClass} value={distractors} onChange={(e) => setDistractors(e.target.value)} />
                                </Field>
                            </div>
                        )}

                        {type === 'numeric' && (
                            <div className="grid grid-cols-2 gap-3">
                                <Field label="Correct value" error={errors['numeric.value']}>
                                    <input type="number" step="any" className={inputClass} value={numeric.value} onChange={(e) => setNumeric({ ...numeric, value: e.target.value })} />
                                </Field>
                                <Field label="Tolerance (±)">
                                    <input type="number" step="any" min={0} className={inputClass} value={numeric.tolerance} onChange={(e) => setNumeric({ ...numeric, tolerance: Number(e.target.value) })} />
                                </Field>
                            </div>
                        )}

                        {type === 'short_answer' && (
                            <div className="grid gap-2">
                                <Field label="Accepted answers (one per line). Leave empty to mark by hand.">
                                    <textarea className={textareaClass} value={accepted} onChange={(e) => setAccepted(e.target.value)} />
                                </Field>
                                <label className="flex items-center gap-2 text-sm">
                                    <input type="checkbox" checked={caseSensitive} onChange={(e) => setCaseSensitive(e.target.checked)} /> Capital letters must match
                                </label>
                            </div>
                        )}

                        <Field label="Explanation or marking guide (shown to markers, and to candidates only after results are released)">
                            <textarea className={`${textareaClass} font-mono`} value={explanation} onChange={(e) => setExplanation(e.target.value)} />
                        </Field>

                        {Object.keys(errors).length > 0 && <p className="text-sm text-status-critical">{Object.values(errors).join(' ')}</p>}
                        <div className="flex gap-2">
                            <Button onClick={save} disabled={saving}>{question ? 'Save new version' : 'Save draft'}</Button>
                            <Button asChild variant="outline">
                                <Link href="/question-bank">Cancel</Link>
                            </Button>
                        </div>
                    </div>

                    <div className="surface sticky top-4 grid content-start gap-4 self-start p-6">
                        <p className="eyebrow">Candidate preview</p>
                        <RichContent html={preview[0] ?? ''} className="prose prose-sm max-w-none dark:prose-invert" />
                        {choice && (
                            <ul className="grid gap-2">
                                {options.map((o, i) => (
                                    <li key={i} className="flex gap-3 rounded-xl border border-border p-3 text-sm">
                                        <span className="font-mono">{String.fromCharCode(65 + i)}</span>
                                        <RichContent html={preview[i + 1] ?? ''} as="span" />
                                    </li>
                                ))}
                            </ul>
                        )}
                        {type === 'matching' && (
                            <ul className="grid gap-1 text-sm">
                                {pairs.filter((p) => p.left).map((p, i) => (
                                    <li key={i}>{i + 1}. {p.left} → <span className="text-muted-foreground">[choose]</span></li>
                                ))}
                            </ul>
                        )}
                        {type === 'numeric' && <input disabled className={inputClass} placeholder="Candidate types a number" />}
                        {type === 'short_answer' && <input disabled className={inputClass} placeholder="Candidate types a short answer" />}
                        {type === 'essay' && <textarea disabled className={textareaClass} placeholder="Candidate writes an answer" />}
                    </div>
                </div>
            </div>
        </>
    );
}

Editor.layout = { breadcrumbs: [{ title: 'Question bank', href: '/question-bank' }, { title: 'Editor', href: '#' }] };
