import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Film, ImagePlus, Music, Plus, Trash2 } from 'lucide-react';
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
    blanks?: Blank[];
    file?: { kinds: string[]; max_mb: number } | null;
    passage_id?: number | null;
};
type Blank = { answers: string[]; case_sensitive?: boolean; value?: number | null; tolerance?: number | null };
type Props = {
    question: Q | null;
    courses: { id: number; code: string; title: string; learning_outcomes: string[] | string | null }[];
    topics: string[];
    passages?: { id: number; title: string; course_id: number | null }[];
    fileKinds?: string[];
};

const TYPES: [string, string][] = [
    ['single_choice', 'Single answer'],
    ['multiple_choice', 'Multiple answers'],
    ['true_false', 'True / false'],
    ['multi_true_false', 'Multiple true / false'],
    ['matching', 'Matching'],
    ['ordering', 'Put in order'],
    ['fill_blank', 'Fill in the blanks'],
    ['numeric', 'Numeric'],
    ['short_answer', 'Short answer'],
    ['essay', 'Essay / theory'],
    ['file_upload', 'File upload (drawing, report, workings)'],
];
const KIND_LABELS: Record<string, string> = { image: 'Pictures / photos', pdf: 'PDF', document: 'Word / text', spreadsheet: 'Spreadsheets', slides: 'Slides', code: 'Code / zip' };

