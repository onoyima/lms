import { Head, Link, router, usePoll } from '@inertiajs/react';
import { Check, Copy, ExternalLink, MonitorPlay, Power, RefreshCw, Square } from 'lucide-react';
import { useState } from 'react';
import { Flash, formatDateTime, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Person = { candidate_id: number; name: string | null; identifier: string | null; email: string | null; guest: boolean; entry_id: number | null; status: string; answered: number; risk: number; score: [number, number] | null };
type Props = {
    exam: { id: number; title: string; status: string; category: string; duration: number; protection: string };
    session: { id: number; starts_at: string; ends_at: string; status: string; open: boolean };
    link: { url: string; qr: string; code: string | null; audience: string; enabled: boolean } | null;
    people: Person[];
    counts: { listed: number; writing: number; submitted: number };
};

const audienceText: Record<string, string> = {
    roster: 'Students on the list sign in and start.',
    institution: 'Any of your students signs in and starts.',
    anyone: 'Anyone types their name and number and starts — no account needed.',
};

/** The control panel of a running exam: share it, watch it, close it. */
export default function Ready({ exam, session, link, people, counts }: Props) {
    const [copied, setCopied] = useState(false);
    usePoll(10000, { only: ['people', 'counts', 'session'] });
    const share = link ? `${link.url}${link.code ? `\nAccess code: ${link.code}` : ''}` : '';
    const waiting = exam.status === 'submitted';

    return (
        <>
            <Head title={`${exam.title} — ready`} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    eyebrow={waiting ? 'Waiting for approval' : session.open ? 'Running' : new Date(session.starts_at) > new Date() ? 'Scheduled' : 'Closed'}
                    title={exam.title}
                    lead={`${exam.duration} minutes · ${exam.protection} · open ${formatDateTime(session.starts_at)} – ${formatDateTime(session.ends_at)}`}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline"><Link href={`/proctor/sessions/${session.id}`}><MonitorPlay className="size-4" /> Watch live</Link></Button>
                            <Button asChild variant="outline"><Link href={`/exams/${exam.id}`}>Exam settings</Link></Button>
                            {session.open && (
                                <Button variant="outline" onClick={() => confirm('Close the exam to new starts? People already writing keep their time.') && router.post(`/sessions/${session.id}/close`, {}, { preserveScroll: true })}>
                                    <Square className="size-4" /> Close now
                                </Button>
                            )}
                        </div>
                    }
                />
                <Flash />
                {waiting && <p className="rounded-lg border border-status-warning/40 bg-status-warning/10 p-4 text-sm">This exam is waiting for a second person to approve it. The link already works; students can start once it is approved.</p>}

                {link && (
                    <section className="surface grid gap-6 p-6 md:grid-cols-[auto_1fr]">
                        <div className="mx-auto w-44 rounded-lg bg-white p-2 [&_svg]:h-auto [&_svg]:w-full" aria-label="QR code for the exam link" dangerouslySetInnerHTML={{ __html: link.qr }} />
                        <div className="grid content-start gap-3">
                            <p className="eyebrow">Share this with the students</p>
                            <div className="flex flex-wrap items-center gap-2">
                                <code className="rounded-md border border-border bg-muted px-3 py-2 text-sm break-all">{link.url}</code>
                                <Button size="sm" onClick={() => {
                                    void navigator.clipboard?.writeText(share).then(() => {
                                        setCopied(true);
                                        window.setTimeout(() => setCopied(false), 2000);
                                    });
                                }}>
                                    {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Copied' : 'Copy link'}
                                </Button>
                                <a className="inline-flex items-center gap-1 text-sm text-brand" href={link.url} target="_blank" rel="noreferrer">Open <ExternalLink className="size-3.5" /></a>
                            </div>
                            {link.code && <p className="text-sm">Access code: <strong className="font-mono text-base tracking-wider">{link.code}</strong></p>}
                            <p className="text-sm text-muted-foreground">{audienceText[link.audience]}</p>
                            <div className="flex flex-wrap gap-2 text-sm">
                                <select aria-label="Who may use the link" className="h-9 rounded-md border border-input bg-card px-2" value={link.audience} onChange={(e) => router.put(`/sessions/${session.id}/link`, { audience: e.target.value }, { preserveScroll: true })}>
                                    <option value="roster">Only students on the list</option>
                                    <option value="institution">Any of our students</option>
                                    <option value="anyone">Anyone with the link</option>
                                </select>
                                <Button size="sm" variant="outline" onClick={() => {
                                    const code = prompt('New access code (4–12 letters or numbers). Leave empty to remove the code.', link.code ?? '');

                                    if (code !== null) {
                                        router.put(`/sessions/${session.id}/link`, code.trim() ? { code: code.trim() } : { clear_code: true }, { preserveScroll: true });
                                    }
                                }}>Access code</Button>
                                <Button size="sm" variant="outline" onClick={() => confirm('Make a new link? The old one stops working.') && router.put(`/sessions/${session.id}/link`, { new_link: true }, { preserveScroll: true })}><RefreshCw className="size-4" /> New link</Button>
                                <Button size="sm" variant="outline" onClick={() => router.put(`/sessions/${session.id}/link`, { enabled: !link.enabled }, { preserveScroll: true })}><Power className="size-4" /> {link.enabled ? 'Switch link off' : 'Switch link on'}</Button>
                            </div>
                        </div>
                    </section>
                )}

                <div className="grid grid-cols-3 gap-4">
                    <Stat label="Joined" value={counts.listed} />
                    <Stat label="Writing now" value={counts.writing} tone={counts.writing ? 'good' : undefined} />
                    <Stat label="Submitted" value={counts.submitted} />
                </div>

                <Table head={['Name', 'Number / email', 'Status', 'Answered', 'Risk', 'Score', '']}>
                    {people.length === 0 && (
                        <tr><Td className="text-muted-foreground" >Nobody has joined yet. This list refreshes by itself.</Td></tr>
                    )}
                    {people.map((p) => (
                        <tr key={p.candidate_id}>
                            <Td>{p.name}{p.guest && <span className="ml-2 rounded bg-muted px-1.5 text-xs text-muted-foreground">guest</span>}</Td>
                            <Td>{p.identifier && !p.identifier.startsWith('G') ? p.identifier : p.email ?? p.identifier}</Td>
                            <Td><Status value={p.status} /></Td>
                            <Td className="tabular-nums">{p.answered}</Td>
                            <Td className={`tabular-nums ${p.risk >= 40 ? 'text-status-critical' : ''}`}>{p.risk}</Td>
                            <Td className="tabular-nums">{p.score ? `${p.score[0]} / ${p.score[1]}` : ''}</Td>
                            <Td>
                                {p.guest && p.entry_id && p.status === 'not started' && (
                                    <button type="button" className="text-xs text-brand" title="They can enter again from another computer" onClick={() => router.post(`/sessions/${session.id}/link-entries/${p.entry_id}/release`, {}, { preserveScroll: true })}>Let in again</button>
                                )}
                                {p.guest && p.entry_id && p.status !== 'not started' && (
                                    <button type="button" className="text-xs text-brand" title="Their computer broke: let them continue on another one" onClick={() => confirm(`Let ${p.name} continue on another computer?`) && router.post(`/sessions/${session.id}/link-entries/${p.entry_id}/release`, {}, { preserveScroll: true })}>Move to another computer</button>
                                )}
                            </Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}
