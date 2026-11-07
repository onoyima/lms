import { Head, Link } from '@inertiajs/react';
import { Flash, formatDateTime, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';

type Stats = {
    institutions: number; active: number; suspended: number; trialing: number; past_due: number;
    mrr: string; collected_30d: string; open_invoices: number; applications: number; students: number;
    users: number; active_users: number; outstanding: string; renewed_30d: number; failed_30d: number; expired_30d: number; storage_gb: number;
};
type Revenue = { month: string; amount: number };
type ByPlan = { plan: string; institutions: number };
type Security = { incidents_7d: number; failed_logins_24h: number; api_calls_month: number; support_sessions_30d: number; maintenance: boolean };
type PaymentRow = { institution: string | null; amount: string; provider: string; status: string; at: string };
type Expiring = { institution: string | null; tenant_id: number; plan: string | null; status: string; ends: string };

/** The platform operator's front page: the business at a glance. Counts only — no institution records. */
export default function Overview({ stats, recentPayments, expiring, revenue, byPlan, security, moduleUsage }: { stats: Stats; recentPayments: PaymentRow[]; expiring: Expiring[]; revenue: Revenue[]; byPlan: ByPlan[]; security: Security; moduleUsage: { module: string; institutions: number }[] }) {
    const top = Math.max(1, ...revenue.map((r) => r.amount));

    return (
        <>
            <Head title="Platform overview" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Overview" lead="Institutions, subscriptions and money. Institution data (people, exams, results) stays with each institution and is not visible here." />
                <Flash />
                {security.maintenance && <p className="rounded-lg border border-status-warning/40 bg-status-warning/10 p-3 text-sm"><b>Maintenance mode is on.</b> Institutions see the maintenance page. <Link className="underline" href="/platform/settings">Switch it off</Link></p>}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                    <Stat label="Institutions" value={stats.institutions} />
                    <Stat label="Active" value={stats.active} tone="good" />
                    <Stat label="On trial" value={stats.trialing} />
                    <Stat label="Past due" value={stats.past_due} tone={stats.past_due ? 'warning' : undefined} />
                    <Stat label="Suspended" value={stats.suspended} tone={stats.suspended ? 'critical' : undefined} />
                    <Stat label="Recurring (monthly)" value={stats.mrr} />
                    <Stat label="Collected, 30 days" value={stats.collected_30d} tone="good" />
                    <Stat label="Open invoices" value={stats.open_invoices} />
                    <Stat label="Applications waiting" value={<Link href="/platform/applications" className="underline-offset-4 hover:underline">{stats.applications}</Link>} tone={stats.applications ? 'warning' : undefined} />
                    <Stat label="Active students (all)" value={stats.students.toLocaleString()} />
                    <Stat label="Outstanding" value={stats.outstanding} tone={stats.open_invoices ? 'warning' : undefined} />
                    <Stat label="Renewals, 30 days" value={stats.renewed_30d} tone="good" />
                    <Stat label="Failed payments, 30 days" value={stats.failed_30d} tone={stats.failed_30d ? 'critical' : undefined} />
                    <Stat label="Expired or suspended, 30 days" value={stats.expired_30d} tone={stats.expired_30d ? 'warning' : undefined} />
                    <Stat label="Users (active / all)" value={`${stats.active_users.toLocaleString()} / ${stats.users.toLocaleString()}`} />
                </div>
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Security incidents, 7 days" value={<Link href="/platform/security" className="hover:underline">{security.incidents_7d}</Link>} tone={security.incidents_7d ? 'warning' : undefined} />
                    <Stat label="Failed sign-ins, 24 h" value={security.failed_logins_24h} tone={security.failed_logins_24h > 50 ? 'critical' : undefined} />
                    <Stat label="API calls this month" value={security.api_calls_month.toLocaleString()} />
                    <Stat label="Support sign-ins, 30 days" value={<Link href="/platform/audit?q=support" className="hover:underline">{security.support_sessions_30d}</Link>} />
                </div>
                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="surface flex flex-col gap-3 p-5 lg:col-span-2">
                        <h2 className="text-lg font-medium">Money received, last 12 months</h2>
                        <div className="flex h-40 items-end gap-1.5" role="img" aria-label="Monthly revenue">
                            {revenue.map((r) => (
                                <div key={r.month} className="group flex flex-1 flex-col items-center gap-1" title={`${r.month}: ${r.amount.toLocaleString()}`}>
                                    <div className="w-full rounded-t-md bg-brand/80 group-hover:bg-brand" style={{ height: `${Math.max(2, (r.amount / top) * 128)}px` }} />
                                    <span className="text-[10px] text-muted-foreground">{r.month.split(' ')[0]}</span>
                                </div>
                            ))}
                        </div>
                    </section>
                    <section className="surface flex flex-col gap-3 p-5">
                        <h2 className="text-lg font-medium">Institutions per package</h2>
                        <ul className="flex flex-col gap-2 text-sm">
                            {byPlan.map((p) => <li key={p.plan} className="flex justify-between"><span>{p.plan}</span><span className="tabular-nums font-medium">{p.institutions}</span></li>)}
                        </ul>
                        <h3 className="mt-2 text-sm font-medium">Modules in use</h3>
                        <ul className="flex flex-col gap-1 text-xs">
                            {moduleUsage.map((m) => <li key={m.module} className="flex justify-between"><span>{m.module}</span><span className="tabular-nums">{m.institutions}</span></li>)}
                        </ul>
                        <p className="mt-auto text-xs text-muted-foreground">Stored files across the platform: {stats.storage_gb} GB · <Link className="underline" href="/platform/health">System health</Link></p>
                    </section>
                </div>
                <div className="grid gap-6 lg:grid-cols-2">
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-medium">Renewals in the next 30 days</h2>
                        <Table head={['Institution', 'Plan', 'Status', 'Ends']}>
                            {expiring.length === 0 && <tr><Td className="text-muted-foreground">Nothing due.</Td></tr>}
                            {expiring.map((e) => (
                                <tr key={e.tenant_id}>
                                    <Td><Link className="font-medium hover:underline" href={`/platform/institutions/${e.tenant_id}`}>{e.institution}</Link></Td>
                                    <Td>{e.plan}</Td>
                                    <Td><Status value={e.status} /></Td>
                                    <Td>{formatDateTime(e.ends)}</Td>
                                </tr>
                            ))}
                        </Table>
                    </section>
                    <section className="flex flex-col gap-3">
                        <h2 className="text-lg font-medium">Latest payments</h2>
                        <Table head={['Institution', 'Amount', 'Method', 'Status', 'When']}>
                            {recentPayments.length === 0 && <tr><Td className="text-muted-foreground">No payments yet.</Td></tr>}
                            {recentPayments.map((p, i) => (
                                <tr key={i}>
                                    <Td>{p.institution}</Td>
                                    <Td className="font-medium tabular-nums">{p.amount}</Td>
                                    <Td>{p.provider.replace('_', ' ')}</Td>
                                    <Td><Status value={p.status} /></Td>
                                    <Td>{formatDateTime(p.at)}</Td>
                                </tr>
                            ))}
                        </Table>
                    </section>
                </div>
            </div>
        </>
    );
}

Overview.layout = { breadcrumbs: [{ title: 'Platform', href: '/platform' }] };