export default function Editor({ question, courses, topics, passages: initialPassages = [], fileKinds = [] }: Props) {
    const [blanks, setBlanks] = useState<Blank[]>(question?.blanks?.length ? question.blanks : []);
    const [fileRule, setFileRule] = useState(question?.file ?? { kinds: ['image', 'pdf', 'document'], max_mb: 10 });
    const [passageId, setPassageId] = useState<number | ''>(question?.passage_id ?? '');
    const [passages, setPassages] = useState(initialPassages);
    const [newPassage, setNewPassage] = useState<{ title: string; body: string } | null>(null);
    const [uploading, setUploading] = useState(false);
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

        if (t === 'fill_blank') {
            const n = new Set(Array.from(stem.matchAll(/\[\[(\d{1,2})\]\]/g)).map((m) => m[1])).size;
            setBlanks((b) => Array.from({ length: n }, (_, i) => b[i] ?? { answers: [''] }));
        }

        if (t === 'true_false') {
            setOptions([{ html: 'True', correct: true }, { html: 'False', correct: false }]);
        } else if (type === 'true_false') {
            setOptions([{ html: '', correct: true }, { html: '', correct: false }, { html: '', correct: false }, { html: '', correct: false }]);
        }
    };

    const choice = ['single_choice', 'multiple_choice', 'true_false', 'multi_true_false', 'ordering'].includes(type);
    const mtf = type === 'multi_true_false';
    const ordering = type === 'ordering';
    const blankCount = blanks.length;

    // one answer box per [[n]] in the text
    const writeStem = (text: string) => {
        setStem(text);
        const n = new Set(Array.from(text.matchAll(/\[\[(\d{1,2})\]\]/g)).map((m) => m[1])).size;
        setBlanks((b) => (b.length === n ? b : Array.from({ length: n }, (_, i) => b[i] ?? { answers: [''] })));
    };

    const addBlank = () => writeStem(`${stem}${stem.endsWith(' ') || stem === '' ? '' : ' '}[[${blankCount + 1}]] `);
    const moveOption = (i: number, d: -1 | 1) => {
        const j = i + d;

        if (j < 0 || j >= options.length) {
            return;
        }

        const next = [...options];
        [next[i], next[j]] = [next[j], next[i]];
        setOptions(next);
    };
    const insertMedia = async (file: File | undefined) => {
        if (!file) {
            return;
        }

        setUploading(true);
        const fd = new FormData();
        fd.append('file', file);
        const xsrf = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const res = await fetch('/question-bank/media', { method: 'POST', body: fd, credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf } }).catch(() => null);
        setUploading(false);

        if (res?.ok) {
            const data = (await res.json()) as { html: string };
            setStem(`${stem}\n${data.html}\n`);
        } else {
            setErrors({ media: 'That file could not be added (pictures, sound or video only, up to 200 MB).' });
        }
    };
    const savePassage = async () => {
        if (!newPassage?.title || !newPassage.body) {
            return;
        }

        const res = await sendJson<{ id: number; title: string }>('/question-bank/passages', 'POST', { title: newPassage.title, body_html: newPassage.body, course_id: courseId || null });

        if (res.ok) {
            setPassages([res.data as { id: number; title: string; course_id: number | null }, ...passages]);
            setPassageId(res.data.id);
            setNewPassage(null);
        }
    };
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
                j === i ? { ...o, ...patch } : patch.correct && !['multiple_choice', 'multi_true_false'].includes(type) ? { ...o, correct: false } : o,
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
            blanks: type === 'fill_blank' ? blanks.map((b) => ({ ...b, answers: b.answers.map((a) => a.trim()).filter(Boolean) })) : [],
            file: type === 'file_upload' ? fileRule : null,
            passage_id: passageId || null,
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
                        <Field label="Shared passage or case study (optional)" hint="Questions on the same passage are shown with it and kept together.">
                            <div className="flex flex-wrap gap-2">
                                <select className={`${inputClass} min-w-0 flex-1`} value={passageId} onChange={(e) => setPassageId(e.target.value ? Number(e.target.value) : '')}>
                                    <option value="">None</option>
                                    {passages.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                                </select>
                                <Button type="button" variant="outline" size="sm" onClick={() => setNewPassage(newPassage ? null : { title: '', body: '' })}><Plus className="size-4" /> New passage</Button>
                            </div>
                        </Field>
                        {newPassage && (
                            <div className="grid gap-2 rounded-xl border border-border p-3">
                                <input className={inputClass} placeholder="Title, e.g. Case study: Mrs A, 64" value={newPassage.title} onChange={(e) => setNewPassage({ ...newPassage, title: e.target.value })} />
                                <textarea className={`${textareaClass} min-h-28`} placeholder="The passage, case, data or scenario (HTML and LaTeX allowed)" value={newPassage.body} onChange={(e) => setNewPassage({ ...newPassage, body: e.target.value })} />
                                <Button type="button" size="sm" className="w-fit" onClick={() => void savePassage()} disabled={!newPassage.title || !newPassage.body}>Save passage</Button>
                            </div>
                        )}
                        <Field label={type === 'fill_blank' ? 'Question — put [[1]], [[2]]… where each blank goes' : 'Question'} error={errors.stem_html}>
                            <textarea className={`${textareaClass} min-h-40 font-mono`} value={stem} onChange={(e) => writeStem(e.target.value)} />
                        </Field>
                        <div className="-mt-2 flex flex-wrap items-center gap-2">
                            <label className="inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-border px-2.5 py-1.5 text-xs font-medium hover:border-brand">
                                <ImagePlus className="size-3.5" /><Music className="size-3.5" /><Film className="size-3.5" /> {uploading ? 'Adding…' : 'Add a picture, sound or video'}
                                <input type="file" className="sr-only" accept="image/*,audio/*,video/*" onChange={(e) => void insertMedia(e.target.files?.[0])} />
                            </label>
                            {type === 'fill_blank' && <Button type="button" size="sm" variant="outline" onClick={addBlank}><Plus className="size-4" /> Blank</Button>}
                            {errors.media && <span className="text-xs text-status-critical">{errors.media}</span>}
                        </div>

                        {type === 'fill_blank' && (
                            <div className="grid gap-2">
                                <p className="eyebrow">Accepted answers for each blank — several separated by |, or a number with a tolerance</p>
                                {blanks.length === 0 && <p className="text-sm text-muted-foreground">Add a blank to the text first.</p>}
                                {blanks.map((b, i) => (
                                    <div key={i} className="grid gap-2 rounded-xl border border-border p-3 sm:grid-cols-[auto_1fr_auto]">
                                        <span className="font-mono text-sm font-semibold">[[{i + 1}]]</span>
                                        <input className={inputClass} placeholder="e.g. mitochondria | mitochondrion" value={b.answers.join(' | ')} onChange={(e) => setBlanks(blanks.map((x, j) => (j === i ? { ...x, answers: e.target.value.split('|').map((a) => a.trimStart()) } : x)))} />
                                        <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" checked={!!b.case_sensitive} onChange={(e) => setBlanks(blanks.map((x, j) => (j === i ? { ...x, case_sensitive: e.target.checked } : x)))} /> Exact capitals</label>
                                        <div className="grid grid-cols-2 gap-2 sm:col-span-3">
                                            <input type="number" step="any" className={inputClass} placeholder="or a number" value={b.value ?? ''} onChange={(e) => setBlanks(blanks.map((x, j) => (j === i ? { ...x, value: e.target.value === '' ? null : Number(e.target.value) } : x)))} />
                                            <input type="number" step="any" min={0} className={inputClass} placeholder="± tolerance" value={b.tolerance ?? ''} onChange={(e) => setBlanks(blanks.map((x, j) => (j === i ? { ...x, tolerance: e.target.value === '' ? null : Number(e.target.value) } : x)))} />
                                        </div>
                                    </div>
                                ))}
                                {errors.blanks && <p className="text-sm text-status-critical">{errors.blanks}</p>}
                            </div>
                        )}

                        {type === 'file_upload' && (
                            <div className="grid gap-3 rounded-xl border border-border p-3">
                                <p className="eyebrow">What the candidate may hand in</p>
                                <div className="flex flex-wrap gap-3">
                                    {fileKinds.map((k) => (
                                        <label key={k} className="flex items-center gap-1.5 text-sm">
                                            <input type="checkbox" checked={fileRule.kinds.includes(k)} onChange={(e) => setFileRule({ ...fileRule, kinds: e.target.checked ? [...fileRule.kinds, k] : fileRule.kinds.filter((x) => x !== k) })} /> {KIND_LABELS[k] ?? k}
                                        </label>
                                    ))}
                                </div>
                                <Field label="Largest file (MB)"><input type="number" min={1} max={100} className={`${inputClass} !w-28`} value={fileRule.max_mb} onChange={(e) => setFileRule({ ...fileRule, max_mb: Number(e.target.value) })} /></Field>
                                <p className="text-xs text-muted-foreground">Marked by an examiner, who opens the file from the marking page.</p>
                            </div>
                        )}

                        {choice && (
                            <div className="grid gap-2">
                                <p className="eyebrow">{ordering ? 'Items — in the right order (candidates see them mixed up)' : mtf ? 'Statements — set each one True or False' : `Options — tick the correct ${type === 'multiple_choice' ? 'ones' : 'one'}`}</p>
                                {options.map((o, i) => (
                                    <div key={i} className="flex items-center gap-2">
                                        {ordering ? (
                                            <span className="flex flex-col">
                                                <button type="button" aria-label="Move up" onClick={() => moveOption(i, -1)} className="text-muted-foreground hover:text-foreground"><ArrowUp className="size-3.5" /></button>
                                                <button type="button" aria-label="Move down" onClick={() => moveOption(i, 1)} className="text-muted-foreground hover:text-foreground"><ArrowDown className="size-3.5" /></button>
                                            </span>
                                        ) : mtf ? (
                                            <span className="flex overflow-hidden rounded-lg border border-border text-xs font-semibold" role="radiogroup" aria-label={`Statement ${String.fromCharCode(65 + i)} is`}>
                                                {[true, false].map((v) => (
                                                    <button key={String(v)} type="button" role="radio" aria-checked={o.correct === v} onClick={() => setOpt(i, { correct: v })}
                                                        className={`px-2.5 py-1.5 ${o.correct === v ? (v ? 'bg-status-good text-white' : 'bg-status-critical text-white') : 'text-muted-foreground hover:bg-muted'}`}>{v ? 'True' : 'False'}</button>
                                                ))}
                                            </span>
                                        ) : <input
                                            type={type === 'multiple_choice' ? 'checkbox' : 'radio'}
                                            name="correct"
                                            checked={o.correct}
                                            onChange={(e) => setOpt(i, { correct: e.target.checked })}
                                            aria-label={`Option ${String.fromCharCode(65 + i)} is correct`}
                                        />}
                                        <span className="w-5 font-mono text-sm">{ordering ? i + 1 : String.fromCharCode(65 + i)}</span>
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
                                        <Plus className="size-4" /> {ordering ? 'Item' : mtf ? 'Statement' : 'Option'}
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
                        {type === 'fill_blank' && <p className="text-sm text-muted-foreground">Each [[n]] becomes an answer box.</p>}
                        {type === 'file_upload' && <p className="text-sm text-muted-foreground">The candidate chooses a file to hand in.</p>}
                    </div>
                </div>
            </div>
        </>
    );
}

Editor.layout = { breadcrumbs: [{ title: 'Question bank', href: '/question-bank' }, { title: 'Editor', href: '#' }] };
