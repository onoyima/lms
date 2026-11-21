import { Head, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Copy, ShieldCheck, TriangleAlert, XCircle } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { formatDateTime, PageHeader, Stat, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Result = { key: string; ok: boolean; warn: boolean; detail: string };
type Row = { id: number; ip: string; label: string | null; status: 'pass' | 'warn' | 'fail'; results: Result[]; browser: string; last_seen: string | null; approval: string | null };
type Check = { id: number; name: string; code: string; open: boolean; closes_at: string | null; exam: string | null; tests: { key: string; label: string }[]; urls: string[]; url: string };

/** One computer check: the link for lab computers and every computer's result, refreshed as they report. */
export default function ComputerCheckPage({ check, rows: initial, qr }: { check: Check; rows: Row[]; qr: string }) {
    const { flash } = usePage().props as { flash?: { success?: string | null } };
    const [rows, setRows] = useState(initial);
    const [only, setOnly] = useState<'all' | 'fail'>('all');
    const [copied, setCopied] = useState(false);

    useEffect(() => {
        const t = window.setInterval(async () => {
            const res = await fetch(`/computer-checks/${check.id}/rows`, { headers: { Accept: 'application/json' } });

            if (res.ok) {
                setRows((await res.json()).rows);
            }
        }, 5000);

        return () => window.clearInterval(t);
    }, [check.id]);

    const counts = useMemo(() => ({ pass: rows.filter((r) => r.status === 'pass').length, warn: rows.filter((r) => r.status === 'warn').length, fail: rows.filter((r) => r.status === 'fail').length }), [rows]);
    const shown = only === 'fail' ? rows.filter((r) => r.status !== 'pass') : rows;
    const links = [...check.urls, check.url].filter((v, i, a) => a.indexOf(v) === i);

    return (
        <>
            <Head title={check.name} />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title={check.name} lead={check.exam ? `Testing what ${check.exam} needs.` : 'Testing the basics every exam needs.'}
                    actions={check.open && (
                        <>
                            <Button variant="outline" onClick={() => router.post(`/computer-checks/${check.id}/approve`, {}, { preserveScroll: true })}><ShieldCheck className="size-4" /> Approve computers that passed</Button>
                            <Button variant="outline" onClick={() => confirm('Close this check? Computers can no longer report.') && router.post(`/computer-checks/${check.id}/close`)}>Close check</Button>
                        </>
                    )} />
                {flash?.success && <p className="rounded-lg bg-status-good/10 p-3 text-sm">{flash.success}</p>}

                <section className="surface grid gap-5 p-5 md:grid-cols-[1fr_auto]">
                    <div className="grid content-start gap-3">
                        <p className="font-medium">On each lab computer, open:</p>
                        {links.map((u) => (
                            <div key={u} className="flex flex-wrap items-center gap-2">
                                <code className="rounded-lg bg-muted px-3 py-2 font-mono text-base">{u}</code>
                                <Button size="sm" variant="ghost" onClick={() => (navigator.clipboard.writeText(u), setCopied(true), window.setTimeout(() => setCopied(false), 1500))}><Copy className="size-4" /> {copied ? 'Copied' : 'Copy'}</Button>
                            </div>
                        ))}
                        <p className="text-sm text-muted-foreground">No sign-in needed. The computer tests itself in about 15 seconds and its result appears below with its IP address. Tests: {check.tests.map((t) => t.label).join(', ')}.</p>
                        <p className="text-sm">{check.open ? `Open until ${formatDateTime(check.closes_at)}.` : 'This check is closed.'}</p>
                    </div>
                    <div className="rounded-lg bg-white p-2 [&_svg]:size-40" dangerouslySetInnerHTML={{ __html: qr }} aria-label="QR code of the link" />
                </section>

                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <Stat label="Computers" value={rows.length} />
                    <Stat label="Ready" value={counts.pass} tone="good" />
                    <Stat label="Ready, with warnings" value={counts.warn} tone={counts.warn ? 'warning' : undefined} />
                    <Stat label="Not ready" value={counts.fail} tone={counts.fail ? 'critical' : undefined} />
                </div>

                <div className="flex gap-1.5" role="tablist">
                    {(['all', 'fail'] as const).map((k) => (
                        <button key={k} role="tab" aria-selected={only === k} onClick={() => setOnly(k)} className={`rounded-lg px-3 py-1.5 text-xs font-semibold ${only === k ? 'bg-brand text-brand-foreground' : 'bg-muted/60'}`}>{k === 'all' ? `All (${rows.length})` : `Problems (${counts.fail + counts.warn})`}</button>
                    ))}
                </div>

                {shown.length === 0 ? (
                    <p className="surface p-6 text-sm text-muted-foreground">{rows.length ? 'No problems.' : 'No computer has reported yet. Open the link on a lab computer.'}</p>
                ) : (
                    <Table head={['Computer (IP)', 'Result', 'What was found', 'Browser', 'Approved', 'Reported']}>
                        {shown.map((r) => (
                            <tr key={r.id} className="border-t border-border align-top">
                                <Td><span className="font-mono font-semibold">{r.ip}</span>{r.label && <span className="block text-xs text-muted-foreground">{r.label}</span>}</Td>
                                <Td>
                                    {r.status === 'pass' && <span className="flex items-center gap-1 text-status-good"><CheckCircle2 className="size-4" /> Ready</span>}
                                    {r.status === 'warn' && <span className="flex items-center gap-1 text-status-warning"><TriangleAlert className="size-4" /> Ready, check</span>}
                                    {r.status === 'fail' && <span className="flex items-center gap-1 font-semibold text-status-critical"><XCircle className="size-4" /> Not ready</span>}
                                </Td>
                                <Td>
                                    <ul className="grid gap-0.5 text-sm">
                                        {r.results.filter((x) => !x.ok || x.warn).map((x) => <li key={x.key} className={x.ok ? 'text-status-warning' : 'text-status-critical'}>{check.tests.find((t) => t.key === x.key)?.label}: {x.detail}</li>)}
                                        {r.results.every((x) => x.ok && !x.warn) && <li className="text-muted-foreground">All tests passed</li>}
                                    </ul>
                                </Td>
                                <Td className="text-sm">{r.browser}</Td>
                                <Td className="text-sm">{r.approval ?? '—'}</Td>
                                <Td className="whitespace-nowrap text-sm">{formatDateTime(r.last_seen)}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </>
    );
}

ComputerCheckPage.layout = { breadcrumbs: [{ title: 'Exam day', href: '/proctor' }, { title: 'Computer checks', href: '/computer-checks' }, { title: 'Check', href: '#' }] };
