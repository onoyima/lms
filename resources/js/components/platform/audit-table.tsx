import { Link, router } from '@inertiajs/react';
import { Download, ShieldCheck, ShieldX } from 'lucide-react';
import { useState } from 'react';
import { inputClass, formatDateTime, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

export type AuditRow = { id: number; action: string; actor: string; subject: string | null; data: Record<string, unknown> | null; ip: string | null; at: string };
export type AuditProps = {
    logs: { data: AuditRow[]; links: { url: string | null; label: string; active: boolean }[]; total: number };
    filters: { q?: string; actor?: string; from?: string; to?: string; tenant?: string };
    broken: number | null;
};

/** Search, verify and export the audit trail (shared by the institution and the platform console). */
export function AuditBrowser({ logs, filters, broken, base, extra }: AuditProps & { base: string; extra?: React.ReactNode }) {
    const [f, setF] = useState({ q: filters.q ?? '', actor: filters.actor ?? '', from: filters.from ?? '', to: filters.to ?? '', tenant: filters.tenant ?? '' });
    const query = new URLSearchParams(Object.entries(f).filter(([, v]) => v) as [string, string][]).toString();

    return (
        <div className="flex flex-col gap-4">
            <form className="surface grid gap-3 p-4 md:grid-cols-6" onSubmit={(e) => {
                e.preventDefault();
                router.get(base, Object.fromEntries(Object.entries(f).filter(([, v]) => v)), { preserveState: true });
            }}>
                {extra && <div className="md:col-span-2">{extra}</div>}
                <input aria-label="Action or detail" placeholder="Action or detail" className={inputClass} value={f.q} onChange={(e) => setF({ ...f, q: e.target.value })} />
                <input aria-label="Person" placeholder="Person" className={inputClass} value={f.actor} onChange={(e) => setF({ ...f, actor: e.target.value })} />
                <input aria-label="From" type="date" className={inputClass} value={f.from} onChange={(e) => setF({ ...f, from: e.target.value })} />
                <input aria-label="To" type="date" className={inputClass} value={f.to} onChange={(e) => setF({ ...f, to: e.target.value })} />
                <div className="flex flex-wrap gap-2 md:col-span-6">
                    <Button>Search</Button>
                    <Button type="button" variant="outline" asChild><a href={`${base}/export${query ? `?${query}` : ''}`}><Download className="size-4" /> Export CSV</a></Button>
                    <Button type="button" variant="ghost" onClick={() => router.get(base, { ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)), verify: 1 }, { preserveState: true })}><ShieldCheck className="size-4" /> Check it has not been altered</Button>
                    <span className="ml-auto self-center text-sm text-muted-foreground">{logs.total.toLocaleString()} entries</span>
                </div>
            </form>
            {broken !== null && (broken === 0
                ? <p className="flex items-center gap-2 rounded-xl border border-status-good/40 bg-status-good/10 p-3 text-sm"><ShieldCheck className="size-4" /> The chain is intact: no entry has been edited or removed.</p>
                : <p className="flex items-center gap-2 rounded-xl border border-status-critical/40 bg-status-critical/10 p-3 text-sm"><ShieldX className="size-4" /> The chain breaks at entry #{broken}: it or an earlier entry was altered. Report this as a security incident.</p>)}
            <Table head={['When', 'Who', 'Action', 'About', 'Details', 'IP']}>
                {logs.data.length === 0 && <tr><Td className="text-muted-foreground">Nothing matches.</Td></tr>}
                {logs.data.map((l) => (
                    <tr key={l.id}>
                        <Td className="whitespace-nowrap text-xs">{formatDateTime(l.at)}</Td>
                        <Td>{l.actor}{l.data && 'support_session' in l.data && <span className="ml-1 rounded bg-status-critical/15 px-1 text-xs">support</span>}</Td>
                        <Td className="font-mono text-xs">{l.action}</Td>
                        <Td className="text-xs">{l.subject}</Td>
                        <Td className="max-w-md text-xs break-words text-muted-foreground">{l.data ? JSON.stringify(l.data) : ''}</Td>
                        <Td className="font-mono text-xs">{l.ip}</Td>
                    </tr>
                ))}
            </Table>
            {logs.links.length > 3 && (
                <nav className="flex flex-wrap gap-1" aria-label="Pages">
                    {logs.links.map((l, i) => l.url
                        ? <Link key={i} href={l.url} preserveScroll className={`rounded-md border px-3 py-1 text-sm ${l.active ? 'border-brand text-brand' : ''}`} dangerouslySetInnerHTML={{ __html: l.label }} />
                        : <span key={i} className="px-3 py-1 text-sm text-muted-foreground" dangerouslySetInnerHTML={{ __html: l.label }} />)}
                </nav>
            )}
        </div>
    );
}
