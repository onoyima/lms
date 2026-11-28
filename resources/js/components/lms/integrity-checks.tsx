import { router } from '@inertiajs/react';
import { Bot, Copy, RefreshCw } from 'lucide-react';
import { useEffect, useRef } from 'react';

/** Similarity and AI-writing scores of one submission, as the server sends them. */
export type Checks = {
    checked: boolean;
    pending?: boolean;
    checked_at: string | null;
    text_status: 'read' | 'short' | 'unreadable' | null;
    words: number | null;
    similarity: number | null;
    external: { provider: string; score: number | null; report_url: string | null } | null;
    ai: number | null;
    ai_band: 'low' | 'some' | 'high' | null;
    ai_signals: { label: string; level: string; source?: string }[];
    ai_model: string | null;
};

export type Match = { percent: number; student: string; same_assignment: boolean };

const simBand = (p: number) => (p >= 40 ? 'high' : p >= 15 ? 'some' : 'low');

const TONE: Record<string, string> = {
    low: 'bg-status-success/12 text-status-success',
    some: 'bg-status-warning/15 text-status-warning',
    high: 'bg-status-critical/15 text-status-critical',
};

const AI_WORDS: Record<string, string> = {
    low: 'few signs of AI writing',
    some: 'some signs of AI writing',
    high: 'strong signs of AI writing',
};

/** Keeps asking the server for the result while a fresh hand-in is still being checked. */
function useWaitForChecks(waiting: boolean) {
    const tries = useRef(0);
    useEffect(() => {
        if (!waiting || tries.current >= 8) {
            return;
        }

        const t = window.setTimeout(() => {
            tries.current += 1;
            router.reload({ only: ['submissions'] });
        }, 4000);

        return () => window.clearTimeout(t);
    });
}

/** Two small badges for a table row. */
export function CheckBadges({ checks, matches = [] }: { checks?: Checks; matches?: Match[] }) {
    if (!checks) {
        return null;
    }

    if (!checks.checked) {
        return <span className="ml-1 text-[11px] text-muted-foreground">{checks.pending ? 'checking…' : 'not checked'}</span>;
    }

    if (checks.text_status === 'unreadable') {
        return (
            <span className="ml-1 rounded bg-muted px-1.5 py-0.5 text-[11px] text-muted-foreground" title="The words in this file could not be read, so it was not checked. Open it and check by eye.">
                not checkable
            </span>
        );
    }

    return (
        <>
            {checks.similarity !== null && (
                <span
                    className={`ml-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] tabular-nums ${TONE[simBand(checks.similarity)]}`}
                    title={matches.length ? matches.map((m) => `${m.percent}% like ${m.student}${m.same_assignment ? '' : ' (earlier class)'}`).join('\n') : 'Matches no other submission'}
                >
                    <Copy className="size-3" aria-hidden /> {checks.similarity}% similar
                </span>
            )}
            {checks.ai !== null && checks.ai_band && (
                <span className={`ml-1 inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-[11px] tabular-nums ${TONE[checks.ai_band]}`} title={checks.ai_signals.map((s) => s.label).join('\n')}>
                    <Bot className="size-3" aria-hidden /> AI {Math.round(checks.ai)}%
                </span>
            )}
        </>
    );
}

function Meter({ value, band }: { value: number; band: string }) {
    const bar = band === 'high' ? 'bg-status-critical' : band === 'some' ? 'bg-status-warning' : 'bg-status-success';

    return (
        <span className="block h-1.5 w-full overflow-hidden rounded-full bg-muted" aria-hidden>
            <span className={`block h-full rounded-full ${bar}`} style={{ width: `${Math.max(2, Math.min(100, value))}%` }} />
        </span>
    );
}

/**
 * The full report: what each score means, and for the lecturer the reasons, the closest matches
 * and a button to check again.
 */
