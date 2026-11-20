import { Head, router } from '@inertiajs/react';
import { Loader2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { PageHeader, inputClass, sendJson, textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Suggestion = { score: number; reasons: string | null; points: { point?: string; credit?: number; comment?: string }[] };
type Answer = { attempt_id: number; question_id: number; candidate_code: string; stem_html: string; guide_html: string | null; mark: number; text: string; score: number | null; feedback: string | null; suggestion: Suggestion | null };

export default function Essays({ exam, answers, remaining, ai }: { exam: { id: number; title: string }; answers: Answer[]; remaining: number; ai: boolean }) {
    return (
        <>
            <Head title={`Mark — ${exam.title}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title={exam.title} lead={`${remaining} scripts still need marks. Candidates appear by code only.`} />
                {answers.length === 0 && <p className="text-muted-foreground">Nothing waiting. Well done.</p>}
                {answers.map((a) => <EssayCard key={`${a.attempt_id}-${a.question_id}`} exam={exam} answer={a} ai={ai} />)}
            </div>
        </>
    );
}

function EssayCard({ exam, answer, ai }: { exam: { id: number }; answer: Answer; ai: boolean }) {
    const [score, setScore] = useState<string | number>(answer.score ?? '');
    const [feedback, setFeedback] = useState(answer.feedback ?? '');
    const [suggestion, setSuggestion] = useState<Suggestion | null>(answer.suggestion);
    const [asking, setAsking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const ask = async () => {
        setAsking(true);
        setError(null);
        const res = await sendJson<Suggestion & { message?: string }>(`/marking/${exam.id}/essays/suggest`, 'POST', { attempt_id: answer.attempt_id, question_id: answer.question_id }).catch(() => null);
        setAsking(false);

        if (res?.ok) {
            setSuggestion(res.data);
        } else {
            setError(res?.data.message ?? 'No answer from the server.');
        }
    };

    return (
        <article className="surface grid gap-4 p-6 lg:grid-cols-[1fr_300px]">
            <div className="grid gap-4">
                <p className="font-mono text-xs text-muted-foreground">Candidate {answer.candidate_code}</p>
                <RichContent html={answer.stem_html} className="text-[15px] font-medium" />
                <div className="rounded-xl bg-muted/50 p-4 text-[15px] leading-relaxed whitespace-pre-wrap">{answer.text}</div>
                {answer.guide_html && (
                    <details className="text-sm">
                        <summary className="cursor-pointer text-muted-foreground">Marking guide</summary>
                        <RichContent html={answer.guide_html} className="mt-2" />
                    </details>
                )}
            </div>
            <form className="grid content-start gap-3" onSubmit={(e) => (e.preventDefault(), router.post(`/marking/${exam.id}/essays`, { attempt_id: answer.attempt_id, question_id: answer.question_id, score, feedback }, { preserveScroll: true }))}>
                <label className="text-sm font-medium">Score out of {answer.mark}</label>
                <input type="number" min={0} max={answer.mark} step="0.25" className={inputClass} value={score} onChange={(e) => setScore(e.target.value)} required />
                <textarea className={textareaClass} placeholder="Feedback (optional)" value={feedback} onChange={(e) => setFeedback(e.target.value)} />
                <Button>Save mark</Button>
                {ai && !suggestion && (
                    <Button type="button" variant="outline" onClick={() => void ask()} disabled={asking}>
                        {asking ? <Loader2 className="size-4 animate-spin" /> : <Sparkles className="size-4" />} Suggest a mark
                    </Button>
                )}
                {error && <p className="text-xs text-status-critical">{error}</p>}
                {suggestion && (
                    <div className="grid gap-2 rounded-xl border border-brand/30 bg-brand-soft/50 p-3 text-sm">
                        <p className="flex items-center gap-1.5 font-semibold"><Sparkles className="size-4 text-brand" /> AI suggests {suggestion.score} of {answer.mark}</p>
                        {suggestion.reasons && <p className="text-muted-foreground">{suggestion.reasons}</p>}
                        {suggestion.points.length > 0 && (
                            <ul className="grid gap-1 text-xs">
                                {suggestion.points.map((p, i) => <li key={i}><b>{p.credit ?? 0}</b> · {p.point}{p.comment ? ` — ${p.comment}` : ''}</li>)}
                            </ul>
                        )}
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" size="sm" variant="outline" onClick={() => setScore(suggestion.score)}>Use {suggestion.score}</Button>
                            <Button type="button" size="sm" variant="ghost" onClick={() => void ask()} disabled={asking}>Ask again</Button>
                        </div>
                        <p className="text-[11px] text-muted-foreground">A suggestion only. Your saved mark is the mark.</p>
                    </div>
                )}
            </form>
        </article>
    );
}

Essays.layout = { breadcrumbs: [{ title: 'Marking', href: '/marking' }, { title: 'Essays', href: '#' }] };
