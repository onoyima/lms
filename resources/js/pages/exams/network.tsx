import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Check, Copy, History, RefreshCw, RotateCcw, Search, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { Field, formatDateTime, inputClass, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Address = { ip: string; source: string; private: boolean; reachable: boolean; other_institution: boolean };
type Computer = {
    id: number; ip: string; label: string | null; status: 'pending' | 'approved' | 'rejected'; venue: string | null; venue_id: number | null;
    student: { name: string; identifier: string | null } | null; requests: number; first_seen_at: string | null; last_seen_at: string | null;
    decided_at: string | null; decided_by: string | null; browser: string | null;
};
type Batch = { cleared_at: string; reason: string | null; total: number; approved: number; rejected: number; pending: number; by: string | null };
type Props = {
    server: { addresses: Address[]; browser_host: string; port: number };
    settings: { preferred_address: string | null; auto_clear: boolean };
    studentUrl: string;
    qr: string;
    computers: Computer[];
    counts: { pending: number; approved: number; rejected: number };
    history: Batch[];
    venues: { id: number; name: string }[];
};

/** Exam network: the server's address for students, and the computers approved (by IP) to sit exams. */
export default function ExamNetwork({ server, settings, studentUrl, qr, computers, counts, history, venues }: Props) {
    const { flash } = usePage().props;
    const form = useForm({ preferred_address: settings.preferred_address ?? '', auto_clear: settings.auto_clear });
    const [filter, setFilter] = useState<'all' | Computer['status']>('all');
    const [q, setQ] = useState('');
    const [copied, setCopied] = useState(false);
    const [open, setOpen] = useState<string | null>(null);
    const [batch, setBatch] = useState<Computer[] | null>(null);

    // the list refreshes itself every 10 seconds so new computers appear while students arrive
    useEffect(() => {
        const t = window.setInterval(() => router.reload({ only: ['computers', 'counts'] }), 10000);

        return () => window.clearInterval(t);
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }

        let alive = true;
        fetch(`/exam-network/history?at=${encodeURIComponent(open)}`, { headers: { Accept: 'application/json' } }).then(async (r) => {
            if (alive && r.ok) {
                setBatch(await r.json());
            }
        });

        return () => {
            alive = false;
        };
    }, [open]);

    const list = useMemo(
        () => computers.filter((c) => (filter === 'all' || c.status === filter) && (!q || `${c.ip} ${c.label ?? ''} ${c.student?.name ?? ''} ${c.student?.identifier ?? ''}`.toLowerCase().includes(q.toLowerCase()))),
        [computers, filter, q],
    );
    const decide = (c: Computer, status: Computer['status']) => router.put(`/exam-network/computers/${c.id}`, { status }, { preserveScroll: true, only: ['computers', 'counts', 'flash'] });

    return (
        <>
            <Head title="Exam network" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Exam network" lead="The address students type to reach the exams, and the hall computers allowed to sit them." />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}

                <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
                    <section className="surface grid content-start gap-5 p-6">
                        <div>
                            <h2 className="text-base font-semibold">Server address</h2>
                            <p className="mt-1 text-sm text-muted-foreground">These are the addresses this computer has on the network. Students on the campus network can open the exams on any address marked ready.</p>
                        </div>
                        <Table head={['IP address', 'Found from', 'Ready for this institution', '']}>
                            {server.addresses.map((a) => (
                                <tr key={a.ip}>
                                    <Td className="font-mono">{a.ip}</Td>
                                    <Td className="text-xs text-muted-foreground">{a.source}{a.private ? ' · campus network' : ' · public'}</Td>
                                    <Td>{a.other_institution ? <span className="text-xs text-status-warning">used by another institution</span> : a.reachable ? <Status value="ready" /> : <span className="text-xs text-muted-foreground">not yet</span>}</Td>
                                    <Td>
                                        {!a.other_institution && form.data.preferred_address !== a.ip && (
                                            <Button size="sm" variant="outline" onClick={() => form.setData('preferred_address', a.ip)}>Use this</Button>
                                        )}
                                    </Td>
                                </tr>
                            ))}
                            {server.addresses.length === 0 && (
                                <tr><Td className="text-sm text-muted-foreground">No network address was found. You can still type the address below.</Td></tr>
                            )}
                        </Table>
                        <form
                            className="grid gap-4 border-t border-border pt-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.transform((d) => ({ ...d, preferred_address: d.preferred_address.trim() || null }));
                                form.put('/exam-network', { preserveScroll: true });
                            }}
                        >
                            <Field
                                label="Address students should use"
                                hint="If the computer shows one address (say 192.168.0.12) but the campus network reaches it on another (say 10.10.0.109), type the one students can reach. The platform will answer for your institution on it."
                                error={form.errors.preferred_address}
                            >
                                <input className={`${inputClass} max-w-xs font-mono`} placeholder="10.10.0.109" value={form.data.preferred_address} onChange={(e) => form.setData('preferred_address', e.target.value)} />
                            </Field>
                            <label className="flex items-start gap-2 text-sm">
                                <input type="checkbox" className="mt-1" checked={form.data.auto_clear} onChange={(e) => form.setData('auto_clear', e.target.checked)} />
                                <span>Clear computer approvals automatically 15 minutes after each sitting ends, so every exam starts with a fresh list. Cleared lists are kept under History.</span>
                            </label>
                            <div><Button disabled={form.processing}>Save</Button></div>
                        </form>
                    </section>

                    <aside className="surface grid content-start justify-items-center gap-3 p-6 text-center">
                        <p className="text-sm font-medium">Students open</p>
                        <p className="font-mono text-lg break-all">{studentUrl}</p>
                        <div className="w-44 rounded-md bg-white p-2" dangerouslySetInnerHTML={{ __html: qr }} />
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => navigator.clipboard?.writeText(studentUrl).then(() => (setCopied(true), window.setTimeout(() => setCopied(false), 1500)))}
                        >
                            {copied ? <Check className="size-4" /> : <Copy className="size-4" />} {copied ? 'Copied' : 'Copy address'}
                        </Button>
                        <p className="text-xs text-muted-foreground">Put this on the hall screen or the board. You are on {server.browser_host}{server.port && ![80, 443].includes(server.port) ? `:${server.port}` : ''} now.</p>
                    </aside>
                </div>

                <section className="grid gap-4">
                    <div className="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <h2 className="text-base font-semibold">Hall computers</h2>
                            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">
                                For exams with "Only computers approved by IP address" switched on. A computer appears here the first time a student presses Start on it; approve it and the student presses Start again.
                            </p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" size="sm" onClick={() => router.reload({ only: ['computers', 'counts'] })}><RefreshCw className="size-4" /> Refresh</Button>
                            <Button size="sm" disabled={!counts.pending} onClick={() => router.post('/exam-network/approve-all', {}, { preserveScroll: true })}><Check className="size-4" /> Approve all waiting ({counts.pending})</Button>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={!computers.length}
                                onClick={() => {
                                    if (confirm('Clear the whole list? Every computer will need approving again. The cleared list is kept under History.')) {
                                        router.post('/exam-network/reset', {}, { preserveScroll: true });
                                    }
                                }}
                            >
                                <RotateCcw className="size-4" /> Reset approvals
                            </Button>
                        </div>
                    </div>
                    <div className="grid grid-cols-3 gap-3 md:max-w-xl">
                        <Stat label="Waiting" value={counts.pending} tone={counts.pending ? 'warning' : undefined} />
                        <Stat label="Approved" value={counts.approved} tone="good" />
                        <Stat label="Refused" value={counts.rejected} tone={counts.rejected ? 'critical' : undefined} />
                    </div>
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <div className="relative w-full max-w-xs">
                            <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                            <input className={`${inputClass} pl-9`} placeholder="IP, label or student" value={q} onChange={(e) => setQ(e.target.value)} />
                        </div>
                        {(['all', 'pending', 'approved', 'rejected'] as const).map((f) => (
                            <button key={f} onClick={() => setFilter(f)} className={`rounded-md px-3 py-1.5 ${filter === f ? 'bg-foreground text-background' : 'border border-border'}`}>
                                {{ all: 'All', pending: 'Waiting', approved: 'Approved', rejected: 'Refused' }[f]}
                            </button>
                        ))}
                    </div>
                    <Table head={['Computer', 'Label', 'Hall', 'Last student', 'Seen', 'Status', '']}>
                        {list.map((c) => (
                            <tr key={c.id} className={c.status === 'pending' ? 'bg-status-warning/5' : ''}>
                                <Td>
                                    <span className="font-mono">{c.ip}</span>
                                    {c.browser && <span className="block text-xs text-muted-foreground">{c.browser}</span>}
                                </Td>
                                <Td>
                                    <input
                                        className="h-8 w-40 rounded-md border border-transparent bg-transparent px-2 text-sm hover:border-input focus:border-brand"
                                        defaultValue={c.label ?? ''}
                                        placeholder="Add a label"
                                        onBlur={(e) => e.target.value !== (c.label ?? '') && router.put(`/exam-network/computers/${c.id}`, { label: e.target.value || null }, { preserveScroll: true, only: ['computers'] })}
                                    />
                                </Td>
                                <Td>
                                    <select
                                        className="h-8 rounded-md border border-input bg-card px-2 text-sm"
                                        value={c.venue_id ?? ''}
                                        onChange={(e) => router.put(`/exam-network/computers/${c.id}`, { venue_id: e.target.value ? Number(e.target.value) : null }, { preserveScroll: true, only: ['computers'] })}
                                    >
                                        <option value="">—</option>
                                        {venues.map((v) => <option key={v.id} value={v.id}>{v.name}</option>)}
                                    </select>
                                </Td>
                                <Td>{c.student ? <>{c.student.name}<span className="block font-mono text-xs text-muted-foreground">{c.student.identifier}</span></> : '—'}</Td>
                                <Td className="text-xs whitespace-nowrap">{formatDateTime(c.last_seen_at)}<span className="block text-muted-foreground">{c.requests} request{c.requests === 1 ? '' : 's'}</span></Td>
                                <Td>
                                    <Status value={c.status === 'rejected' ? 'refused' : c.status === 'pending' ? 'waiting' : 'approved'} />
                                    {c.decided_by && <span className="block text-xs text-muted-foreground">by {c.decided_by}</span>}
                                </Td>
                                <Td className="whitespace-nowrap">
                                    <div className="flex gap-1">
                                        {c.status !== 'approved' && <Button size="sm" onClick={() => decide(c, 'approved')}>Approve</Button>}
                                        {c.status !== 'rejected' && <Button size="sm" variant="outline" onClick={() => decide(c, 'rejected')}>Refuse</Button>}
                                        <Button size="sm" variant="ghost" aria-label="Remove from the list" title="Remove from the list (kept in history)" onClick={() => router.delete(`/exam-network/computers/${c.id}`, { preserveScroll: true })}>
                                            <X className="size-4" />
                                        </Button>
                                    </div>
                                </Td>
                            </tr>
                        ))}
                        {list.length === 0 && (
                            <tr><Td className="text-sm text-muted-foreground">{computers.length ? 'Nothing matches.' : 'No computer has asked yet.'}</Td></tr>
                        )}
                    </Table>
                </section>

                <section className="grid gap-3">
                    <h2 className="flex items-center gap-2 text-base font-semibold"><History className="size-4" /> History</h2>
                    <p className="text-sm text-muted-foreground">Every cleared list, so you can trace which computers were allowed or refused for each exam.</p>
                    <Table head={['Cleared', 'Why', 'Computers', 'Approved', 'Refused', 'Waiting', '']}>
                        {history.map((h) => (
                            <tr key={h.cleared_at}>
                                <Td className="whitespace-nowrap">{formatDateTime(h.cleared_at)}{h.by && <span className="block text-xs text-muted-foreground">{h.by}</span>}</Td>
                                <Td className="text-sm">{h.reason}</Td>
                                <Td className="tabular-nums">{h.total}</Td>
                                <Td className="tabular-nums">{h.approved}</Td>
                                <Td className="tabular-nums">{h.rejected}</Td>
                                <Td className="tabular-nums">{h.pending}</Td>
                                <Td><Button size="sm" variant="outline" onClick={() => (setBatch(null), setOpen(open === h.cleared_at ? null : h.cleared_at))}>{open === h.cleared_at ? 'Hide' : 'Show computers'}</Button></Td>
                            </tr>
                        ))}
                        {history.length === 0 && (
                            <tr><Td className="text-sm text-muted-foreground">Nothing cleared yet.</Td></tr>
                        )}
                    </Table>
                    {open && (
                        <div className="surface p-4">
                            {!batch ? (
                                <p className="text-sm text-muted-foreground">Loading…</p>
                            ) : (
                                <Table head={['Computer', 'Label', 'Hall', 'Last student', 'Decision', 'Decided']}>
                                    {batch.map((c) => (
                                        <tr key={c.id}>
                                            <Td className="font-mono">{c.ip}</Td>
                                            <Td>{c.label ?? '—'}</Td>
                                            <Td>{c.venue ?? '—'}</Td>
                                            <Td>{c.student ? `${c.student.name} (${c.student.identifier ?? '—'})` : '—'}</Td>
                                            <Td><Status value={c.status === 'rejected' ? 'refused' : c.status === 'pending' ? 'waiting' : 'approved'} /></Td>
                                            <Td className="text-xs">{c.decided_at ? `${formatDateTime(c.decided_at)}${c.decided_by ? ` · ${c.decided_by}` : ''}` : '—'}</Td>
                                        </tr>
                                    ))}
                                </Table>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

ExamNetwork.layout = { breadcrumbs: [{ title: 'Exams', href: '/exams' }, { title: 'Exam network', href: '/exam-network' }] };
