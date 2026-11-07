import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { eventText, UsageMeters } from '@/components/platform/usage';
import type { HistoryRow, UsageRow } from '@/components/platform/usage';
import { Button } from '@/components/ui/button';

type Institution = {
    id: number; name: string; short_name: string | null; slug: string; status: string; suspension_reason: string | null; domains: string[] | null;
    contact_name: string | null; contact_email: string | null; contact_phone: string | null; created_at: string; approved_at: string | null; plan_id: number | null;
};
type Sub = {
    id: number; status: string; cycle: string; price: number; currency: string; student_limit: number | null; plan: string | null; price_label: string;
    current_period_start: string | null; current_period_end: string | null; trial_ends_at: string | null; cancel_at_period_end: boolean;
};
type Invoice = { id: number; number: string; status: string; kind: string; amount_label: string; due_at: string; paid_at: string | null; period_start: string | null; period_end: string | null };
type Payment = { id: number; provider: string; reference: string; status: string; paid_at: string | null; note: string | null; amount_label: string };
type PlanOpt = { id: number; name: string; key: string; price_monthly: number; price_yearly: number; currency: string };
type Addon = { id: number; limit_key: string; label: string; quantity: number; status: string; starts_at: string | null; ends_at: string | null; note: string | null; invoice_id: number | null };
type LimitKey = { key: string; label: string; unit: string };
type ModuleOpt = { key: string; name: string; always_on: boolean };

type Props = {
    institution: Institution; usage: { students: number; staff: number; attempts_30d: number }; subscription: Sub | null;
    invoices: Invoice[]; payments: Payment[]; plans: PlanOpt[]; modules: ModuleOpt[]; enabled: string[]; overrides: Record<string, boolean>;
    grants: { module: string; expires_at: string; reason: string | null }[]; entitlements: UsageRow[]; planLimits: Record<string, number | null>;
    customLimits: Record<string, number | null> | null; note: string | null; addons: Addon[]; history: HistoryRow[]; limitKeys: LimitKey[]; cycles: Record<string, string>;
    supportSessions: { id: number; reason: string; by: string | null; started_at: string | null; expires_at: string | null; ended_at: string | null; created_at: string }[];
};

/** One institution, as the platform sees it: contract, money, modules and status. No institution records. */
export default function InstitutionPage(p: Props) {
    const t = p.institution;

    return (
        <>
            <Head title={t.name} />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title={t.name} lead={<span className="font-mono text-xs">{t.slug}{(t.domains ?? []).length ? ` · ${(t.domains ?? []).join(', ')}` : ''}</span>}
                    actions={<Status value={t.status} />} />
                <Flash />
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Active students" value={p.usage.students.toLocaleString()} tone={p.subscription?.student_limit && p.usage.students > p.subscription.student_limit ? 'warning' : undefined} />
                    <Stat label="Active staff" value={p.usage.staff.toLocaleString()} />
                    <Stat label="Exam sittings, 30 days" value={p.usage.attempts_30d.toLocaleString()} />
                    <Stat label="Student allowance" value={p.subscription?.student_limit?.toLocaleString() ?? 'Unlimited'} />
                </div>
                <div className="grid gap-6 lg:grid-cols-2">
                    <SubscriptionCard {...p} />
                    <DetailsCard institution={t} />
                </div>
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Usage against the subscription</h2>
                    <UsageMeters rows={p.entitlements} />
                </section>
                {p.subscription && <CapacityCard {...p} />}
                <div className="grid gap-6 lg:grid-cols-2">
                    <AddonsCard {...p} />
                    {p.subscription && <ValidityCard {...p} />}
                </div>
                <ModulesCard {...p} />
                <InvoicesCard {...p} />
                <SupportCard {...p} />
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Subscription history</h2>
                    {p.history.length === 0 ? <p className="text-sm text-muted-foreground">No changes yet.</p> : (
                        <ol className="surface divide-y text-sm">
                            {p.history.map((e) => <li key={e.id} className="flex flex-wrap justify-between gap-2 px-4 py-2.5"><span>{eventText(e)}</span><span className="text-muted-foreground">{e.by ? `${e.by} · ` : ''}{formatDateTime(e.at)}</span></li>)}
                        </ol>
                    )}
                </section>
            </div>
        </>
    );
}

