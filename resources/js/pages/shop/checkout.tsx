import { Head, Link, router, useForm } from '@inertiajs/react';
import { LoaderCircle, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Field, inputClass } from '@/components/platform/ui';
import { ShopShell } from '@/components/shop/shell';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/money';

type Price = {
    items: { id: number; title: string; price: number; access_days: number }[];
    subtotal: number; discount: number; total: number; currency: string; voucher_code: string | null; voucher_error: string | null;
};

/** Cart, voucher and payment. Visitors make an account here (or sign in) before paying. */
export default function Checkout({ price, signedIn, online }: { price: Price; signedIn: boolean; online: boolean }) {
    const [code, setCode] = useState(price.voucher_code ?? '');
    const form = useForm({ voucher: price.voucher_code ?? '', name: '', email: '', phone: '', password: '' });
    const canPay = price.total === 0 || online;

    return (
        <ShopShell cartCount={price.items.length}>
            <Head title="Checkout" />
            <h1 className="text-2xl font-semibold">Checkout</h1>
            <div className="mt-6 grid gap-6 lg:grid-cols-[1fr_360px]">
                <section className="surface p-5">
                    <ul className="divide-y divide-border">
                        {price.items.map((i) => (
                            <li key={i.id} className="flex flex-wrap items-center gap-3 py-3">
                                <span className="min-w-0 flex-1"><b className="block font-medium">{i.title}</b><span className="text-sm text-muted-foreground">{i.access_days} days of access</span></span>
                                <span>{money(i.price, price.currency)}</span>
                                <Button variant="ghost" size="icon" aria-label={`Remove ${i.title}`} onClick={() => router.delete(`/shop/cart/${i.id}`)}><Trash2 className="size-4" /></Button>
                            </li>
                        ))}
                    </ul>
                    <form className="mt-4 flex flex-wrap items-end gap-2" onSubmit={(e) => (e.preventDefault(), router.get('/shop/checkout', code ? { voucher: code } : {}, { preserveScroll: true }))}>
                        <div className="min-w-0 flex-1"><Field label="Voucher code" error={price.voucher_error ?? undefined}><input className={inputClass} value={code} onChange={(e) => setCode(e.target.value.toUpperCase())} /></Field></div>
                        <Button type="submit" variant="outline">Apply</Button>
                    </form>
                    <Link href="/shop" className="mt-4 inline-block text-sm underline-offset-4 hover:underline">Keep browsing</Link>
                </section>
                <form className="surface grid h-fit gap-4 p-5" onSubmit={(e) => (e.preventDefault(), form.transform((d) => ({ ...d, voucher: price.voucher_code ?? '' })), form.post('/shop/checkout'))}>
                    <dl className="grid grid-cols-[1fr_auto] gap-y-1.5 text-sm">
                        <dt className="text-muted-foreground">Subtotal</dt><dd>{money(price.subtotal, price.currency)}</dd>
                        {price.discount > 0 && <><dt className="text-muted-foreground">Voucher {price.voucher_code}</dt><dd>−{money(price.discount, price.currency)}</dd></>}
                        <dt className="pt-2 font-semibold">Total</dt><dd className="pt-2 text-lg font-semibold">{money(price.total, price.currency)}</dd>
                    </dl>
                    {!signedIn && (
                        <div className="grid gap-3 border-t border-border pt-4">
                            <p className="text-sm">Make an account to write your exams. Already have one? <Link href="/login" className="underline">Sign in</Link> first.</p>
                            <Field label="Full name" error={form.errors.name}><input className={inputClass} autoComplete="name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required /></Field>
                            <Field label="Email" error={form.errors.email}><input type="email" className={inputClass} autoComplete="email" value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} required /></Field>
                            <Field label="Mobile number (optional)" error={form.errors.phone}><input type="tel" className={inputClass} autoComplete="tel" value={form.data.phone} onChange={(e) => form.setData('phone', e.target.value)} /></Field>
                            <Field label="Password (8 or more characters)" error={form.errors.password}><input type="password" className={inputClass} autoComplete="new-password" minLength={8} value={form.data.password} onChange={(e) => form.setData('password', e.target.value)} required /></Field>
                        </div>
                    )}
                    {!canPay && <p className="text-sm text-status-critical">Online payment is not set up for this shop yet.</p>}
                    <Button size="lg" disabled={form.processing || !canPay}>
                        {form.processing && <LoaderCircle className="size-4 animate-spin" />} {price.total === 0 ? 'Get access' : `Pay ${money(price.total, price.currency)}`}
                    </Button>
                    {price.total > 0 && <p className="text-xs text-muted-foreground">You pay on Paystack's page, then come back here. Access starts once the payment is confirmed.</p>}
                </form>
            </div>
        </ShopShell>
    );
}
