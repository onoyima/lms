import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Flash, inputClass, PageHeader, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Plan = {
    id: number; key: string; name: string; tagline: string | null; description: string | null; modules: string[]; currency: string;
    price_monthly: number; price_quarterly: number | null; price_biannual: number | null; price_yearly: number; student_limit: number | null; features: string[] | null;
    is_public: boolean; highlighted: boolean; sort: number; limits: Record<string, number | null> | null; trial_days: number | null; grace_days: number | null;
    addon_prices: Record<string, { per: number; price: number }> | null;
};
type ModuleOpt = { key: string; name: string; always_on: boolean };
type LimitKey = { key: string; label: string; unit: string; about: string };
type Defaults = { trial_days: number; grace_days: number; upload_mb: number };
type Shared = { modules: ModuleOpt[]; limitKeys: LimitKey[]; defaults: Defaults };

/** What institutions can buy: the modules in each plan, the price and the size allowance. */
export default function Plans({ plans, ...shared }: { plans: Plan[] } & Shared) {
    const [adding, setAdding] = useState(false);

    return (
        <>
            <Head title="Plans & prices" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Plans" accent="& prices" lead="Each package sets its modules, capacities, prices for every billing period, free trial, grace period and the price of extra capacity. Capacity changes apply at once; a price change applies to new subscriptions and renewals."
                    actions={<Button onClick={() => setAdding((v) => !v)}>{adding ? 'Close' : 'New plan'}</Button>} />
                <Flash />
                {adding && <PlanForm {...shared} onDone={() => setAdding(false)} />}
                {plans.map((p) => <PlanForm key={p.id} plan={p} {...shared} />)}
            </div>
        </>
    );
}