function SubscriptionCard({ institution, subscription: s, plans, cycles }: Props) {
    const form = useForm({ plan_id: s ? plans.find((x) => x.name === s.plan)?.id ?? plans[0]?.id : institution.plan_id ?? plans[0]?.id, cycle: s?.cycle ?? 'yearly', price: '', trial_days: 0 });

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <h2 className="text-lg font-medium">Subscription</h2>
            {s ? (
                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
                    <dt className="text-muted-foreground">Package</dt><dd className="font-medium">{s.plan}</dd>
                    <dt className="text-muted-foreground">Price</dt><dd className="tabular-nums">{s.price_label} · {cycles[s.cycle] ?? s.cycle}</dd>
                    <dt className="text-muted-foreground">Status</dt><dd><Status value={s.status} /></dd>
                    <dt className="text-muted-foreground">{s.status === 'trialing' ? 'Trial ends' : 'Paid until'}</dt><dd>{formatDateTime(s.trial_ends_at ?? s.current_period_end)}</dd>
                </dl>
            ) : <p className="text-sm text-muted-foreground">No current subscription.</p>}
            <form className="grid gap-3 border-t pt-4 sm:grid-cols-2" onSubmit={(e) => {
                e.preventDefault();

                if (confirm('Start the new subscription now? The current one ends today.')) {
                    form.post(`/platform/institutions/${institution.id}/subscription`, { preserveScroll: true });
                }
            }}>
                <Field label="Change plan" error={form.errors.plan_id}>
                    <select className={inputClass} value={form.data.plan_id} onChange={(e) => form.setData('plan_id', Number(e.target.value))}>{plans.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
                </Field>
                <Field label="Billing period"><select className={inputClass} value={form.data.cycle} onChange={(e) => form.setData('cycle', e.target.value)}>{Object.entries(cycles).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                <Field label="Agreed price (optional)" hint="per cycle, in naira; blank uses the plan price" error={form.errors.price}><input type="number" min={0} className={inputClass} value={form.data.price} onChange={(e) => form.setData('price', e.target.value)} /></Field>
                <Field label="Free trial days" error={form.errors.trial_days}><input type="number" min={0} max={90} className={inputClass} value={form.data.trial_days} onChange={(e) => form.setData('trial_days', Number(e.target.value))} /></Field>
                <div className="flex flex-wrap gap-2 sm:col-span-2">
                    <Button disabled={form.processing}>Apply</Button>
                    {s && s.price > 0 && <Button type="button" variant="outline" onClick={() => router.post(`/platform/institutions/${institution.id}/invoice`, {}, { preserveScroll: true })}>Issue renewal invoice</Button>}
                </div>
            </form>
        </section>
    );
}

function DetailsCard({ institution: t }: { institution: Institution }) {
    const form = useForm({ name: t.name, domains: (t.domains ?? []).join(' '), contact_name: t.contact_name ?? '', contact_email: t.contact_email ?? '', contact_phone: t.contact_phone ?? '' });
    const [reason, setReason] = useState('non_payment');
    const setStatus = (status: 'active' | 'suspended') => {
        const msg = status === 'suspended' ? 'Suspend this institution? Nobody there can sign in except its administrators, who can still pay.' : 'Restore access for this institution?';

        if (confirm(msg)) {
            router.put(`/platform/institutions/${t.id}`, { status, suspension_reason: status === 'suspended' ? reason : null }, { preserveScroll: true });
        }
    };

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <h2 className="text-lg font-medium">Contract details</h2>
            <form className="grid gap-3 sm:grid-cols-2" onSubmit={(e) => {
                e.preventDefault();
                form.transform((d) => ({ ...d, domains: d.domains.split(/[\s,]+/).filter(Boolean) }));
                form.put(`/platform/institutions/${t.id}`, { preserveScroll: true });
            }}>
                <Field label="Name" error={form.errors.name}><input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} /></Field>
                <Field label="Domains" error={form.errors.domains}><input className={inputClass} value={form.data.domains} onChange={(e) => form.setData('domains', e.target.value)} /></Field>
                <Field label="Contact person" error={form.errors.contact_name}><input className={inputClass} value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} /></Field>
                <Field label="Contact email" error={form.errors.contact_email}><input type="email" className={inputClass} value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} /></Field>
                <Field label="Contact phone" error={form.errors.contact_phone}><input className={inputClass} value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} /></Field>
                <div className="flex items-end"><Button disabled={form.processing}>Save details</Button></div>
            </form>
            <div className="flex flex-wrap items-end gap-2 border-t pt-4">
                {t.status === 'active' ? (
                    <>
                        <Field label="Reason">
                            <select className={inputClass} value={reason} onChange={(e) => setReason(e.target.value)}>
                                <option value="non_payment">Unpaid</option><option value="request">Institution asked</option><option value="abuse">Misuse</option><option value="other">Other</option>
                            </select>
                        </Field>
                        <Button variant="destructive" onClick={() => setStatus('suspended')}>Suspend</Button>
                    </>
                ) : <Button onClick={() => setStatus('active')}>Restore access</Button>}
                <p className="w-full text-xs text-muted-foreground">Joined {formatDateTime(t.created_at)}{t.approved_at ? ` · approved ${formatDateTime(t.approved_at)}` : ''}</p>
            </div>
        </section>
    );
}

