import { Head, router, useForm } from '@inertiajs/react';
import { Copy, Download, Pencil, Plus, RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Stat, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/money';

type Pkg = { id: number; title: string; description: string | null; price: number; currency: string; access_days: number; active: boolean; position: number; exam_ids: number[]; exam_titles: string[]; sold: number };
type Voucher = { id: number; code: string; kind: 'percent' | 'amount' | 'free'; value: number; test_package_id: number | null; max_uses: number | null; used: number; expires_at: string | null; active: boolean };
type Order = { id: number; number: string; status: string; subtotal: number; discount: number; total: number; currency: string; provider: string | null; paid_at: string | null; created_at: string; buyer: { name: string; email: string } | null };
type Props = {
    packages: Pkg[]; vouchers: Voucher[]; orders: Order[]; totals: { currency: string; orders: number; total: number }[];
    exams: { id: number; title: string; category: string }[];
    settings: { currency: string; has_key: boolean; open: boolean; webhook: string; link: string };
};

const CURRENCIES = ['NGN', 'USD', 'GHS', 'KES', 'ZAR', 'EUR', 'GBP', 'XOF', 'XAF', 'UGX', 'TZS', 'RWF', 'EGP'];

function PackageForm({ pkg, exams, currency, onDone }: { pkg?: Pkg; exams: Props['exams']; currency: string; onDone: () => void }) {
    const form = useForm({
        title: pkg?.title ?? '', description: pkg?.description ?? '', price: pkg ? pkg.price / 100 : 0, currency: pkg?.currency ?? currency,
        access_days: pkg?.access_days ?? 90, active: pkg?.active ?? true, position: pkg?.position ?? 0, exam_ids: pkg?.exam_ids ?? ([] as number[]),
    });
    const [filter, setFilter] = useState('');
    const toggle = (id: number) => form.setData('exam_ids', form.data.exam_ids.includes(id) ? form.data.exam_ids.filter((x) => x !== id) : [...form.data.exam_ids, id]);
    const shown = exams.filter((e) => !filter || e.title.toLowerCase().includes(filter.toLowerCase()));
    const submit = () => (pkg ? form.put(`/admin/shop/packages/${pkg.id}`, { preserveScroll: true, onSuccess: onDone }) : form.post('/admin/shop/packages', { preserveScroll: true, onSuccess: onDone }));

    return (
        <form className="surface grid gap-4 p-5" onSubmit={(e) => (e.preventDefault(), submit())}>
            <div className="grid gap-4 md:grid-cols-2">
                <Field label="Title" error={form.errors.title}><input className={inputClass} value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></Field>
                <div className="grid grid-cols-2 gap-3">
                    <Field label="Price" error={form.errors.price}><input type="number" min={0} step="0.01" className={inputClass} value={form.data.price} onChange={(e) => form.setData('price', Number(e.target.value))} /></Field>
                    <Field label="Currency" error={form.errors.currency}>
                        <input className={inputClass} list="shop-currencies" maxLength={3} value={form.data.currency} onChange={(e) => form.setData('currency', e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} />
                    </Field>
                </div>
                <Field label="Access (days after buying)" error={form.errors.access_days}><input type="number" min={1} className={inputClass} value={form.data.access_days} onChange={(e) => form.setData('access_days', Number(e.target.value))} /></Field>
                <Field label="Order in the shop"><input type="number" min={0} className={inputClass} value={form.data.position} onChange={(e) => form.setData('position', Number(e.target.value))} /></Field>
                <div className="md:col-span-2"><Field label="Description"><textarea className={textareaClass} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} /></Field></div>
            </div>
            <fieldset className="grid gap-2">
                <legend className="mb-1 text-sm font-medium">Exams in this package ({form.data.exam_ids.length})</legend>
                <input className={inputClass} placeholder="Find an exam" value={filter} onChange={(e) => setFilter(e.target.value)} />
                <div className="max-h-56 overflow-y-auto rounded-xl border border-border p-2">
                    {shown.map((e) => (
                        <label key={e.id} className="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-foreground/[.04]">
                            <input type="checkbox" checked={form.data.exam_ids.includes(e.id)} onChange={() => toggle(e.id)} />
                            <span className="min-w-0 flex-1 truncate">{e.title}</span><span className="text-xs text-muted-foreground">{e.category}</span>
                        </label>
                    ))}
                    {shown.length === 0 && <p className="p-2 text-sm text-muted-foreground">No exams found.</p>}
                </div>
                {form.errors.exam_ids && <p className="text-sm text-status-critical">{form.errors.exam_ids}</p>}
            </fieldset>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={form.data.active} onChange={(e) => form.setData('active', e.target.checked)} /> On sale</label>
            <div className="flex flex-wrap gap-2"><Button disabled={form.processing}>{pkg ? 'Save package' : 'Add package'}</Button><Button type="button" variant="ghost" onClick={onDone}>Cancel</Button></div>
        </form>
    );
}

function VoucherForm({ packages }: { packages: Pkg[] }) {
    const form = useForm({ code: '', kind: 'percent' as Voucher['kind'], value: 10, test_package_id: '' as number | '', max_uses: '' as number | '', expires_at: '', count: 1 });

    return (
        <form className="surface grid gap-4 p-5" onSubmit={(e) => (e.preventDefault(), form.post('/admin/shop/vouchers', { preserveScroll: true, onSuccess: () => form.reset('code') }))}>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <Field label="Code (empty makes random codes)" error={form.errors.code}><input className={inputClass} value={form.data.code} onChange={(e) => form.setData('code', e.target.value.toUpperCase())} /></Field>
                <Field label="How many codes" error={form.errors.count}><input type="number" min={1} max={500} className={inputClass} value={form.data.count} disabled={!!form.data.code} onChange={(e) => form.setData('count', Number(e.target.value))} /></Field>
                <Field label="Discount">
                    <select className={inputClass} value={form.data.kind} onChange={(e) => form.setData('kind', e.target.value as Voucher['kind'])}>
                        <option value="percent">Percentage off</option><option value="amount">Amount off</option><option value="free">Free</option>
                    </select>
                </Field>
                {form.data.kind !== 'free' && <Field label={form.data.kind === 'percent' ? 'Percent' : 'Amount'} error={form.errors.value}><input type="number" min={0} step="0.01" className={inputClass} value={form.data.value} onChange={(e) => form.setData('value', Number(e.target.value))} /></Field>}
                <Field label="For package">
                    <select className={inputClass} value={form.data.test_package_id} onChange={(e) => form.setData('test_package_id', e.target.value ? Number(e.target.value) : '')}>
                        <option value="">Any package</option>{packages.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}
                    </select>
                </Field>
                <Field label="Uses per code (empty: no limit)" error={form.errors.max_uses}><input type="number" min={1} className={inputClass} value={form.data.max_uses} onChange={(e) => form.setData('max_uses', e.target.value ? Number(e.target.value) : '')} /></Field>
                <Field label="Expires" error={form.errors.expires_at}><input type="date" className={inputClass} value={form.data.expires_at} onChange={(e) => form.setData('expires_at', e.target.value)} /></Field>
            </div>
            <div><Button disabled={form.processing}><Plus className="size-4" /> Make vouchers</Button></div>
        </form>
    );
}

/** The institution's shop: packages, vouchers, orders and the Paystack account payments go to. */
export default function ShopAdmin({ packages, vouchers, orders, totals, exams, settings }: Props) {
    const [editing, setEditing] = useState<number | 'new' | null>(null);
    const s = useForm({ currency: settings.currency, paystack_secret: '', remove_key: false, open: settings.open });
    const pkgTitle = (id: number | null) => (id ? packages.find((p) => p.id === id)?.title ?? '—' : 'Any');

    return (
        <>
            <Head title="Test-series shop" />
            <datalist id="shop-currencies">{CURRENCIES.map((c) => <option key={c} value={c} />)}</datalist>
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader title="Test-series shop" lead="Sell packages of practice and mock exams. Buyers are added to every exam in a package, in an always-open sitting, until their access ends."
                    actions={<Button variant="outline" onClick={() => void navigator.clipboard?.writeText(settings.link)}><Copy className="size-4" /> Copy shop link</Button>} />
                <Flash />
                {totals.length > 0 && <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{totals.map((t) => <Stat key={t.currency} label={`Paid orders (${t.currency})`} value={`${t.orders} · ${money(Number(t.total), t.currency)}`} />)}</div>}

                <section className="grid gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-semibold">Packages</h2>{editing === null && <Button onClick={() => setEditing('new')}><Plus className="size-4" /> New package</Button>}</div>
                    {editing === 'new' && <PackageForm exams={exams} currency={settings.currency} onDone={() => setEditing(null)} />}
                    {packages.length === 0 && editing === null && <p className="surface p-5 text-sm text-muted-foreground">No packages yet. Add one and choose the exams it gives access to.</p>}
                    {packages.map((p) => editing === p.id ? <PackageForm key={p.id} pkg={p} exams={exams} currency={settings.currency} onDone={() => setEditing(null)} /> : (
                        <article key={p.id} className="surface flex flex-wrap items-start gap-4 p-5">
                            <div className="min-w-0 flex-1">
                                <h3 className="font-semibold">{p.title} {!p.active && <span className="ml-1 text-sm font-normal text-muted-foreground">(not on sale)</span>}</h3>
                                <p className="mt-1 text-sm text-muted-foreground">{money(p.price, p.currency)} · {p.access_days} days · {p.exam_titles.length} exams · {p.sold} sold</p>
                                {p.exam_titles.length > 0 && <p className="mt-1 truncate text-sm">{p.exam_titles.join(', ')}</p>}
                            </div>
                            <Button variant="outline" size="sm" onClick={() => setEditing(p.id)}><Pencil className="size-4" /> Edit</Button>
                        </article>
                    ))}
                </section>

                <section className="grid gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-lg font-semibold">Vouchers</h2>{vouchers.length > 0 && <Button variant="outline" size="sm" asChild><a href="/admin/shop/vouchers.csv"><Download className="size-4" /> Download codes</a></Button>}</div>
                    <VoucherForm packages={packages} />
                    {vouchers.length > 0 && (
                        <Table head={['Code', 'Discount', 'Package', 'Used', 'Expires', '']}>
                            {vouchers.map((v) => (
                                <tr key={v.id} className={v.active ? '' : 'opacity-60'}>
                                    <Td className="font-mono text-xs">{v.code}</Td>
                                    <Td>{v.kind === 'free' ? 'Free' : v.kind === 'percent' ? `${v.value}%` : money(v.value, settings.currency)}</Td>
                                    <Td>{pkgTitle(v.test_package_id)}</Td><Td>{v.used}{v.max_uses ? ` of ${v.max_uses}` : ''}</Td><Td>{v.expires_at ? formatDateTime(v.expires_at) : '—'}</Td>
                                    <Td><Button variant="ghost" size="sm" onClick={() => router.post(`/admin/shop/vouchers/${v.id}/toggle`, {}, { preserveScroll: true })}>{v.active ? 'Turn off' : 'Turn on'}</Button></Td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </section>

                <section className="grid gap-4">
                    <h2 className="text-lg font-semibold">Orders</h2>
                    {orders.length === 0 ? <p className="surface p-5 text-sm text-muted-foreground">No orders yet.</p> : (
                        <Table head={['Order', 'Buyer', 'Date', 'Total', 'Status', '']}>
                            {orders.map((o) => (
                                <tr key={o.id}>
                                    <Td className="font-mono text-xs">{o.number}</Td><Td>{o.buyer?.name}<span className="block text-xs text-muted-foreground">{o.buyer?.email}</span></Td>
                                    <Td>{formatDateTime(o.created_at)}</Td><Td>{money(o.total, o.currency)}</Td><Td><Status value={o.status} /></Td>
                                    <Td>
                                        {o.status === 'paid' ? <a className="text-sm underline-offset-4 hover:underline" href={`/shop/orders/${o.number}/receipt.pdf`}>Receipt</a>
                                            : o.provider === 'paystack' && <Button variant="ghost" size="sm" onClick={() => router.post(`/admin/shop/orders/${o.id}/recheck`, {}, { preserveScroll: true })}><RefreshCw className="size-4" /> Check payment</Button>}
                                    </Td>
                                </tr>
                            ))}
                        </Table>
                    )}
                </section>

                <form className="surface grid gap-4 p-5" onSubmit={(e) => (e.preventDefault(), s.put('/admin/shop/settings', { preserveScroll: true, onSuccess: () => s.reset('paystack_secret', 'remove_key') }))}>
                    <h2 className="text-lg font-semibold">Payment and settings</h2>
                    <div className="grid gap-4 md:grid-cols-2">
                        <Field label="Usual currency for new packages" error={s.errors.currency}><input className={inputClass} list="shop-currencies" maxLength={3} value={s.data.currency} onChange={(e) => s.setData('currency', e.target.value.toUpperCase().replace(/[^A-Z]/g, ''))} /></Field>
                        <Field label={settings.has_key ? 'Paystack secret key (saved; type a new one to change it)' : 'Paystack secret key (sk_live_… or sk_test_…)'} error={s.errors.paystack_secret}>
                            <input type="password" autoComplete="off" className={inputClass} value={s.data.paystack_secret} placeholder={settings.has_key ? '••••••••••••' : ''} onChange={(e) => s.setData('paystack_secret', e.target.value.trim())} />
                        </Field>
                    </div>
                    <p className="text-sm text-muted-foreground">Payments go straight to your institution's Paystack account. In Paystack, set the webhook address to <span className="font-mono text-xs break-all">{settings.webhook}</span></p>
                    <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s.data.open} onChange={(e) => s.setData('open', e.target.checked)} /> The shop is open to buyers</label>
                    {settings.has_key && <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s.data.remove_key} onChange={(e) => s.setData('remove_key', e.target.checked)} /> Remove the saved key</label>}
                    <div><Button disabled={s.processing}>Save settings</Button></div>
                </form>
            </div>
        </>
    );
}