function PlanForm({ plan, modules, limitKeys, defaults, onDone }: { plan?: Plan; onDone?: () => void } & Shared) {
    const lim = (k: string) => {
        const v = plan?.limits?.[k] ?? (k === 'students' ? plan?.student_limit : null);

        return v === null || v === undefined ? '' : String(v);
    };
    const form = useForm({
        key: plan?.key ?? '', name: plan?.name ?? '', tagline: plan?.tagline ?? '', description: plan?.description ?? '', modules: plan?.modules ?? [],
        currency: plan?.currency ?? 'NGN', price_monthly: (plan?.price_monthly ?? 0) / 100, price_yearly: (plan?.price_yearly ?? 0) / 100,
        price_quarterly: plan?.price_quarterly != null ? String(plan.price_quarterly / 100) : '', price_biannual: plan?.price_biannual != null ? String(plan.price_biannual / 100) : '',
        trial_days: plan?.trial_days != null ? String(plan.trial_days) : '', grace_days: plan?.grace_days != null ? String(plan.grace_days) : '',
        limits: Object.fromEntries(limitKeys.map((k) => [k.key, lim(k.key)])) as Record<string, string>,
        addon_prices: Object.fromEntries(limitKeys.filter((k) => k.key !== 'upload_mb').map((k) => [k.key, { per: plan?.addon_prices?.[k.key]?.per ? String(plan.addon_prices[k.key].per) : '', price: plan?.addon_prices?.[k.key] ? String(plan.addon_prices[k.key].price / 100) : '' }])) as Record<string, { per: string; price: string }>,
        features: (plan?.features ?? []).join('\n'), is_public: plan?.is_public ?? true, highlighted: plan?.highlighted ?? false, sort: plan?.sort ?? 0,
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({
            ...d, features: d.features.split('\n').map((s) => s.trim()).filter(Boolean),
            price_quarterly: d.price_quarterly === '' ? null : Number(d.price_quarterly), price_biannual: d.price_biannual === '' ? null : Number(d.price_biannual),
            trial_days: d.trial_days === '' ? null : Number(d.trial_days), grace_days: d.grace_days === '' ? null : Number(d.grace_days),
            limits: Object.fromEntries(Object.entries(d.limits).map(([k, v]) => [k, v === '' ? null : Number(v)])),
            addon_prices: Object.fromEntries(Object.entries(d.addon_prices).filter(([, v]) => v.per !== '' && v.price !== '').map(([k, v]) => [k, { per: Number(v.per), price: Number(v.price) }])),
        }));

        if (plan) {
            form.put(`/platform/plans/${plan.id}`, { preserveScroll: true });
        } else {
            form.post('/platform/plans', { preserveScroll: true, onSuccess: () => onDone?.() });
        }
    };
    const toggle = (k: string) => form.setData('modules', form.data.modules.includes(k) ? form.data.modules.filter((m) => m !== k) : [...form.data.modules, k]);

    return (
        <form className="surface grid gap-3 p-5 md:grid-cols-4" onSubmit={submit}>
            <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
            <Field label="Key" error={form.errors.key}><input className={inputClass} value={form.data.key} onChange={(e) => form.setData('key', e.target.value)} required /></Field>
            <Field label="Monthly price" error={form.errors.price_monthly}><input type="number" min={0} className={inputClass} value={form.data.price_monthly} onChange={(e) => form.setData('price_monthly', Number(e.target.value))} /></Field>
            <Field label="Yearly price" hint="0 = by quotation" error={form.errors.price_yearly}><input type="number" min={0} className={inputClass} value={form.data.price_yearly} onChange={(e) => form.setData('price_yearly', Number(e.target.value))} /></Field>
            <Field label="Quarterly price" hint="blank = 3 × monthly" error={form.errors.price_quarterly}><input type="number" min={0} className={inputClass} value={form.data.price_quarterly} onChange={(e) => form.setData('price_quarterly', e.target.value)} /></Field>
            <Field label="6-month price" hint="blank = 6 × monthly" error={form.errors.price_biannual}><input type="number" min={0} className={inputClass} value={form.data.price_biannual} onChange={(e) => form.setData('price_biannual', e.target.value)} /></Field>
            <Field label="Free trial days" hint={`blank = platform default (${defaults.trial_days})`} error={form.errors.trial_days}><input type="number" min={0} max={365} className={inputClass} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', e.target.value)} /></Field>
            <Field label="Grace days after due" hint={`blank = platform default (${defaults.grace_days})`} error={form.errors.grace_days}><input type="number" min={0} max={180} className={inputClass} value={form.data.grace_days} onChange={(e) => form.setData('grace_days', e.target.value)} /></Field>
            <Field label="Currency"><select className={inputClass} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value)}>{['NGN', 'USD', 'GHS', 'KES', 'ZAR'].map((c) => <option key={c}>{c}</option>)}</select></Field>
            <Field label="Order on page"><input type="number" className={inputClass} value={form.data.sort} onChange={(e) => form.setData('sort', Number(e.target.value))} /></Field>
            <div className="flex flex-col justify-end gap-1 text-sm">
                <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.is_public} onChange={(e) => form.setData('is_public', e.target.checked)} /> Shown on website</label>
                <label className="flex items-center gap-2"><input type="checkbox" checked={form.data.highlighted} onChange={(e) => form.setData('highlighted', e.target.checked)} /> Highlight as recommended</label>
            </div>
            <div className="md:col-span-2"><Field label="Tagline" error={form.errors.tagline}><input className={inputClass} value={form.data.tagline} onChange={(e) => form.setData('tagline', e.target.value)} /></Field></div>
            <div className="md:col-span-2"><Field label="Description" error={form.errors.description}><input className={inputClass} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field></div>
            <div className="md:col-span-2"><Field label="Selling points (one per line)" error={form.errors.features}><textarea className={textareaClass} value={form.data.features} onChange={(e) => form.setData('features', e.target.value)} /></Field></div>
            <div className="md:col-span-2">
                <p className="mb-1.5 text-sm font-medium">Modules included</p>
                <div className="flex flex-wrap gap-2">
                    {modules.filter((m) => !m.always_on).map((m) => (
                        <label key={m.key} className={`cursor-pointer rounded-full border px-3 py-1 text-xs ${form.data.modules.includes(m.key) ? 'border-brand bg-brand-soft text-brand' : 'text-muted-foreground'}`}>
                            <input type="checkbox" className="sr-only" checked={form.data.modules.includes(m.key)} onChange={() => toggle(m.key)} />{m.name}
                        </label>
                    ))}
                </div>
                {form.errors.modules && <p className="mt-1 text-xs text-destructive">{form.errors.modules}</p>}
            </div>
            <div className="md:col-span-4">
                <p className="mb-1.5 text-sm font-medium">Capacity and extra-capacity prices <span className="font-normal text-muted-foreground">— blank capacity = unlimited; blank price = not sold as an extra</span></p>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {limitKeys.map((k) => (
                        <div key={k.key} className="rounded-xl border p-3 text-sm" title={k.about}>
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">{k.label}{k.unit && k.unit !== 'accounts' && k.unit !== 'people' ? ` (${k.unit})` : ''}</span>
                                <input type="number" min={0} aria-label={`${k.label} capacity`} placeholder={k.key === 'upload_mb' ? `default ${defaults.upload_mb}` : 'unlimited'} className="h-8 w-28 rounded-lg border bg-background px-2 text-right"
                                    value={form.data.limits[k.key]} onChange={(e) => form.setData('limits', { ...form.data.limits, [k.key]: e.target.value })} />
                            </div>
                            {form.data.addon_prices[k.key] && (
                                <div className="mt-2 flex items-center gap-1 text-xs text-muted-foreground">
                                    Extra: <input type="number" min={1} aria-label={`${k.label} block size`} placeholder="block" className="h-7 w-20 rounded-md border bg-background px-1 text-right"
                                        value={form.data.addon_prices[k.key].per} onChange={(e) => form.setData('addon_prices', { ...form.data.addon_prices, [k.key]: { ...form.data.addon_prices[k.key], per: e.target.value } })} />
                                    for <input type="number" min={0} aria-label={`${k.label} block price`} placeholder="price" className="h-7 w-24 rounded-md border bg-background px-1 text-right"
                                        value={form.data.addon_prices[k.key].price} onChange={(e) => form.setData('addon_prices', { ...form.data.addon_prices, [k.key]: { ...form.data.addon_prices[k.key], price: e.target.value } })} />
                                </div>
                            )}
                        </div>
                    ))}
                </div>
                {Object.entries(form.errors).filter(([k]) => k.startsWith('limits') || k.startsWith('addon_prices')).map(([k, v]) => <p key={k} className="mt-1 text-xs text-destructive">{v}</p>)}
            </div>
            <Button className="w-fit" disabled={form.processing}>{plan ? 'Save plan' : 'Create plan'}</Button>
        </form>
    );
}

Plans.layout = { breadcrumbs: [{ title: 'Plans & prices', href: '/platform/plans' }] };