function ModulesCard({ institution, modules, enabled, overrides: initial, grants }: Props) {
    const [overrides, setOverrides] = useState<Record<string, boolean | null>>(initial);
    const grant = useForm({ module: modules.find((m) => !m.always_on)?.key ?? '', days: 14, reason: '' });
    const state = (k: string) => (overrides[k] === true ? 'on' : overrides[k] === false ? 'off' : 'plan');

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h2 className="text-lg font-medium">Modules</h2>
                    <p className="text-sm text-muted-foreground">The plan decides; switch a module on as an add-on or off for this institution only.</p>
                </div>
                <Button onClick={() => router.put(`/platform/institutions/${institution.id}`, { overrides }, { preserveScroll: true })}>Save modules</Button>
            </div>
            <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                {modules.map((m) => (
                    <div key={m.key} className="flex items-center justify-between gap-3 rounded-xl border px-3 py-2 text-sm">
                        <span>{m.name}<span className={`ml-2 text-xs ${enabled.includes(m.key) ? 'text-status-good' : 'text-muted-foreground'}`}>{enabled.includes(m.key) ? 'on' : 'off'}</span></span>
                        {m.always_on ? <span className="text-xs text-muted-foreground">always on</span> : (
                            <select aria-label={`${m.name} setting`} className="h-8 rounded-lg border bg-background px-2 text-xs" value={state(m.key)}
                                onChange={(e) => setOverrides({ ...overrides, [m.key]: e.target.value === 'plan' ? null : e.target.value === 'on' })}>
                                <option value="plan">From plan</option><option value="on">Add-on</option><option value="off">Off</option>
                            </select>
                        )}
                    </div>
                ))}
            </div>
            <div className="flex flex-col gap-3 border-t pt-4">
                <h3 className="font-medium">Temporary access</h3>
                <p className="text-sm text-muted-foreground">Switch a module on for a limited time (a trial of a feature, a support case). It does not use the module allowance and switches itself off when the time is up.</p>
                {grants.length > 0 && (
                    <ul className="flex flex-col gap-1 text-sm">
                        {grants.map((g) => (
                            <li key={g.module} className="flex flex-wrap items-center gap-2">
                                <b>{modules.find((m) => m.key === g.module)?.name ?? g.module}</b> until {formatDateTime(g.expires_at)}{g.reason ? ` — ${g.reason}` : ''}
                                <Button size="sm" variant="ghost" onClick={() => confirm('End this temporary access now?') && router.delete(`/platform/institutions/${institution.id}/grants/${g.module}`, { preserveScroll: true })}>End now</Button>
                            </li>
                        ))}
                    </ul>
                )}
                <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => {
                    e.preventDefault();
                    grant.post(`/platform/institutions/${institution.id}/grants`, { preserveScroll: true, onSuccess: () => grant.reset('reason') });
                }}>
                    <Field label="Module" error={grant.errors.module}>
                        <select className={inputClass} value={grant.data.module} onChange={(e) => grant.setData('module', e.target.value)}>{modules.filter((m) => !m.always_on).map((m) => <option key={m.key} value={m.key}>{m.name}</option>)}</select>
                    </Field>
                    <Field label="Days" error={grant.errors.days}><input type="number" min={1} max={365} className={inputClass} value={grant.data.days} onChange={(e) => grant.setData('days', Number(e.target.value))} /></Field>
                    <Field label="Reason" error={grant.errors.reason}><input className={inputClass} value={grant.data.reason} onChange={(e) => grant.setData('reason', e.target.value)} /></Field>
                    <div className="flex items-end"><Button variant="outline" disabled={grant.processing}>Grant access</Button></div>
                </form>
            </div>
        </section>
    );
}

