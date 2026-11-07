import { Head, router, useForm } from '@inertiajs/react';
import { CreditCard, Download, Landmark, Tag } from 'lucide-react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Stat, Status, Table, Td } from '@/components/platform/ui';
import { eventText, UsageMeters } from '@/components/platform/usage';
import type { HistoryRow, UsageRow } from '@/components/platform/usage';
import { Button } from '@/components/ui/button';

type Sub = { plan: string | null; plan_id: number; plan_key: string | null; status: string; cycle: string; cycle_label: string; price: string; period_end: string | null; trial_ends_at: string | null; cancel_at_period_end: boolean };
type Invoice = { id: number; number: string; status: string; kind: string; amount: string; discount: boolean; due_at: string; paid_at: string | null; period_start: string | null; period_end: string | null; overdue: boolean; lines: { description: string; amount: number }[] };
type Payment = { id: number; invoice_id: number; provider: string; reference: string; status: string; paid_at: string | null; amount: string };
type PlanOpt = { id: number; key: string; name: string; tagline: string | null; features: string[] | null; prices: Record<string, string> };
type AddonOpt = { key: string; label: string; unit: string; per: number; price: string };
type ActiveAddon = { id: number; label: string; quantity: number; status: string; ends_at: string | null };
type Props = {
    institution: { name: string; status: string; reason: string | null }; subscription: Sub | null; usage: UsageRow[]; addons: AddonOpt[]; activeAddons: ActiveAddon[];
    history: HistoryRow[]; invoices: Invoice[]; payments: Payment[]; plans: PlanOpt[]; cycles: Record<string, string>; online: boolean; bank: string;
};

