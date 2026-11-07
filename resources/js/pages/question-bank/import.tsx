import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, FileText, Upload } from 'lucide-react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

type Recent = { id: number; original_name: string; status: string; question_count: number; created_at: string };

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
    recent,
}: {
    courses: { id: number; code: string; title: string }[];
    pandoc: boolean;
    recent: Recent[];
}) {
    const form = useForm<{ file: File | null; course_id: string; reader: 'standard' | 'science' }>({ file: null, course_id: '', reader: pandoc ? 'standard' : 'science' });

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
                            form.post('/question-bank/import', { forceFormData: true });
                        }}
                    >
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
                        <Button disabled={!form.data.file || form.processing}>{form.processing ? 'Reading document…' : 'Upload and preview'}</Button>
                    </form>

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