function CapacityCard({ institution, limitKeys, planLimits, customLimits, note }: Props) {
    const init: Record<string, string> = {};
    const custom: Record<string, boolean> = {};
    limitKeys.forEach((k) => {
        const has = !!customLimits && Object.prototype.hasOwnProperty.call(customLimits, k.key);
        custom[k.key] = has;
        const v = has ? customLimits![k.key] : null;
        init[k.key] = v === null || v === undefined ? '' : String(v);
    });
    const [values, setValues] = useState(init);
    const [own, setOwn] = useState(custom);
    const [text, setText] = useState(note ?? '');
    const show = (v: number | null | undefined) => (v === null || v === undefined ? 'unlimited' : v.toLocaleString());

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div>
                <h2 className="text-lg font-medium">Capacity for this institution</h2>
                <p className="text-sm text-muted-foreground">By default the package decides. Tick a row to give this institution its own figure (enterprise or negotiated deal); leave the box empty for unlimited. Extra capacity bought is added on top.</p>
            </div>
            <form className="flex flex-col gap-3" onSubmit={(e) => {
                e.preventDefault();
                const limits: Record<string, number | null> = {};
                const usePackage: string[] = [];
                limitKeys.forEach((k) => (own[k.key] ? (limits[k.key] = values[k.key] === '' ? null : Number(values[k.key])) : usePackage.push(k.key)));
                router.post(`/platform/institutions/${institution.id}/limits`, { limits, use_package: usePackage, note: text }, { preserveScroll: true });
            }}>
                <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                    {limitKeys.map((k) => (
                        <div key={k.key} className="flex items-center gap-2 rounded-xl border px-3 py-2 text-sm">
                            <input type="checkbox" aria-label={`Own ${k.label} limit`} checked={own[k.key]} onChange={(e) => setOwn({ ...own, [k.key]: e.target.checked })} />
                            <span className="flex-1">{k.label}<span className="block text-xs text-muted-foreground">package: {show(planLimits[k.key])}{k.unit && k.unit !== 'accounts' && k.unit !== 'people' ? ` ${k.unit}` : ''}</span></span>
                            <input type="number" min={0} aria-label={`${k.label} limit`} disabled={!own[k.key]} placeholder="unlimited" className="h-8 w-28 rounded-lg border bg-background px-2 text-right disabled:opacity-40"
                                value={values[k.key]} onChange={(e) => setValues({ ...values, [k.key]: e.target.value })} />
                        </div>
                    ))}
                </div>
                <Field label="Note (contract reference, reason)"><input className={inputClass} value={text} onChange={(e) => setText(e.target.value)} maxLength={255} /></Field>
                <div><Button>Save capacity</Button></div>
            </form>
        </section>
    );
}

function AddonsCard({ institution, addons, limitKeys }: Props) {
    const form = useForm({ limit_key: limitKeys[0]?.key ?? 'students', quantity: '', ends_at: '', note: '' });

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div>
                <h2 className="text-lg font-medium">Extra capacity</h2>
                <p className="text-sm text-muted-foreground">Bought by the institution from its billing page, or given here without an invoice.</p>
            </div>
            {addons.length === 0 ? <p className="text-sm text-muted-foreground">None.</p> : (
                <ul className="flex flex-col gap-1 text-sm">
                    {addons.map((a) => (
                        <li key={a.id} className="flex flex-wrap items-center gap-2">
                            <span>+{a.quantity.toLocaleString()} {a.label.toLowerCase()}</span><Status value={a.status} />
                            <span className="text-muted-foreground">{a.ends_at ? `until ${formatDateTime(a.ends_at)}` : 'until the subscription ends'}{a.note ? ` · ${a.note}` : ''}</span>
                            {a.status !== 'ended' && <Button size="sm" variant="ghost" onClick={() => confirm('End this extra capacity now?') && router.delete(`/platform/institutions/${institution.id}/addons/${a.id}`, { preserveScroll: true })}>End</Button>}
                        </li>
                    ))}
                </ul>
            )}
            <form className="grid gap-3 border-t pt-4 sm:grid-cols-2" onSubmit={(e) => {
                e.preventDefault();
                form.post(`/platform/institutions/${institution.id}/addons`, { preserveScroll: true, onSuccess: () => form.reset() });
            }}>
                <Field label="What" error={form.errors.limit_key}>
                    <select className={inputClass} value={form.data.limit_key} onChange={(e) => form.setData('limit_key', e.target.value)}>{limitKeys.filter((k) => k.key !== 'upload_mb').map((k) => <option key={k.key} value={k.key}>{k.label}</option>)}</select>
                </Field>
                <Field label="Quantity" error={form.errors.quantity}><input type="number" min={1} className={inputClass} value={form.data.quantity} onChange={(e) => form.setData('quantity', e.target.value)} required /></Field>
                <Field label="Until (optional)" error={form.errors.ends_at}><input type="date" className={inputClass} value={form.data.ends_at} onChange={(e) => form.setData('ends_at', e.target.value)} /></Field>
                <Field label="Note" error={form.errors.note}><input className={inputClass} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></Field>
                <div><Button variant="outline" disabled={form.processing}>Add capacity</Button></div>
            </form>
        </section>
    );
}

