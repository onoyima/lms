import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Pencil, X } from 'lucide-react';
import { useState } from 'react';
import { formatDateTime, PageHeader, textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Opt = { id: number; label: string; html: string; right: boolean; truth: boolean | null; chosen: boolean; said: boolean | null };
type Item = { question_id: number; position: number; n: number | null; type: string | null; stem_html: string | null; mark: number; score: number | null; dropped: boolean; award_all: boolean; options: Opt[]; text: string | null; correctable: boolean };
type Attempt = { id: number; name: string | null; identifier: string | null; raw: number; max: number; status: string; submitted_at: string | null; final: number | null; locked: boolean };

/** One student's script: what they had, what they chose, the right answer and the mark; one answer can be corrected with a reason. */
export default function Script({ exam, attempt, items, pending, needsApproval }: { exam: { id: number; title: string }; attempt: Attempt; items: Item[]; pending: { question_id: number; reason: string }[]; needsApproval: boolean }) {
    const { flash } = usePage<{ flash?: { success?: string } }>().props;
    const [editing, setEditing] = useState<number | null>(null);

    return (
        <>
            <Head title={`Script — ${attempt.name}`} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    eyebrow={exam.title}
                    title={attempt.name ?? 'Script'}
                    lead={`${attempt.identifier ?? ''} · ${attempt.raw} / ${attempt.max}${attempt.final !== null ? ` · final ${attempt.final}` : ''} · ${attempt.status.replace('_', ' ')}${attempt.submitted_at ? ` · submitted ${formatDateTime(attempt.submitted_at)}` : ''}`}
                    actions={<Button variant="outline" asChild><Link href={`/results/${exam.id}/questions`}>All questions</Link></Button>}
                />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                {items.map((it, i) => {
                    const waiting = pending.find((p) => p.question_id === it.question_id);

                    return (
                        <section key={it.question_id} className="surface grid gap-3 p-5">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <p className="text-xs text-muted-foreground">Question {i + 1} on this paper{it.n ? ` · Q${it.n} in the statistics` : ''}</p>
                                <span className={`rounded-full px-2.5 py-0.5 text-xs font-semibold tabular-nums ${it.dropped ? 'bg-muted' : it.score !== null && it.score >= it.mark ? 'bg-status-good/15 text-status-good' : 'bg-status-critical/10 text-status-critical'}`}>
                                    {it.dropped ? 'taken out' : `${it.score ?? 0} / ${it.mark}`}{it.award_all ? ' · full marks for all' : ''}
                                </span>
                            </div>
                            <RichContent html={(it.stem_html ?? '').replace(/\[\[(\d{1,2})\]\]/g, '<span class="blank-chip">($1)</span>')} className="text-sm" />
                            {editing === it.question_id ? (
                                <Correct examId={exam.id} attemptId={attempt.id} item={it} needsApproval={needsApproval} onDone={() => setEditing(null)} />
                            ) : (
                                <ul className="grid gap-1 text-sm">
                                    {it.options.map((o) => (
                                        <li key={o.id} className={`flex items-start gap-2 rounded-lg border px-3 py-1.5 ${o.chosen || o.said !== null ? 'border-brand/50 bg-brand-soft/40' : 'border-border'}`}>
                                            <b className="w-4">{o.label}</b>
                                            <RichContent html={o.html} className="flex-1" />
                                            {it.type === 'multi_true_false' ? (
                                                <span className="text-xs">said {o.said === null ? '—' : o.said ? 'true' : 'false'} · is {o.truth ? 'true' : 'false'}</span>
                                            ) : (
                                                <span className="flex items-center gap-1 text-xs">
                                                    {o.chosen && <span className="font-semibold">chosen</span>}
                                                    {o.right && <span className="flex items-center gap-0.5 text-status-good"><Check className="size-3.5" /> right</span>}
                                                </span>
                                            )}
                                        </li>
                                    ))}
                                    {it.text !== null && <li className="rounded-lg border border-border px-3 py-2 whitespace-pre-wrap">{it.text || <span className="text-muted-foreground">No answer</span>}</li>}
                                    {it.options.length === 0 && it.text === null && <li className="text-muted-foreground">No answer</li>}
                                </ul>
                            )}
                            {waiting && <p className="text-xs text-status-warning">A correction is waiting for approval: {waiting.reason}</p>}
                            {it.correctable && !waiting && editing !== it.question_id && (
                                <Button size="sm" variant="ghost" className="w-fit" onClick={() => setEditing(it.question_id)}><Pencil className="size-3.5" /> Correct this answer</Button>
                            )}
                        </section>
                    );
                })}
            </div>
        </>
    );
}

function Correct({ examId, attemptId, item, needsApproval, onDone }: { examId: number; attemptId: number; item: Item; needsApproval: boolean; onDone: () => void }) {
    const mtf = item.type === 'multi_true_false';
    const single = item.type === 'single_choice' || item.type === 'true_false';
    const [chosen, setChosen] = useState<number[]>(item.options.filter((o) => o.chosen).map((o) => o.id));
    const [tf, setTf] = useState<Record<number, boolean>>(Object.fromEntries(item.options.filter((o) => o.said !== null).map((o) => [o.id, o.said as boolean])));
    const [reason, setReason] = useState('');

    return (
        <div className="grid gap-2 rounded-xl border border-brand/40 p-3">
            <p className="text-sm font-medium">What the student actually chose</p>
            <ul className="grid gap-1 text-sm">
                {item.options.map((o) => (
                    <li key={o.id} className="flex items-start gap-2 rounded-lg px-2 py-1">
                        <b className="w-4">{o.label}</b>
                        <RichContent html={o.html} className="flex-1" />
                        {mtf ? (
                            <span className="flex gap-1">
                                {[true, false].map((v) => (
                                    <button key={String(v)} type="button" onClick={() => setTf({ ...tf, [o.id]: v })} className={`rounded-md border px-2 py-0.5 text-xs ${tf[o.id] === v ? 'border-brand bg-brand text-brand-foreground' : 'border-border'}`}>{v ? 'True' : 'False'}</button>
                                ))}
                            </span>
                        ) : (
                            <input type={single ? 'radio' : 'checkbox'} name={`c-${item.question_id}`} checked={chosen.includes(o.id)} aria-label={`Option ${o.label}`}
                                onChange={(e) => setChosen(single ? [o.id] : e.target.checked ? [...chosen, o.id] : chosen.filter((x) => x !== o.id))} />
                        )}
                    </li>
                ))}
            </ul>
            <textarea className={`${textareaClass} min-h-16`} placeholder="Why (for example: the answer sheet shows C; the screen froze when she clicked)" value={reason} onChange={(e) => setReason(e.target.value)} />
            <div className="flex flex-wrap gap-2">
                <Button size="sm" disabled={reason.trim().length < 10} onClick={() => router.post(`/results/${examId}/scripts/${attemptId}/answers/${item.question_id}`, mtf ? { tf, reason } : { options: chosen, reason }, { preserveScroll: true, onSuccess: onDone })}>
                    {needsApproval ? 'Send for approval' : 'Correct and mark again'}
                </Button>
                <Button size="sm" variant="ghost" onClick={onDone}><X className="size-3.5" /> Cancel</Button>
            </div>
        </div>
    );
}

Script.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Script', href: '#' }] };
