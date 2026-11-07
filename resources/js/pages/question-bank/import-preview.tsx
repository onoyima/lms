import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { QuestionView } from '@/components/question/question-view';
import { Button } from '@/components/ui/button';
import type { ParsedQuestion } from '@/types';

type ImportInfo = { id: number; original_name: string; status: string; question_count: number; warnings: string[] | null; format: string };

export default function ImportPreview({
    import: info,
    questions,
    documentWarnings,
    commitUrl,
    commitLabel,
    backUrl,
}: {
    import: ImportInfo;
    questions: ParsedQuestion[];
    documentWarnings: string[];
    commitUrl?: string;
    commitLabel?: string;
    backUrl?: string;
}) {
    const withWarnings = questions.filter((q) => q.warnings.length > 0).length;
    const failed = info.status === 'failed';

    return (
        <>
            <Head title={`Preview — ${info.original_name}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <header className="flex flex-wrap items-end justify-between gap-4">
                    <div>
                        <h1 className="text-[1.75rem] leading-tight font-semibold">{info.original_name}</h1>
                        <p className="mt-2 text-muted-foreground">
                            {failed
                                ? 'This file could not be read.'
                                : `${questions.length} questions found${withWarnings ? `, ${withWarnings} need attention` : ''}. Check the equations below — this is how students will see them.`}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {backUrl && (
                            <Button variant="outline" asChild>
                                <Link href={backUrl}>Back</Link>
                            </Button>
                        )}
                        {info.status === 'parsed' && questions.length > 0 && (
                            <Button onClick={() => router.post(commitUrl ?? `/question-bank/imports/${info.id}/commit`)}>
                                <CheckCircle2 className="size-4" /> {commitLabel ? `${commitLabel} (${questions.length})` : `Save ${questions.length} questions as drafts`}
                            </Button>
                        )}
                    </div>
                    {info.status === 'committed' && <span className="text-sm text-status-good">Saved to the question bank.</span>}
                </header>

                {[...new Set([...(info.warnings ?? []), ...documentWarnings])].map((w) => (
                    <div key={w} className="flex gap-3 rounded-lg border border-status-warning/40 bg-status-warning/10 p-4 text-sm">
                        <AlertTriangle className="mt-0.5 size-4 shrink-0 text-status-warning" />
                        {w}
                    </div>
                ))}

                <div className="grid gap-4">
                    {questions.map((q) => (
                        <QuestionView
                            key={q.number}
                            number={q.number}
                            type={q.type}
                            stemHtml={q.stem_html}
                            options={q.options}
                            mark={q.mark}
                            meta={[q.topic, q.difficulty]}
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
