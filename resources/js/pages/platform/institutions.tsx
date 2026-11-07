import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Row = {
    id: number; name: string; slug: string; status: string; reason: string | null; plan: string | null;
    subscription: { status: string; ends: string | null } | null;
    usage: { students: number; staff: number; attempts_30d: number }; domains: string[];
};
type PlanOpt = { id: number; name: string; key: string };

export default function Institutions({ institutions, plans, filters }: { institutions: Row[]; plans: PlanOpt[]; filters: { q?: string; status?: string } }) {
    const [q, setQ] = useState(filters.q ?? '');
    const [adding, setAdding] = useState(false);
    const form = useForm({ name: '', slug: '', domains: '', plan_id: plans[1]?.id ?? plans[0]?.id ?? '', cycle: 'yearly', trial_days: 30, admin_name: '', admin_email: '', contact_phone: '' });
    const search = (status?: string) => router.get('/platform/institutions', { q: q || undefined, status: status ?? filters.status }, { preserveState: true, replace: true });

    return (
        <>
            <Head title="Institutions" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Institutions" lead="Every institution using the platform, its plan and its size. Open one to change its subscription, modules, invoices or status."
                    actions={<Button onClick={() => setAdding((v) => !v)}>{adding ? 'Close' : 'Add institution'}</Button>} />
                <Flash />
                {adding && (
                    <form className="surface grid gap-3 p-5 md:grid-cols-3" onSubmit={(e) => {
                        e.preventDefault();
                        form.transform((d) => ({ ...d, domains: d.domains.split(/[\s,]+/).filter(Boolean) }));
                form.post('/platform/institutions');
                    }}>
                        <Field label="Institution name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                        <Field label="Short name (web address)" hint="letters, numbers and dashes" error={form.errors.slug}><input className={inputClass} value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value.toLowerCase())} required /></Field>
                        <Field label="Domains" hint="e.g. exams.uni.edu.ng, separated by spaces" error={form.errors.domains}><input className={inputClass} value={form.data.domains} onChange={(e) => form.setData('domains', e.target.value)} /></Field>
                        <Field label="Plan" error={form.errors.plan_id}>
                            <select className={inputClass} value={form.data.plan_id} onChange={(e) => form.setData('plan_id', Number(e.target.value))}>{plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}</select>
                        </Field>
                        <Field label="Billing cycle"><select className={inputClass} value={form.data.cycle} onChange={(e) => form.setData('cycle', e.target.value)}><option value="yearly">Yearly</option><option value="monthly">Monthly</option></select></Field>
                        <Field label="Free trial (days)" error={form.errors.trial_days}><input type="number" min={0} max={90} className={inputClass} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', Number(e.target.value))} /></Field>
                        <Field label="First administrator's name" error={form.errors.admin_name}><input className={inputClass} value={form.data.admin_name} onChange={(e) => form.setData('admin_name', e.target.value)} required /></Field>
                        <Field label="Administrator's email" hint="receives a link to set their own password" error={form.errors.admin_email}><input type="email" className={inputClass} value={form.data.admin_email} onChange={(e) => form.setData('admin_email', e.target.value)} required /></Field>
                        <Field label="Phone" error={form.errors.contact_phone}><input className={inputClass} value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} /></Field>
                        <Button className="w-fit" disabled={form.processing}>Create institution</Button>
                    </form>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <input className={`${inputClass} max-w-xs`} placeholder="Search name or short name" value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && search()} />
                    {['', 'active', 'suspended'].map((s) => (
                        <Button key={s} size="sm" variant={(filters.status ?? '') === s ? 'default' : 'outline'} onClick={() => search(s || '')}>{s || 'All'}</Button>
                    ))}
                </div>
                <Table head={['Institution', 'Plan', 'Subscription', 'Students', 'Staff', 'Exams (30 days)', 'Status']}>
                    {institutions.length === 0 && <tr><Td className="text-muted-foreground">No institutions match.</Td></tr>}
                    {institutions.map((t) => (
                        <tr key={t.id}>
                            <Td><Link href={`/platform/institutions/${t.id}`} className="font-medium hover:underline">{t.name}</Link><span className="block font-mono text-xs text-muted-foreground">{t.slug}{t.domains[0] ? ` · ${t.domains[0]}` : ''}</span></Td>
                            <Td>{t.plan ?? '—'}</Td>
                            <Td>{t.subscription ? <><Status value={t.subscription.status} /><span className="block text-xs text-muted-foreground">to {formatDateTime(t.subscription.ends)}</span></> : '—'}</Td>
                            <Td className="tabular-nums">{t.usage.students.toLocaleString()}</Td>
                            <Td className="tabular-nums">{t.usage.staff.toLocaleString()}</Td>
                            <Td className="tabular-nums">{t.usage.attempts_30d.toLocaleString()}</Td>
                            <Td><Status value={t.status} />{t.reason && <span className="block text-xs text-muted-foreground">{t.reason.replace('_', ' ')}</span>}</Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

Institutions.layout = { breadcrumbs: [{ title: 'Institutions', href: '/platform/institutions' }] };