function ValidityCard({ institution, subscription: s }: Props) {
    const form = useForm({ days: 30, reason: '' });

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div>
                <h2 className="text-lg font-medium">Validity</h2>
                <p className="text-sm text-muted-foreground">Paid until {formatDateTime(s?.current_period_end)}{s?.cancel_at_period_end ? ' — will not renew' : ''}. Extending moves the end date and any open renewal invoice, and restores access if it was suspended for non-payment.</p>
            </div>
            <form className="grid gap-3 sm:grid-cols-3" onSubmit={(e) => {
                e.preventDefault();
                form.post(`/platform/institutions/${institution.id}/extend`, { preserveScroll: true, onSuccess: () => form.reset('reason') });
            }}>
                <Field label="Days" error={form.errors.days}><input type="number" min={1} max={730} className={inputClass} value={form.data.days} onChange={(e) => form.setData('days', Number(e.target.value))} /></Field>
                <Field label="Reason" error={form.errors.reason}><input className={inputClass} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} /></Field>
                <div className="flex items-end"><Button variant="outline" disabled={form.processing}>Extend</Button></div>
            </form>
            <div className="flex flex-wrap gap-2 border-t pt-4">
                {s?.cancel_at_period_end
                    ? <Button variant="outline" onClick={() => router.post(`/platform/institutions/${institution.id}/cancel`, { when: 'resume' }, { preserveScroll: true })}>Undo cancellation</Button>
                    : <Button variant="ghost" onClick={() => confirm('Stop renewal at the end of the current period?') && router.post(`/platform/institutions/${institution.id}/cancel`, { when: 'period_end' }, { preserveScroll: true })}>Cancel at period end</Button>}
                <Button variant="ghost" className="text-status-critical" onClick={() => confirm('End the subscription today? The institution keeps its data but loses paid features.') && router.post(`/platform/institutions/${institution.id}/cancel`, { when: 'now' }, { preserveScroll: true })}>End now</Button>
            </div>
        </section>
    );
}

