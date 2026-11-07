import { Head, router } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { Flash, formatDateTime, PageHeader, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Row = { user_id: number; name: string; email: string; status: string; role: string; two_factor_confirmed_at: string | null; last_login: string | null };

export default function AccessReview({ rows, last, everyDays }: { rows: Row[]; last: string | null; everyDays: number }) {
    const [keep, setKeep] = useState<Record<string, boolean>>(Object.fromEntries(rows.map((r) => [`${r.user_id}:${r.role}`, true])));
    const [notes, setNotes] = useState<Record<string, string>>({});
    const [now] = useState(() => Date.now());
    const stale = (d: string | null) => !d || now - new Date(d).getTime() > 90 * 86400000;

    return (
        <>
            <Head title="Access review" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Access review" lead={`Confirm that everyone still needs their role. Due every ${everyDays} days. Last completed: ${last ? formatDateTime(last) : 'never'}.`} />
                <Flash />
                <div className="surface overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead><tr className="border-b border-border text-left"><th className="p-3">Person</th><th className="p-3">Role</th><th className="p-3">Two-factor</th><th className="p-3">Last sign-in</th><th className="p-3">Decision</th><th className="p-3">Note</th></tr></thead>
                        <tbody>
                            {rows.map((r) => {
                                const k = `${r.user_id}:${r.role}`;

                                return (
                                    <tr key={k} className={`border-b border-border/60 ${keep[k] ? '' : 'bg-status-critical/5'}`}>
                                        <td className="p-3">{r.name}<span className="block text-xs text-muted-foreground">{r.email}</span></td>
                                        <td className="p-3">{r.role.replace(/_/g, ' ')} {r.status !== 'active' && <Status value={r.status} />}</td>
                                        <td className="p-3">{r.two_factor_confirmed_at ? <ShieldCheck className="size-4 text-status-good" /> : <span className="text-xs text-status-warning">off</span>}</td>
                                        <td className={`p-3 ${stale(r.last_login) ? 'text-status-warning' : ''}`}>{r.last_login ? formatDateTime(r.last_login) : 'never'}</td>
                                        <td className="p-3">
                                            <select className="rounded-lg border border-border bg-background px-2 py-1" value={keep[k] ? 'keep' : 'remove'} onChange={(e) => setKeep({ ...keep, [k]: e.target.value === 'keep' })}><option value="keep">Keep</option><option value="remove">Remove role</option></select>
                                        </td>
                                        <td className="p-3"><input className="w-48 rounded-lg border border-border bg-background px-2 py-1" value={notes[k] ?? ''} onChange={(e) => setNotes({ ...notes, [k]: e.target.value })} /></td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
                <Button className="w-fit" onClick={() => confirm('Record this review and remove the roles marked "Remove"?') && router.post('/admin/access-review', { decisions: rows.map((r) => ({ user_id: r.user_id, role: r.role, keep: keep[`${r.user_id}:${r.role}`], note: notes[`${r.user_id}:${r.role}`] ?? null })) }, { preserveScroll: true })}>
                    Complete review
                </Button>
            </div>
        </>
    );
}
