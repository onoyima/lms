import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, FileText, Images, Sparkles, Upload } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Recent = { id: number; original_name: string; status: string; question_count: number; created_at: string };

const pdfPages = async (file: File, progress: (done: number, total: number) => void) => (await import('@/lib/pdf-pages')).pdfToPages(file, progress);

const template = `1. Which equation is balanced?
A. $\\ce{H2 + O2 -> H2O}$
*B. $\\ce{2H2 + O2 -> 2H2O}$
C. $\\ce{H2 + 2O2 -> H2O}$
D. $\\ce{2H2 + 2O2 -> H2O}$
Topic: Stoichiometry

2. A 2 kg body moves at 3 m/s. Its kinetic energy $E_k = \\frac{1}{2}mv^2$ is:
A. 3 J
B. 6 J
C. 9 J
D. 18 J
Answer: C
Mark: 2

3. Explain why $\\ce{NaCl}$ conducts electricity when molten.`;

export default function ImportQuestions({
    courses,
    pandoc,
    ai,
    aiWrite = false,
    recent,
}: {
    courses: { id: number; code: string; title: string }[];
    pandoc: boolean;
    ai: boolean;
    aiWrite?: boolean;
    recent: Recent[];
}) {
    const form = useForm<{ file: File | null; course_id: string; reader: 'standard' | 'science' | 'ai' }>({ file: null, course_id: '', reader: ai ? 'ai' : pandoc ? 'standard' : 'science' });
    const [files, setFiles] = useState<File[]>([]);
    const [busy, setBusy] = useState<string | null>(null);
    const [aiError, setAiError] = useState<string | null>(null);
    const isAi = form.data.reader === 'ai';

    /** AI: PDFs become page pictures here in the browser; pictures go as they are; Word goes to the server. */
    const sendToAi = async () => {
        setAiError(null);
        const word = files.find((f) => /\.docx$/i.test(f.name));
        const pages: File[] = [];

        try {
            for (const f of files) {
                if (/\.pdf$/i.test(f.name) || f.type === 'application/pdf') {
                    setBusy(`Preparing ${f.name}…`);
                    pages.push(...(await pdfPages(f, (d, t) => setBusy(`Preparing ${f.name}: page ${d} of ${t}`))));
                } else if (f.type.startsWith('image/')) {
                    pages.push(f);
                }
            }
        } catch {
            setBusy(null);
            setAiError('That PDF could not be opened. If it is protected with a password, remove the password first.');

            return;
        }

        if (!word && pages.length === 0) {
            setAiError('Choose a PDF, photos or scans of the pages, or a Word file.');

            return;
        }

        setBusy(`Sending ${word ? 'the Word file' : `${pages.length} page${pages.length === 1 ? '' : 's'}`}…`);
        router.post('/question-bank/import', {
            reader: 'ai', course_id: form.data.course_id, name: files.map((f) => f.name).join(', ').slice(0, 240),
            ...(word ? { file: word } : { pages }),
        } as never, { forceFormData: true, onError: (e) => setAiError(Object.values(e)[0] ?? 'Upload failed.'), onFinish: () => setBusy(null) });
    };

    return (
        <>
            <Head title="Import questions" />
            <div className="grid gap-10 p-6 md:p-10 xl:grid-cols-[1fr_420px]">
                <div className="max-w-2xl space-y-8">
                    <header>
                        <h1 className="text-[1.75rem] leading-tight font-semibold">
                            Import questions with their equations.
                        </h1>
                        <p className="mt-3 text-muted-foreground">
                            Upload a Word document (.docx) written with Word's equation editor, or a text file using LaTeX. Chemistry works
                            with <code className="font-mono text-sm">{'\\ce{…}'}</code>. You will see every question exactly as students will before
                            anything is saved.
                        </p>
                    </header>

                    {!pandoc && form.data.reader === 'standard' && (
                        <div className="flex gap-3 rounded-lg border border-status-warning/40 bg-status-warning/10 p-4 text-sm">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warning" />
                            <p>
                                The standard Word reader needs <strong>Pandoc</strong> on the server. Choose <strong>Science & maths</strong> below instead — it reads Word files on its own.
                            </p>
                        </div>
                    )}

                    <form
                        className="surface space-y-6 p-6"
                        onSubmit={(e) => {
                            e.preventDefault();

                            if (isAi) {
                                void sendToAi();
                            } else {
                                form.post('/question-bank/import', { forceFormData: true });
                            }
                        }}
                    >
                        {isAi ? (
                            <div className="grid gap-2">
                                <Label htmlFor="ai-files">Question paper</Label>
                                <label htmlFor="ai-files" className="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed border-border p-8 text-center hover:border-brand/50">
                                    <Images className="size-6 text-brand" />
                                    <span className="text-sm">{files.length ? files.map((f) => f.name).join(', ') : 'Choose a PDF, photos or scans of the pages, or a Word file'}</span>
                                    <span className="text-xs text-muted-foreground">Several photos at once are fine, in page order. Up to 60 pages.</span>
                                </label>
                                <input id="ai-files" type="file" multiple className="sr-only" accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,image/*,application/pdf"
                                    onChange={(e) => setFiles(Array.from(e.target.files ?? []).sort((a, b) => a.name.localeCompare(b.name, undefined, { numeric: true })))} />
                                {aiError && <InputError message={aiError} />}
                            </div>
                        ) : (
                        <div className="grid gap-2">
                            <Label htmlFor="file">Document</Label>
                            <label
                                htmlFor="file"
                                className="flex cursor-pointer flex-col items-center gap-2 rounded-lg border border-dashed border-border p-8 text-center hover:border-brand/50"
                            >
                                <Upload className="size-6 text-brand" />
                                <span className="text-sm">{form.data.file ? form.data.file.name : 'Choose a .docx, .txt or .md file'}</span>
                                <span className="text-xs text-muted-foreground">Up to 20 MB</span>
                            </label>
                            <input
                                id="file"
                                type="file"
                                className="sr-only"
                                accept=".docx,.txt,.md,.html"
                                onChange={(e) => form.setData('file', e.target.files?.[0] ?? null)}
                            />
                            <span className="text-xs text-muted-foreground">Samples: <a className="text-brand underline" href="/templates/questions_docx">Word</a> · <a className="text-brand underline" href="/templates/questions_text">text</a></span>
                            <InputError message={form.errors.file} />
                        </div>
                        )}
                        <div className="grid gap-2">
                            <Label htmlFor="course">Course (optional)</Label>
                            <select
                                id="course"
                                className="h-10 rounded-xl border border-input bg-background px-3 text-sm"
                                value={form.data.course_id}
                                onChange={(e) => form.setData('course_id', e.target.value)}
                            >
                                <option value="">No course yet</option>
                                {courses.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.code} — {c.title}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.course_id} />
                        </div>
                        <fieldset className="grid gap-2">
                            <legend className="text-sm font-medium">How to read the file</legend>
                            <label className={`flex items-start gap-2 rounded-lg border border-border p-3 text-sm has-[:checked]:border-brand ${ai ? '' : 'opacity-60'}`}>
                                <input type="radio" className="mt-1" name="reader" disabled={!ai} checked={isAi} onChange={() => form.setData('reader', 'ai')} />
                                <span>
                                    <strong className="inline-flex items-center gap-1"><Sparkles className="size-3.5 text-brand" /> Read with AI</strong> — any layout: a PDF, photos or scans of a printed paper, or a Word file. Reads maths, physics and chemistry notation, tables and diagrams (cut out and placed with their question), options and the answer key when the paper shows it. You check every question before anything is saved.
                                    {!ai && <span className="mt-1 block text-xs">Not set up yet: an administrator can add an AI provider under Settings › AI.</span>}
                                </span>
                            </label>
                            <label className="flex items-start gap-2 rounded-lg border border-border p-3 text-sm has-[:checked]:border-brand">
                                <input type="radio" className="mt-1" name="reader" checked={form.data.reader === 'science'} onChange={() => form.setData('reader', 'science')} />
                                <span>
                                    <strong>Science & maths</strong> — for papers with equations, chemistry and physics symbols. Keeps Word equations (Insert › Equation), Greek letters and symbols from Insert › Symbol, superscripts and subscripts (x², H₂SO₄, Fe³⁺), tables and pictures. Works without Pandoc.
                                </span>
                            </label>
                            <label className="flex items-start gap-2 rounded-lg border border-border p-3 text-sm has-[:checked]:border-brand">
                                <input type="radio" className="mt-1" name="reader" checked={form.data.reader === 'standard'} onChange={() => form.setData('reader', 'standard')} />
                                <span><strong>Standard</strong> — the usual reader{pandoc ? '' : ' (Word files need Pandoc on the server)'}.</span>
                            </label>
                        </fieldset>
                        {isAi ? (
                            <Button disabled={!files.length || !!busy}>{busy ?? 'Read with AI'}</Button>
                        ) : (
                            <Button disabled={!form.data.file || form.processing}>{form.processing ? 'Reading document…' : 'Upload and preview'}</Button>
                        )}
                    </form>

                    {aiWrite && <WriteWithAi courses={courses} />}

                    {recent.length > 0 && (
                        <section>
                            <p className="eyebrow mb-3">Recent imports</p>
                            <ul className="divide-y divide-border rounded-lg border border-border">
                                {recent.map((r) => (
                                    <li key={r.id}>
                                        <Link href={`/question-bank/imports/${r.id}`} className="flex items-center justify-between gap-4 p-4 text-sm hover:bg-muted/50">
                                            <span className="flex items-center gap-2">
                                                <FileText className="size-4 text-muted-foreground" />
                                                {r.original_name}
                                            </span>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {r.question_count} · {r.status}
                                            </span>
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    )}
                </div>

                <aside className="space-y-3">
                    <p className="eyebrow">How to write the document</p>
                    <pre className="surface overflow-x-auto p-5 font-mono text-[12.5px] leading-relaxed whitespace-pre-wrap">{template}</pre>
                    <ul className="space-y-1.5 text-sm text-muted-foreground">
                        <li>
                            Number each question: <span className="font-mono">1.</span> or <span className="font-mono">Q1.</span>
                        </li>
                        <li>
                            Letter each option: <span className="font-mono">A.</span> or <span className="font-mono">(a)</span>
                        </li>
                        <li>
                            Mark the answer with <span className="font-mono">*</span> or an <span className="font-mono">Answer:</span> line
                        </li>
                        <li>Optional lines: Mark, Topic, Difficulty, Type, Explanation</li>
                        <li>No options means an essay question; a number answer means numeric</li>
                        <li>In Word, use Insert → Equation; it imports as real maths</li>
                    </ul>
                </aside>
            </div>
        </>
    );
}

ImportQuestions.layout = {
    breadcrumbs: [
        { title: 'Question bank', href: '/question-bank' },
        { title: 'Import', href: '/question-bank/import' },
    ],
};

const WRITE_TYPES: [string, string][] = [
    ['single_choice', 'Single answer'], ['multiple_choice', 'Multiple answers'], ['true_false', 'True / false'], ['multi_true_false', 'Multiple true / false'],
    ['numeric', 'Numeric'], ['short_answer', 'Short answer'], ['essay', 'Essay / theory'],
];

/** AI drafts new questions on a topic (optionally only from the lecturer's notes); they are reviewed like an upload. */
function WriteWithAi({ courses }: { courses: { id: number; code: string; title: string }[] }) {
    const form = useForm({ topic: '', course_id: '', types: ['single_choice'] as string[], count: 10, difficulty: 'mixed', level: '', instructions: '', notes: '' });
    const field = 'h-10 rounded-xl border border-input bg-background px-3 text-sm';

    return (
        <form className="surface grid gap-4 p-6" onSubmit={(e) => (e.preventDefault(), form.post('/question-bank/write'))}>
            <div>
                <h2 className="flex items-center gap-2 text-lg font-semibold"><Sparkles className="size-4 text-brand" /> Write questions with AI</h2>
                <p className="text-sm text-muted-foreground">Drafts with answers and explanations, for you to check. Paste your lecture notes to keep the questions to what was taught.</p>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <label className="grid gap-1 text-sm sm:col-span-2">Topic<input className={field} value={form.data.topic} onChange={(e) => form.setData('topic', e.target.value)} placeholder="e.g. Acid–base equilibria and buffer calculations" required /></label>
                <label className="grid gap-1 text-sm">Course
                    <select className={field} value={form.data.course_id} onChange={(e) => form.setData('course_id', e.target.value)}>
                        <option value="">No course</option>
                        {courses.map((c) => <option key={c.id} value={c.id}>{c.code} — {c.title}</option>)}
                    </select>
                </label>
                <div className="grid grid-cols-2 gap-3">
                    <label className="grid gap-1 text-sm">How many<input type="number" min={1} max={40} className={field} value={form.data.count} onChange={(e) => form.setData('count', Number(e.target.value))} /></label>
                    <label className="grid gap-1 text-sm">Difficulty
                        <select className={field} value={form.data.difficulty} onChange={(e) => form.setData('difficulty', e.target.value)}>
                            {['mixed', 'easy', 'medium', 'hard'].map((d) => <option key={d} value={d}>{d}</option>)}
                        </select>
                    </label>
                </div>
            </div>
            <fieldset className="flex flex-wrap gap-2">
                <legend className="mb-1 text-sm">Types</legend>
                {WRITE_TYPES.map(([v, l]) => {
                    const on = form.data.types.includes(v);

                    return (
                        <button key={v} type="button" onClick={() => form.setData('types', on ? form.data.types.filter((t) => t !== v) : [...form.data.types, v])}
                            className={`rounded-full border px-3 py-1 text-sm ${on ? 'border-brand bg-brand-soft font-semibold' : 'border-border text-muted-foreground'}`} aria-pressed={on}>{l}</button>
                    );
                })}
            </fieldset>
            <label className="grid gap-1 text-sm">Instructions (optional)<input className={field} value={form.data.instructions} onChange={(e) => form.setData('instructions', e.target.value)} placeholder="e.g. 200-level, include two calculation questions" /></label>
            <label className="grid gap-1 text-sm">Lecture notes (optional)
                <textarea className="min-h-28 rounded-xl border border-input bg-background p-3 text-sm" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="Paste the notes the questions must come from" />
            </label>
            {Object.values(form.errors)[0] && <InputError message={Object.values(form.errors)[0]} />}
            <Button className="w-fit" disabled={form.processing || !form.data.topic || form.data.types.length === 0}><Sparkles className="size-4" /> Write {form.data.count} questions</Button>
        </form>
    );
}
