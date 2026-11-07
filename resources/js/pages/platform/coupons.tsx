import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Coupon = {
    id: number; code: string; description: string | null; kind: 'percent' | 'fixed'; value: number; currency: string; plan_ids: number[] | null; cycles: string[] | null;
    max_redemptions: number | null; per_tenant: number; redeemed: number; starts_at: string | null; ends_at: string | null; active: boolean;
};
type Props = { coupons: Coupon[]; plans: { id: number; name: string }[]; cycles: Record<string, string> };

/** Discount codes institutions type on an open invoice. */
export default function Coupons({ coupons, plans, cycles }: Props) {
    const [editing, setEditing] = useState<Coupon | 'new' | null>(null);

    return (
        <>
            <Head title="Discount codes" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Discount" accent="codes" lead="Promotions and negotiated deals. An institution applies a code to an open invoice from its billing page; each use is recorded."
                    actions={<Button onClick={() => setEditing(editing ? null : 'new')}>{editing ? 'Close' : 'New code'}</Button>} />
                <Flash />
                {editing && <CouponForm key={editing === 'new' ? 'new' : editing.id} coupon={editing === 'new' ? undefined : editing} plans={plans} cycles={cycles} onDone={() => setEditing(null)} />}
                <Table head={['Code', 'Discount', 'Limits', 'Used', 'Valid', 'Status', '']}>
                    {coupons.length === 0 && <tr><Td className="text-muted-foreground">No codes yet.</Td></tr>}
                    {coupons.map((c) => (
                        <tr key={c.id}>
                            <Td className="font-mono">{c.code}<span className="block font-sans text-xs text-muted-foreground">{c.description}</span></Td>
                            <Td>{c.kind === 'percent' ? `${c.value}%` : `${c.currency} ${(c.value / 100).toLocaleString()}`}</Td>
                            <Td className="text-xs">{c.plan_ids ? plans.filter((p) => c.plan_ids!.includes(p.id)).map((p) => p.name).join(', ') : 'Any package'} · {c.cycles ? c.cycles.map((x) => cycles[x]).join(', ') : 'any period'}</Td>
                            <Td className="tabular-nums">{c.redeemed}{c.max_redemptions ? ` / ${c.max_redemptions}` : ''}</Td>
                            <Td className="text-xs">{c.starts_at ? formatDateTime(c.starts_at) : 'now'} – {c.ends_at ? formatDateTime(c.ends_at) : 'no end'}</Td>
                            <Td><Status value={c.active ? 'active' : 'inactive'} /></Td>
                            <Td><Button size="sm" variant="ghost" onClick={() => setEditing(c)}>Edit</Button></Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

function CouponForm({ coupon, plans, cycles, onDone }: { coupon?: Coupon; plans: Props['plans']; cycles: Props['cycles']; onDone: () => void }) {
    const form = useForm({
        code: coupon?.code ?? '', description: coupon?.description ?? '', kind: coupon?.kind ?? 'percent', value: coupon ? (coupon.kind === 'percent' ? coupon.value : coupon.value / 100) : 10,
        currency: coupon?.currency ?? 'NGN', plan_ids: coupon?.plan_ids ?? [], cycles: coupon?.cycles ?? [], max_redemptions: coupon?.max_redemptions ?? '', per_tenant: coupon?.per_tenant ?? 1,
        starts_at: coupon?.starts_at?.slice(0, 10) ?? '', ends_at: coupon?.ends_at?.slice(0, 10) ?? '', active: coupon?.active ?? true,
    });
    const flip = <T,>(list: T[], v: T) => (list.includes(v) ? list.filter((x) => x !== v) : [...list, v]);

    return (
        <form className="surface grid gap-3 p-5 md:grid-cols-4" onSubmit={(e) => {
            e.preventDefault();
            form.transform((d) => ({ ...d, max_redemptions: d.max_redemptions === '' ? null : Number(d.max_redemptions), starts_at: d.starts_at || null, ends_at: d.ends_at || null }));

            if (coupon) {
                form.put(`/platform/coupons/${coupon.id}`, { preserveScroll: true, onSuccess: onDone });
            } else {
                form.post('/platform/coupons', { preserveScroll: true, onSuccess: onDone });
            }
        }}>
            <Field label="Code" error={form.errors.code}><input className={`${inputClass} uppercase`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} required /></Field>
            <Field label="Kind" error={form.errors.kind}>
                <select className={inputClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value as 'percent' | 'fixed')}><option value="percent">Percentage</option><option value="fixed">Fixed amount</option></select>
            </Field>
            <Field label={form.data.kind === 'percent' ? 'Percent off' : 'Amount off'} error={form.errors.value}>
                <input type="number" min={0.01} step="0.01" max={form.data.kind === 'percent' ? 100 : undefined} className={inputClass} value={form.data.value} onChange={(e) => form.setData('value', Number(e.target.value))} />
            </Field>
            <Field label="Currency" error={form.errors.currency}><select className={inputClass} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>{['NGN', 'USD', 'GHS', 'KES', 'ZAR'].map((c) => <option key={c}>{c}</option>)}</select></Field>
            <div className="md:col-span-2"><Field label="Description" error={form.errors.description}><input className={inputClass} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field></div>
            <Field label="Total uses (blank = no limit)" error={form.errors.max_redemptions}><input type="number" min={1} className={inputClass} value={form.data.max_redemptions} onChange={(e) => form.setData('max_redemptions', e.target.value)} /></Field>
            <Field label="Uses per institution" error={form.errors.per_tenant}><input type="number" min={1} max={100} className={inputClass} value={form.data.per_tenant} onChange={(e) => form.setData('per_tenant', Number(e.target.value))} /></Field>
            <Field label="Valid from" error={form.errors.starts_at}><input type="date" className={inputClass} value={form.data.starts_at} onChange={(e) => form.setData('starts_at', e.target.value)} /></Field>
            <Field label="Valid until" error={form.errors.ends_at}><input type="date" className={inputClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} /></Field>
            <div className="md:col-span-2">
                <p className="mb-1.5 text-sm font-medium">Packages <span className="font-normal text-muted-foreground">(none ticked = any)</span></p>
                <div className="flex flex-wrap gap-2">{plans.map((p) => <label key={p.id} className="flex items-center gap-1 text-sm"><input type="checkbox" checked={form.data.plan_ids.includes(p.id)} onChange={() => form.setData('plan_ids', flip(form.data.plan_ids, p.id))} />{p.name}</label>)}</div>
            </div>
            <div className="md:col-span-2">
                <p className="mb-1.5 text-sm font-medium">Billing periods <span className="font-normal text-muted-foreground">(none ticked = any)</span></p>
                <div className="flex flex-wrap gap-2">{Object.entries(cycles).map(([k, v]) => <label key={k} className="flex items-center gap-1 text-sm"><input type="checkbox" checked={form.data.cycles.includes(k)} onChange={() => form.setData('cycles', flip(form.data.cycles, k))} />{v}</label>)}</div>
            </div>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> Active</label>
            <div className="md:col-span-3"><Button disabled={form.processing}>{coupon ? 'Save code' : 'Create code'}</Button></div>
        </form>
    );
}

Coupons.layout = { breadcrumbs: [{ title: 'Discount codes', href: '/platform/coupons' }] };
