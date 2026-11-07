import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { Flash, formatDateTime, inputClass, PageHeader, Stat, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Row = { id: number; to: string; subject: string | null; kind: string; status: string; error: string | null; created_at: string; updated_at: string };
const tone: Record<string, string> = { sent: 'text-status-good', failed: 'text-status-critical', bounced: 'text-status-critical', sending: 'text-status-warning' };

/** What happened to each e-mail the platform sent for this institution (LMS 188). */
export default function MailLog({ rows, counts, filters }: { rows: Row[]; counts: Record<string, number>; filters: { status?: string | null; q?: string } }) {
    const [q, setQ] = useState(filters.q ?? '');
    const go = (status?: string | null) => router.get('/admin/mail-log', { status: status || undefined, q: q || undefined }, { preserveState: true, replace: true });

    return (
        <>
            <Head title="E-mail delivery" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="E-mail delivery" lead="Each e-mail is logged when it is handed to the mail server. Bounces appear when your mail service reports them. In-app notifications are always delivered, whatever happens to the e-mail." />
                <Flash />
                <div className="grid gap-4 sm:grid-cols-4">
                    <Stat label="Sent, 7 days" value={counts.sent ?? 0} tone="good" />
                    <Stat label="Waiting" value={counts.sending ?? 0} />
                    <Stat label="Failed" value={counts.failed ?? 0} tone={counts.failed ? 'critical' : undefined} />
                    <Stat label="Bounced" value={counts.bounced ?? 0} tone={counts.bounced ? 'warning' : undefined} />
                </div>
                <div className="flex flex-wrap gap-2">
                    <input className={`${inputClass} max-w-xs`} placeholder="Address or subject" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && go(filters.status)} />
                    {['', 'sent', 'sending', 'failed', 'bounced'].map((s) => <Button key={s} size="sm" variant={(filters.status ?? '') === s ? 'default' : 'outline'} onClick={() => go(s)}>{s || 'All'}</Button>)}
                </div>
                <Table head={['When', 'To', 'Subject', 'Type', 'Status']}>
                    {rows.length === 0 && <tr><Td className="text-muted-foreground">No e-mails yet.</Td></tr>}
                    {rows.map((r) => (
                        <tr key={r.id}>
                            <Td>{formatDateTime(r.created_at)}</Td>
                            <Td className="break-all">{r.to}</Td>
                            <Td>{r.subject}</Td>
                            <Td className="text-xs">{r.kind}</Td>
                            <Td><span className={`font-medium ${tone[r.status] ?? ''}`}>{r.status}</span>{r.error && <span className="block text-xs text-muted-foreground">{r.error}</span>}</Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

MailLog.layout = { breadcrumbs: [{ title: 'E-mail delivery', href: '/admin/mail-log' }] };