function SupportCard({ institution, supportSessions }: Props) {
    const form = useForm({ reason: '' });

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div>
                <h2 className="text-lg font-medium">Support sign-in</h2>
                <p className="text-sm text-muted-foreground">Sign in as this institution's administrator for up to 30 minutes to help with a problem. The reason, every action and the end are recorded in both audit trails, the institution's administrators are told at once, and passwords, two-factor settings and payments cannot be changed. You are signed out of this console while it lasts.</p>
            </div>
            <form className="flex flex-wrap items-end gap-3" onSubmit={(e) => {
                e.preventDefault();

                if (confirm('Start a recorded support session as this institution\'s administrator?')) {
                    form.post(`/platform/institutions/${institution.id}/support`);
                }
            }}>
                <div className="min-w-64 flex-1"><Field label="Reason (ticket number and what you will check)" error={form.errors.reason}><input className={inputClass} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} minLength={10} required /></Field></div>
                <Button variant="outline" disabled={form.processing || form.data.reason.length < 10}>Sign in for support</Button>
            </form>
            {supportSessions.length > 0 && (
                <ul className="flex flex-col gap-1 border-t pt-3 text-sm">
                    {supportSessions.map((s) => (
                        <li key={s.id} className="flex flex-wrap justify-between gap-2">
                            <span>{s.by ?? 'Unknown'} — {s.reason}</span>
                            <span className="text-muted-foreground">{s.started_at ? `${formatDateTime(s.started_at)} → ${s.ended_at ? formatDateTime(s.ended_at) : `until ${formatDateTime(s.expires_at)}`}` : 'link not used'}</span>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function InvoicesCard({ invoices, payments }: Props) {
    const [paying, setPaying] = useState<Invoice | null>(null);
    const form = useForm({ reference: '', amount: '', note: '', provider: 'bank_transfer' });

    return (
        <section className="flex flex-col gap-4">
            <h2 className="text-lg font-medium">Invoices</h2>
            <Table head={['Number', 'Period', 'Amount', 'Due', 'Status', '']}>
                {invoices.length === 0 && <tr><Td className="text-muted-foreground">No invoices yet.</Td></tr>}
                {invoices.map((i) => (
                    <tr key={i.id}>
                        <Td className="font-mono text-xs">{i.number}<span className="block font-sans text-muted-foreground">{i.kind}</span></Td>
                        <Td>{formatDateTime(i.period_start)} – {formatDateTime(i.period_end)}</Td>
                        <Td className="tabular-nums">{i.amount_label}</Td>
                        <Td>{formatDateTime(i.due_at)}</Td>
                        <Td><Status value={i.status} /></Td>
                        <Td>
                            <a className="mr-2 text-sm text-brand hover:underline" href={`/platform/invoices/${i.id}/pdf`}>PDF</a>
                            {i.status === 'open' && (
                                <div className="inline-flex gap-1">
                                    <Button size="sm" variant="outline" onClick={() => {
 setPaying(i); form.reset(); 
}}>Record payment</Button>
                                    <Button size="sm" variant="ghost" onClick={() => confirm(`Cancel ${i.number}?`) && router.post(`/platform/invoices/${i.id}/void`, {}, { preserveScroll: true })}>Cancel</Button>
                                </div>
                            )}
                        </Td>
                    </tr>
                ))}
            </Table>
            {paying && (
                <form className="surface grid gap-3 p-5 md:grid-cols-5" onSubmit={(e) => {
                    e.preventDefault();
                    form.post(`/platform/invoices/${paying.id}/payment`, { preserveScroll: true, onSuccess: () => setPaying(null) });
                }}>
                    <p className="text-sm md:col-span-5">Record a bank transfer for <b>{paying.number}</b> ({paying.amount_label}). Check the money is in the account first.</p>
                    <Field label="Bank reference" error={form.errors.reference}><input className={inputClass} value={form.data.reference} onChange={(e) => form.setData('reference', e.target.value)} required /></Field>
                    <Field label="Amount received (₦)" error={form.errors.amount}><input type="number" min={0} step="0.01" className={inputClass} value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} required /></Field>
                    <Field label="Method"><select className={inputClass} value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)}><option value="bank_transfer">Bank transfer</option><option value="manual">Other</option></select></Field>
                    <Field label="Note" error={form.errors.note}><input className={inputClass} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} /></Field>
                    <div className="flex items-end gap-2"><Button disabled={form.processing}>Save</Button><Button type="button" variant="ghost" onClick={() => setPaying(null)}>Close</Button></div>
                </form>
            )}
            <h2 className="mt-2 text-lg font-medium">Payments</h2>
            <Table head={['Reference', 'Method', 'Amount', 'Status', 'Paid', 'Note']}>
                {payments.length === 0 && <tr><Td className="text-muted-foreground">No payments yet.</Td></tr>}
                {payments.map((x) => (
                    <tr key={x.id}>
                        <Td className="font-mono text-xs">{x.reference}</Td>
                        <Td>{x.provider.replace('_', ' ')}</Td>
                        <Td className="tabular-nums">{x.amount_label}</Td>
                        <Td><Status value={x.status} /></Td>
                        <Td>{formatDateTime(x.paid_at)}</Td>
                        <Td>{x.note}</Td>
                    </tr>
                ))}
            </Table>
        </section>
    );
}

InstitutionPage.layout = { breadcrumbs: [{ title: 'Institutions', href: '/platform/institutions' }, { title: 'Institution', href: '#' }] };
