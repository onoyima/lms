import { Head, router } from '@inertiajs/react';
import { CheckCircle2, CircleAlert, XCircle } from 'lucide-react';
import { Flash, formatDateTime, PageHeader, Stat } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Row = { area: string; check: string; status: 'pass' | 'warn' | 'fail'; detail: string };

/** Security self-check results (exams office and ICT procedure). The check reads settings and counts only. */
export default function Security({ last }: { last: { at: string; results: Row[] } | null }) {
    const rows = last?.results ?? [];
    const areas = [...new Set(rows.map((r) => r.area))];
    const count = (s: string) => rows.filter((r) => r.status === s).length;
    const icon = { pass: <CheckCircle2 className="size-4 text-status-good" />, warn: <CircleAlert className="size-4 text-status-warning" />, fail: <XCircle className="size-4 text-status-critical" /> };

    return (
        <>
            <Head title="Security check" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Security check" lead="Run before each exam season and after every upgrade. It checks settings, accounts, keys, backups and every institution's audit trail, and changes nothing. The same check runs every Monday and from the command line: php artisan security:check."
                    actions={<Button onClick={() => router.post('/platform/security', {}, { preserveScroll: true })}>Run the check now</Button>} />
                <Flash />
                {!last ? <p className="text-muted-foreground">The check has not run yet.</p> : (
                    <>
                        <p className="text-sm text-muted-foreground">Last run {formatDateTime(last.at)}</p>
                        <div className="grid gap-4 sm:grid-cols-3">
                            <Stat label="Failed" value={count('fail')} tone={count('fail') ? 'critical' : undefined} />
                            <Stat label="To look at" value={count('warn')} tone={count('warn') ? 'warning' : undefined} />
                            <Stat label="Passed" value={count('pass')} tone="good" />
                        </div>
                        {areas.map((a) => (
                            <section key={a} className="surface divide-y">
                                <h2 className="px-5 py-3 font-medium">{a}</h2>
                                {rows.filter((r) => r.area === a).map((r, i) => (
                                    <div key={i} className="flex items-start gap-3 px-5 py-2.5 text-sm">
                                        <span className="mt-0.5" aria-label={r.status}>{icon[r.status]}</span>
                                        <span className="flex-1">{r.check}{r.detail && r.status !== 'pass' && <span className="block text-xs text-muted-foreground">{r.detail}</span>}</span>
                                    </div>
                                ))}
                            </section>
                        ))}
                    </>
                )}
            </div>
        </>
    );
}

Security.layout = { breadcrumbs: [{ title: 'Security check', href: '/platform/security' }] };
