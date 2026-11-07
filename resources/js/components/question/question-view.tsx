import { AlertTriangle, Check } from 'lucide-react';
import { RichContent } from '@/components/rich-content';
import { Badge } from '@/components/ui/badge';
import type { QuestionOption } from '@/types';

const typeLabels: Record<string, string> = {
    single_choice: 'Single answer',
    multiple_choice: 'Multiple answers',
    true_false: 'True / false',
    matching: 'Matching',
    numeric: 'Numeric',
    short_answer: 'Short answer',
    essay: 'Essay',
};

/** A question as setters and moderators see it: stem, options with the key marked, maths rendered. */
export function QuestionView({
    number,
    type,
    stemHtml,
    options,
    mark,
    meta,
    answerText,
    explanationHtml,
    warnings = [],
}: {
    number: number | string;
    type: string;
    stemHtml: string;
    options: QuestionOption[];
    mark: number;
    meta?: (string | null | undefined)[];
    answerText?: string | null;
    explanationHtml?: string | null;
    warnings?: string[];
}) {
    return (
        <article className="surface p-6">
            <header className="flex flex-wrap items-center gap-2">
                <span className="font-mono text-xs text-muted-foreground">Q{number}</span>
                <Badge variant="outline">{typeLabels[type] ?? type}</Badge>
                <Badge variant="outline">
                    {mark} mark{Number(mark) === 1 ? '' : 's'}
                </Badge>
                {meta?.filter(Boolean).map((m) => (
                    <Badge key={m} variant="secondary">
                        {m}
                    </Badge>
                ))}
            </header>

            <RichContent html={stemHtml} className="mt-4 text-[17px] leading-relaxed" />

            {options.length > 0 && (
                <ol className="mt-5 grid gap-2">
                    {options.map((o) => (
                        <li
                            key={o.label}
                            className={`flex items-start gap-3 rounded-xl border p-3 ${o.correct ? 'border-brand/60 bg-brand-soft' : 'border-border'}`}
                        >
                            <span
                                className={`flex size-7 shrink-0 items-center justify-center rounded-lg text-sm font-semibold ${o.correct ? 'bg-brand text-brand-foreground' : 'bg-muted'}`}
                            >
                                {o.label}
                            </span>
                            <RichContent html={o.html} className="flex-1 pt-0.5" />
                            {o.correct && <Check className="mt-1 size-4 text-brand" aria-label="Correct answer" />}
                        </li>
                    ))}
                </ol>
            )}

            {answerText && (
                <p className="mt-4 text-sm">
                    <span className="eyebrow mr-2">Answer</span>
                    <span className="font-mono">{answerText}</span>
                </p>
            )}

            {explanationHtml && (
                <div className="mt-4 rounded-xl bg-muted/60 p-4 text-sm">
                    <p className="eyebrow mb-2">Explanation</p>
                    <RichContent html={explanationHtml} />
                </div>
            )}

            {warnings.length > 0 && (
                <ul className="mt-4 space-y-1">
                    {warnings.map((w) => (
                        <li key={w} className="flex items-start gap-2 text-sm text-status-warning">
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            {w}
                        </li>
                    ))}
                </ul>
            )}
        </article>
    );
}
