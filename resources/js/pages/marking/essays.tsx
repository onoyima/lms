import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PageHeader, inputClass, textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Answer = { attempt_id: number; question_id: number; candidate_code: string; stem_html: string; guide_html: string | null; mark: number; text: string; score: number | null; feedback: string | null };

export default function Essays({ exam, answers, remaining }: { exam: { id: number; title: string }; answers: Answer[]; remaining: number }) {
    return (
        <>
            <Head title={`Mark — ${exam.title}`} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title={exam.title} lead={`${remaining} scripts still need marks. Candidates appear by code only.`} />
                {answers.length === 0 && <p className="text-muted-foreground">Nothing waiting. Well done.</p>}
                {answers.map((a) => <EssayCard key={`${a.attempt_id}-${a.question_id}`} exam={exam} answer={a} />)}
            </div>
        </>
    );
}

function EssayCard({ exam, answer }: { exam: { id: number }; answer: Answer }) {
    const [score, setScore] = useState(answer.score ?? '');
    const [feedback, setFeedback] = useState(answer.feedback ?? '');

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
            </form>
        </article>
    );
}

Essays.layout = { breadcrumbs: [{ title: 'Marking', href: '/marking' }, { title: 'Essays', href: '#' }] };
