import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2, Loader2, RotateCcw, Sparkles } from 'lucide-react';
import { useEffect } from 'react';
import { TemplateLinks } from '@/components/exam/question-upload';
import { QuestionView } from '@/components/question/question-view';
import { Button } from '@/components/ui/button';
import type { ParsedQuestion } from '@/types';

type ImportInfo = { id: number; original_name: string; status: string; question_count: number; warnings: string[] | null; format: string };
type Destination = { bank: string | null; exam: { id: number; title: string } | null; section: string | null; pictures: number };

const TYPE_NAMES: Record<string, string> = {
    single_choice: 'single answer', multiple_choice: 'multiple answers', true_false: 'true / false', multi_true_false: 'multiple true / false', matching: 'matching',
    ordering: 'put in order', fill_blank: 'fill in the blanks', numeric: 'numeric', short_answer: 'short answer', essay: 'essay', file_upload: 'file upload',
};

export default function ImportPreview({
    import: info,
    questions,
    documentWarnings,
    commitUrl,
    commitLabel,
    backUrl,
    pages = 0,
    destination,
}: {
    import: ImportInfo;
    questions: ParsedQuestion[];
    documentWarnings: string[];
    commitUrl?: string;
    commitLabel?: string;
    backUrl?: string;
    pages?: number;
    destination?: Destination;
}) {
    const typeCounts = Object.entries(questions.reduce<Record<string, number>>((m, q) => ((m[q.type] = (m[q.type] ?? 0) + 1), m), {}));
    const where = destination?.bank ? `the bank "${destination.bank}"${destination.section ? ` and ${destination.section} of the paper` : ''}` : null;
    const label = commitLabel ?? (where ? `Save to ${where}` : undefined);
    const back = backUrl ?? (destination?.exam ? `/exams/${destination.exam.id}?tab=banks` : undefined);
    const withWarnings = questions.filter((q) => q.warnings.length > 0).length;
    const failed = info.status === 'failed';
    const reading = info.status === 'reading';
    const ai = info.format.startsWith('ai-');

    // while the AI reads the paper, look again every few seconds
    useEffect(() => {
        if (!reading) {
            return;
        }

        const t = window.setInterval(() => router.reload({ only: ['import', 'questions', 'documentWarnings', 'pages'] }), 4000);

        return () => window.clearInterval(t);
    }, [reading]);

    return (
        <>
            <Head title={`Preview — ${info.original_name}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-[1.75rem] leading-tight font-semibold">{info.original_name}</h1>
                        <p className="mt-2 text-muted-foreground">
                            {reading
                                ? 'Reading the paper with AI…'
                                : failed
                                ? 'This file could not be read.'
                                : `${questions.length} questions found${withWarnings ? `, ${withWarnings} need attention` : ''}. Check the equations below — this is how students will see them.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {back && (
                            <Button variant="outline" asChild>
                                <Link href={back}>Back</Link>
                            </Button>
                        )}
                        {info.status === 'parsed' && questions.length > 0 && (
                            <Button onClick={() => router.post(commitUrl ?? `/question-bank/imports/${info.id}/commit`)}>
                                <CheckCircle2 className="size-4" /> {label ? `${label} (${questions.length})` : `Save ${questions.length} questions as drafts`}
                            </Button>
                        )}
                    </div>
                    {failed && ai && (
                        <Button variant="outline" onClick={() => router.post(`/question-bank/imports/${info.id}/retry`)}><RotateCcw className="size-4" /> Read again</Button>
                    )}
                    {info.status === 'committed' && <span className="text-sm text-status-good">Saved to the question bank.</span>}
                </header>

                {questions.length > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {typeCounts.map(([t, n]) => `${n} ${TYPE_NAMES[t] ?? t}`).join(' · ')}
                        {destination?.exam && <> · for <Link className="text-brand" href={`/exams/${destination.exam.id}?tab=banks`}>{destination.exam.title}</Link></>}
                        {destination && destination.pictures > 0 && ` · ${destination.pictures} pictures uploaded with the file`}
                    </p>
                )}

                {[...new Set([...(info.warnings ?? []), ...documentWarnings])].map((w) => (
                    <div key={w} className="flex gap-3 rounded-lg border border-status-warning/40 bg-status-warning/10 p-4 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warning" />
                        {w}
                    </div>
                ))}

                {!reading && info.status !== 'committed' && questions.length === 0 && (
                    <div className="surface grid gap-3 p-5 text-sm">
                        <p className="font-semibold">No questions were found in this file.</p>
                        <p className="text-muted-foreground">Number each question and letter its options. Any of these layouts works, in Word, PDF or text:</p>
                        <pre className="overflow-x-auto rounded-lg bg-muted p-3 text-xs leading-relaxed whitespace-pre">{`1. What is the capital of Nigeria?        Question 1: Which gas do plants absorb?
A. Lagos                                  (a) Oxygen  (b) Carbon dioxide  (c) Neon
B. Abuja                                  Ans: (b)
C. Kano
Answer: B                                 … or an answer key at the end: ANSWERS  1. B  2. C`}</pre>
                        <p className="text-muted-foreground">The correct option can also be marked with a * or written in bold. Excel sheets need columns Question, A, B, C, D and Answer. A scanned paper (photos) needs “Read with AI”.</p>
                        <TemplateLinks />
                    </div>
                )}

                {reading && (
                    <div className="surface flex items-center gap-4 p-6" role="status">
                        <span className="grid size-12 place-items-center rounded-2xl bg-brand-soft text-brand-ink"><Sparkles className="size-6" /></span>
                        <div className="flex-1">
                            <p className="font-semibold">The AI is reading every page</p>
                            <p className="text-sm text-muted-foreground">Questions, options, answers, maths and chemistry, tables and diagrams. A long paper takes a few minutes; you can leave this page and come back from Question bank › Import.</p>
                        </div>
                        <Loader2 className="size-5 animate-spin text-muted-foreground" />
                    </div>
                )}

                {pages > 0 && !reading && (
                    <details className="surface p-4">
                        <summary className="cursor-pointer text-sm font-medium">The original pages ({pages}) — compare with what was read</summary>
                        <div className="mt-3 grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                            {Array.from({ length: pages }, (_, i) => (
                                <a key={i} href={`/question-bank/imports/${info.id}/pages/${i + 1}`} target="_blank" rel="noreferrer" className="overflow-hidden rounded-xl border border-border bg-white">
                                    <img src={`/question-bank/imports/${info.id}/pages/${i + 1}`} alt={`Page ${i + 1}`} loading="lazy" className="aspect-[3/4] w-full object-cover object-top" />
                                    <span className="block px-2 py-1 text-xs text-muted-foreground">Page {i + 1}</span>
                                </a>
                            ))}
                        </div>
                    </details>
                )}

                <div className="grid gap-4">
                    {questions.map((q, i) => (
                        <QuestionView
                            key={i}
                            number={i + 1}
                            type={q.type}
                            stemHtml={q.stem_html}
                            options={q.options}
                            mark={q.mark}
                            meta={[q.topic, q.difficulty, q.line ? `line ${q.line} of the file` : null, q.scoring ? (q.scoring === 'all' ? 'all or nothing' : 'part marks') : null]}
                            answerText={q.answer_text}
                            explanationHtml={q.explanation_html}
                            warnings={q.warnings}
                        />
                    ))}
                </div>

                <Link href="/question-bank/import" className="text-sm text-muted-foreground underline-offset-4 hover:underline">
                    Upload a different file
                </Link>
            </div>
        </>
    );
}

ImportPreview.layout = {
    breadcrumbs: [
        { title: 'Question bank', href: '/question-bank' },
        { title: 'Import preview', href: '/question-bank/import' },
    ],
};