/** The institution administrator's view of what the institution pays for, what it uses, and the place to pay. */
export default function Billing(p: Props) {
    const { institution, subscription: s, invoices, payments, online, bank } = p;
    const open = invoices.filter((i) => i.status === 'open');
    const full = p.usage.filter((u) => !u.ceiling && u.percent !== null && u.percent >= 100);

    return (
        <>
            <Head title="Subscription & billing" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Subscription" accent="& billing" lead="Your package, what you use of it, invoices and receipts. Only people allowed to manage billing see this page." />
                <Flash />
                {institution.status === 'suspended' && (
                    <div className="rounded-lg border border-status-critical/40 bg-status-critical/10 p-4 text-sm">
                        <b>Access to {institution.name} is suspended</b>{institution.reason === 'non_payment' ? ' because an invoice is unpaid. Paying it restores access straight away.' : '. Contact the platform team.'}
                    </div>
                )}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Package" value={s ? `${s.plan}` : 'None'} />
                    <Stat label="Status" value={<Status value={s?.status ?? 'none'} className="text-sm" />} />
                    <Stat label={s?.status === 'trialing' ? 'Free trial ends' : s?.cancel_at_period_end ? 'Ends on' : 'Paid until'} value={<span className="text-lg">{formatDateTime(s?.trial_ends_at ?? s?.period_end)}</span>} />
                    <Stat label="Price" value={<span className="text-lg">{s ? `${s.price} · ${s.cycle_label}` : '—'}</span>} />
                </div>
                {s?.cancel_at_period_end && (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-status-warning/40 bg-status-warning/10 p-4 text-sm">
                        <span>The subscription will not renew and ends on <b>{formatDateTime(s.period_end)}</b>. Your data is kept.</span>
                        <Button size="sm" onClick={() => router.post('/institution/billing/cancel', { resume: true }, { preserveScroll: true })}>Keep the subscription</Button>
                    </div>
                )}

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">What you use</h2>
                    {full.length > 0 && <p className="text-sm text-status-critical">Full: {full.map((u) => u.label).join(', ')}. New items of these kinds are refused until you buy extra capacity or change package.</p>}
                    <UsageMeters rows={p.usage} />
                </section>

                {(p.addons.length > 0 || p.activeAddons.length > 0) && <AddonsCard {...p} />}
                {s && <ChangeCard {...p} />}

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Invoices</h2>
                    <Table head={['Number', 'Period', 'Amount', 'Due', 'Status', '']}>
                        {invoices.length === 0 && <tr><Td className="text-muted-foreground">No invoices yet.</Td></tr>}
                        {invoices.map((i) => <InvoiceRow key={i.id} i={i} online={online} />)}
                    </Table>
                    {open.length > 0 && (
                        <div className="surface flex gap-3 p-4 text-sm">
                            <Landmark className="mt-0.5 size-5 shrink-0 text-muted-foreground" />
                            <div>
                                <p className="font-medium">Paying by bank transfer</p>
                                <p className="text-muted-foreground">{bank || 'Ask the platform team for the account details.'} Use the invoice number ({open.map((i) => i.number).join(', ')}) as the payment description. The platform team marks it paid once the money arrives.</p>
                                {online && <p className="mt-1 text-muted-foreground">Card, bank and USSD payments through Paystack count immediately.</p>}
                            </div>
                        </div>
                    )}
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Payments and receipts</h2>
                    <Table head={['Paid', 'Amount', 'Method', 'Reference', 'Status', '']}>
                        {payments.length === 0 && <tr><Td className="text-muted-foreground">No payments yet.</Td></tr>}
                        {payments.map((x) => (
                            <tr key={x.id}>
                                <Td>{formatDateTime(x.paid_at)}</Td>
                                <Td className="tabular-nums">{x.amount}</Td>
                                <Td>{x.provider.replace('_', ' ')}</Td>
                                <Td className="font-mono text-xs">{x.reference}</Td>
                                <Td><Status value={x.status} /></Td>
                                <Td>{x.status === 'succeeded' && <a className="inline-flex items-center gap-1 text-sm text-brand hover:underline" href={`/institution/billing/invoices/${x.invoice_id}/pdf`}><Download className="size-4" /> Receipt</a>}</Td>
                            </tr>
                        ))}
                    </Table>
                </section>

                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Subscription history</h2>
                    {p.history.length === 0 ? <p className="text-sm text-muted-foreground">No changes yet.</p> : (
                        <ol className="surface divide-y text-sm">
                            {p.history.map((e) => <li key={e.id} className="flex flex-wrap justify-between gap-2 px-4 py-2.5"><span>{eventText(e)}</span><span className="text-muted-foreground">{formatDateTime(e.at)}</span></li>)}
                        </ol>
                    )}
                    {s && !s.cancel_at_period_end && (
                        <div>
                            <Button variant="ghost" className="text-status-critical" onClick={() => confirm(`Stop the subscription at the end of the current period (${formatDateTime(s.period_end)})? You can undo this until then.`) && router.post('/institution/billing/cancel', {}, { preserveScroll: true })}>Do not renew</Button>
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}

function InvoiceRow({ i, online }: { i: Invoice; online: boolean }) {
    const [coupon, setCoupon] = useState(false);
    const form = useForm({ code: '' });

    return (
        <tr>
            <Td className="font-mono text-xs">{i.number}<span className="block font-sans text-muted-foreground">{i.lines?.[0]?.description}</span></Td>
            <Td>{formatDateTime(i.period_start)} – {formatDateTime(i.period_end)}</Td>
            <Td className="tabular-nums font-medium">{i.amount}{i.discount && <span className="block text-xs font-normal text-status-good">discount applied</span>}</Td>
            <Td className={i.overdue ? 'text-status-critical' : ''}>{formatDateTime(i.due_at)}</Td>
            <Td><Status value={i.overdue ? 'past_due' : i.status} /></Td>
            <Td>
                <div className="flex flex-wrap items-center gap-1">
                    {i.status === 'open' && online && <Button size="sm" onClick={() => router.post(`/institution/billing/invoices/${i.id}/pay`)}><CreditCard className="size-4" /> Pay now</Button>}
                    {i.status === 'open' && !i.discount && !coupon && <Button size="sm" variant="ghost" onClick={() => setCoupon(true)}><Tag className="size-4" /> Code</Button>}
                    {i.status !== 'void' && <a className="inline-flex h-8 items-center gap-1 px-2 text-sm text-brand hover:underline" href={`/institution/billing/invoices/${i.id}/pdf`}><Download className="size-4" /> PDF</a>}
                </div>
                {coupon && (
                    <form className="mt-2 flex gap-1" onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/institution/billing/invoices/${i.id}/coupon`, { preserveScroll: true, onSuccess: () => setCoupon(false) });
                    }}>
                        <input aria-label="Discount code" className={`${inputClass} h-8 w-32`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="Code" required />
                        <Button size="sm" disabled={form.processing}>Apply</Button>
                    </form>
                )}
                {form.errors.code && <p className="text-xs text-status-critical">{form.errors.code}</p>}
            </Td>
        </tr>
    );
}

function AddonsCard({ addons, activeAddons }: Props) {
    const form = useForm({ key: addons[0]?.key ?? '', blocks: 1 });
    const chosen = addons.find((a) => a.key === form.data.key);

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div>
                <h2 className="text-lg font-medium">Extra capacity</h2>
                <p className="text-sm text-muted-foreground">Add room without changing package. It lasts until the end of the current subscription period and is switched on as soon as its invoice is paid.</p>
            </div>
            {activeAddons.length > 0 && (
                <ul className="flex flex-wrap gap-2 text-sm">
                    {activeAddons.map((a) => <li key={a.id} className="rounded-full border px-3 py-1">+{a.quantity.toLocaleString()} {a.label.toLowerCase()} · {a.status === 'pending' ? 'awaiting payment' : `until ${formatDateTime(a.ends_at)}`}</li>)}
                </ul>
            )}
            {addons.length > 0 && (
                <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => {
                    e.preventDefault();
                    form.post('/institution/billing/addons', { preserveScroll: true });
                }}>
                    <Field label="What" error={form.errors.key}>
                        <select className={inputClass} value={form.data.key} onChange={(e) => form.setData('key', e.target.value)}>{addons.map((a) => <option key={a.key} value={a.key}>{a.label}</option>)}</select>
                    </Field>
                    <Field label="Blocks" hint={chosen ? `1 block = ${chosen.per.toLocaleString()} ${chosen.unit && chosen.unit !== 'accounts' && chosen.unit !== 'people' ? chosen.unit : chosen.label.toLowerCase()} for ${chosen.price}` : undefined} error={form.errors.blocks}>
                        <input type="number" min={1} max={100} className={inputClass} value={form.data.blocks} onChange={(e) => form.setData('blocks', Number(e.target.value))} />
                    </Field>
                    <div className="flex items-end"><Button disabled={form.processing || !chosen}>Create invoice</Button></div>
                </form>
            )}
        </section>
    );
}

function ChangeCard({ subscription: s, plans, cycles }: Props) {
    const form = useForm({ plan_id: s?.plan_id ?? plans[0]?.id, cycle: s?.cycle ?? 'yearly' });
    const plan = plans.find((x) => x.id === Number(form.data.plan_id));
    const same = s && Number(form.data.plan_id) === s.plan_id && form.data.cycle === s.cycle;

    return (
        <section className="surface flex flex-col gap-4 p-6">
            <div>
                <h2 className="text-lg font-medium">Change package or billing period</h2>
                <p className="text-sm text-muted-foreground">You get an invoice with credit for the unused part of the time you have already paid for. The change happens as soon as it is paid.</p>
            </div>
            <form className="grid gap-3 sm:grid-cols-4" onSubmit={(e) => {
                e.preventDefault();

                if (confirm(`Move to ${plan?.name} (${cycles[form.data.cycle]})?`)) {
                    form.post('/institution/billing/change', { preserveScroll: true });
                }
            }}>
                <Field label="Package" error={form.errors.plan_id}>
                    <select className={inputClass} value={form.data.plan_id} onChange={(e) => form.setData('plan_id', Number(e.target.value))}>{plans.map((x) => <option key={x.id} value={x.id}>{x.name}</option>)}</select>
                </Field>
                <Field label="Billing period" error={form.errors.cycle}>
                    <select className={inputClass} value={form.data.cycle} onChange={(e) => form.setData('cycle', e.target.value)}>{Object.entries(cycles).map(([k, v]) => <option key={k} value={k}>{v}{plan ? ` — ${plan.prices[k]}` : ''}</option>)}</select>
                </Field>
                <div className="flex items-end"><Button disabled={form.processing || !!same}>Continue</Button></div>
            </form>
            {plan?.features && plan.features.length > 0 && <ul className="grid gap-1 text-sm text-muted-foreground sm:grid-cols-2">{plan.features.map((f) => <li key={f}>• {f}</li>)}</ul>}
        </section>
    );
}

Billing.layout = { breadcrumbs: [{ title: 'Subscription & billing', href: '/institution/billing' }] };