export function CheckReport({
    checks,
    matches = [],
    teacher = false,
    submissionId,
}: {
    checks?: Checks;
    matches?: Match[];
    teacher?: boolean;
    submissionId: number;
}) {
    useWaitForChecks(!!checks && !checks.checked && !!checks.pending);

    if (!checks) {
        return null;
    }

    if (!checks.checked && !checks.pending) {
        return (
            <p className="flex items-center justify-between gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                Not checked for copying or AI writing yet.
                {teacher && (
                    <button type="button" className="text-brand hover:underline" onClick={() => router.post(`/learn/submissions/${submissionId}/recheck`, {}, { preserveScroll: true })}>
                        Check now
                    </button>
                )}
            </p>
        );
    }

    if (!checks.checked) {
        return (
            <p className="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                <RefreshCw className="mr-1 inline size-3 animate-spin" aria-hidden />
                Checking for copying and AI writing…
            </p>
        );
    }

    return (
        <div className="grid gap-3 rounded-xl border p-3 text-xs">
            <div className="flex items-center justify-between gap-2">
                <p className="font-medium text-foreground">Originality check</p>
                {teacher && (
                    <button
                        type="button"
                        className="inline-flex items-center gap-1 text-brand hover:underline"
                        onClick={() => router.post(`/learn/submissions/${submissionId}/recheck`, {}, { preserveScroll: true })}
                    >
                        <RefreshCw className="size-3" aria-hidden /> Check again
                    </button>
                )}
            </div>

            {checks.text_status === 'unreadable' ? (
                <p className="text-muted-foreground">
                    The words in this file could not be read (a scan or photo without readable text), so it was not checked.
                    {teacher ? ' Open the file and check it by eye.' : ' Your lecturer will look at it.'}
                </p>
            ) : checks.text_status === 'short' ? (
                <p className="text-muted-foreground">
                    Too little text to check ({checks.words ?? 0} words). Checks start from 120 words.
                </p>
            ) : (
                <>
                    {checks.similarity !== null && (
                        <div className="grid gap-1">
                            <span className="flex items-baseline justify-between">
                                <span>Similarity to other students' work</span>
                                <span className="text-sm font-semibold tabular-nums">{checks.similarity}%</span>
                            </span>
                            <Meter value={checks.similarity} band={simBand(checks.similarity)} />
                            {teacher && matches.length > 0 && (
                                <ul className="mt-1 grid gap-0.5 text-muted-foreground">
                                    {matches.slice(0, 5).map((m, i) => (
                                        <li key={i}>
                                            {m.percent}% like {m.student}
                                            {m.same_assignment ? '' : ' (earlier class)'}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                    {checks.external && checks.external.score !== null && (
                        <p>
                            {checks.external.provider}:{' '}
                            {checks.external.report_url ? (
                                <a href={checks.external.report_url} target="_blank" rel="noreferrer noopener" className="font-semibold text-brand underline">
                                    {checks.external.score}%
                                </a>
                            ) : (
                                <span className="font-semibold">{checks.external.score}%</span>
                            )}
                        </p>
                    )}
                    {checks.ai !== null && checks.ai_band && (
                        <div className="grid gap-1">
                            <span className="flex items-baseline justify-between">
                                <span>AI writing score</span>
                                <span className="text-sm font-semibold tabular-nums">{Math.round(checks.ai)}%</span>
                            </span>
                            <Meter value={checks.ai} band={checks.ai_band} />
                            <span className="text-muted-foreground">{AI_WORDS[checks.ai_band]}</span>
                            {teacher && checks.ai_signals.length > 0 && (
                                <ul className="mt-1 list-disc pl-4 text-muted-foreground">
                                    {checks.ai_signals.map((s, i) => (
                                        <li key={i}>{s.label}</li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}
                </>
            )}
            <p className="text-[11px] text-muted-foreground">
                {teacher
                    ? `These scores show where to look, not proof. Read the work before deciding.${checks.ai_model ? ` AI score uses ${checks.ai_model} and the writing-style measures.` : ' AI score uses writing-style measures on this server.'}`
                    : 'Your lecturer sees these scores too. They point to where to look; your lecturer reads the work and decides.'}
            </p>
        </div>
    );
}
