import { Head, router } from '@inertiajs/react';
import { FileSpreadsheet, FileText, Download } from 'lucide-react';
import { useState } from 'react';
import { Flash, formatDateTime, inputClass, PageHeader, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Opt = { id: number; name: string; faculty_id?: number };
type Props = {
    reports: { key: string; title: string; group: string; personal: boolean }[];
    current: string | null;
    result: { columns: string[]; rows: Record<string, unknown>[]; personal: boolean; title: string; truncated: boolean } | null;
    filters: Record<string, string>;
    options: { sessions: Opt[]; semesters: Opt[]; faculties: Opt[]; departments: Opt[] };
    approvalRows: number;
    myRequests: { id: number; report: string; format: string; rows: number; status: string; created_at: string; expires_at: string | null }[];
    pending: { id: number; name: string; report: string; format: string; rows: number; reason: string; created_at: string }[];
};

export default function Reports({ reports, current, result, filters, options, approvalRows, myRequests, pending }: Props) {
    const [f, setF] = useState<Record<string, string>>(filters);
    const query = (extra: Record<string, string> = {}) => new URLSearchParams(Object.entries({ ...f, ...extra }).filter(([, v]) => v !== '' && v !== undefined) as [string, string][]).toString();
    const run = (key: string) => router.get('/admin/reports', { report: key, ...Object.fromEntries(Object.entries(f).filter(([, v]) => v)) }, { preserveScroll: true });
    const groups = [...new Set(reports.map((r) => r.group))];
    const needsApproval = result?.personal && result.rows.length > approvalRows;

    return (
        <>
            <Head title="Reports" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Reports and exports" lead="Every report can be filtered and downloaded as Excel, CSV or PDF. Heads of department and deans only see their own department or faculty." />
                <Flash />
                <div className="grid gap-6 lg:grid-cols-[280px_1fr]">
                    <aside className="grid content-start gap-4">
                        {groups.map((g) => (
                            <div key={g}>
                                <p className="eyebrow mb-2">{g}</p>
                                {reports.filter((r) => r.group === g).map((r) => (
                                    <button key={r.key} onClick={() => run(r.key)} className={`block w-full rounded-lg px-3 py-2 text-left text-sm ${current === r.key ? 'bg-brand-soft text-brand' : 'hover:bg-muted'}`}>
                                        {r.title}{r.personal && <span className="ml-1 text-[10px] text-muted-foreground">(personal data)</span>}
                                    </button>
                                ))}
                            </div>
                        ))}
                    </aside>
                    <main className="grid content-start gap-4">
                        <div className="surface grid gap-2 p-4 md:grid-cols-4">
                            <select className={inputClass} value={f.session_id ?? ''} onChange={(e) => setF({ ...f, session_id: e.target.value })}><option value="">All sessions</option>{options.sessions.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</select>
                            <select className={inputClass} value={f.semester_id ?? ''} onChange={(e) => setF({ ...f, semester_id: e.target.value })}><option value="">All semesters</option>{options.semesters.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</select>
                            <select className={inputClass} value={f.faculty_id ?? ''} onChange={(e) => setF({ ...f, faculty_id: e.target.value, department_id: '' })}><option value="">All faculties</option>{options.faculties.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</select>
                            <select className={inputClass} value={f.department_id ?? ''} onChange={(e) => setF({ ...f, department_id: e.target.value })}><option value="">All departments</option>{options.departments.filter((d) => !f.faculty_id || String(d.faculty_id) === f.faculty_id).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}</select>
                            <select className={inputClass} value={f.level ?? ''} onChange={(e) => setF({ ...f, level: e.target.value })}><option value="">All levels</option>{[100, 200, 300, 400, 500, 600].map((l) => <option key={l} value={l}>{l} level</option>)}</select>
                            <input type="date" className={inputClass} value={f.from ?? ''} onChange={(e) => setF({ ...f, from: e.target.value })} aria-label="From" />
                            <input type="date" className={inputClass} value={f.to ?? ''} onChange={(e) => setF({ ...f, to: e.target.value })} aria-label="To" />
                            <Button disabled={!current} onClick={() => current && run(current)}>Apply</Button>
                        </div>
                        {!result && <div className="surface p-10 text-center text-sm text-muted-foreground">Choose a report on the left.</div>}
                        {result && (
                            <div className="grid gap-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <p className="text-lg font-medium">{result.title} <span className="text-sm text-muted-foreground">· {result.rows.length}{result.truncated ? '+' : ''} rows</span></p>
                                    <div className="flex gap-2">
                                        {needsApproval ? (
                                            <Button size="sm" variant="outline" onClick={() => {
 const reason = prompt('Why do you need this personal data? (seen by the approver)');

 if (reason) {
router.post(`/admin/reports/${current}/request/xlsx?${query()}`, { reason }, { preserveScroll: true });
} 
}}>Request approval to export</Button>
                                        ) : null}
                                        <a className="flex items-center gap-1 rounded-xl border border-border px-3 py-1.5 text-sm" href={`/admin/reports/${current}/export/xlsx?${query()}`}><FileSpreadsheet className="size-4" /> Excel</a>
                                        <a className="flex items-center gap-1 rounded-xl border border-border px-3 py-1.5 text-sm" href={`/admin/reports/${current}/export/csv?${query()}`}><Download className="size-4" /> CSV</a>
                                        <a className="flex items-center gap-1 rounded-xl border border-border px-3 py-1.5 text-sm" href={`/admin/reports/${current}/export/pdf?${query()}`}><FileText className="size-4" /> PDF</a>
                                    </div>
                                </div>
                                <div className="surface overflow-x-auto">
                                    <table className="w-full text-sm">
                                        <thead><tr className="border-b border-border text-left">{result.columns.map((c) => <th key={c} className="p-3 font-medium whitespace-nowrap">{c}</th>)}</tr></thead>
                                        <tbody>{result.rows.map((r, i) => <tr key={i} className="border-b border-border/60">{Object.values(r).map((v, j) => <td key={j} className="p-3 whitespace-nowrap tabular-nums">{v === null || v === undefined ? '—' : String(v)}</td>)}</tr>)}</tbody>
                                    </table>
                                </div>
                                {result.truncated && <p className="text-xs text-muted-foreground">Showing the first 500 rows. Downloads contain everything.</p>}
                            </div>
                        )}
                        {pending.length > 0 && (
                            <div className="surface grid gap-2 p-5">
                                <p className="eyebrow">Export requests waiting for you</p>
                                {pending.map((r) => (
                                    <div key={r.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                                        <span>{r.name} · {r.report} ({r.rows} rows, {r.format}) — “{r.reason}”</span>
                                        <span className="flex gap-2"><Button size="sm" onClick={() => router.post(`/admin/reports/requests/${r.id}`, { approve: true }, { preserveScroll: true })}>Approve</Button><Button size="sm" variant="outline" onClick={() => router.post(`/admin/reports/requests/${r.id}`, { approve: false }, { preserveScroll: true })}>Reject</Button></span>
                                    </div>
                                ))}
                            </div>
                        )}
                        {myRequests.length > 0 && (
                            <div className="surface grid gap-1 p-5 text-sm">
                                <p className="eyebrow">My export requests</p>
                                {myRequests.map((r) => <p key={r.id}>{r.report} · {r.rows} rows · <Status value={r.status} /> {r.expires_at && r.status === 'approved' && `until ${formatDateTime(r.expires_at)}`}</p>)}
                            </div>
                        )}
                    </main>
                </div>
            </div>
        </>
    );
}
