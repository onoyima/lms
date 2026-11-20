import { Head, router } from '@inertiajs/react';
import { CalendarClock, Check, FileText } from 'lucide-react';
import { ShopShell } from '@/components/shop/shell';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/money';

type Pkg = { id: number; title: string; description: string | null; price: number; currency: string; access_days: number; exams: number };

/** The institution's catalogue of practice and mock exam packages. */
export default function ShopIndex({ packages, cart, institution }: { packages: Pkg[]; cart: number[]; institution: string | null }) {
    return (
        <ShopShell cartCount={cart.length}>
            <Head title="Test series" />
            <h1 className="text-2xl font-semibold sm:text-3xl">Practice and mock exams</h1>
            <p className="mt-2 max-w-2xl text-muted-foreground">Buy a package and write its exams online{institution ? ` with ${institution}` : ''}, as often as the package allows, until your access ends.</p>
            {packages.length === 0 ? (
                <p className="surface mt-8 p-6 text-sm text-muted-foreground">No packages are on sale right now.</p>
            ) : (
                <div className="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {packages.map((p) => {
                        const inCart = cart.includes(p.id);

                        return (
                            <article key={p.id} className="surface flex flex-col p-5">
                                <h2 className="text-lg font-semibold">{p.title}</h2>
                                {p.description && <p className="mt-2 text-sm whitespace-pre-line text-muted-foreground">{p.description}</p>}
                                <ul className="mt-4 grid gap-1.5 text-sm">
                                    <li className="flex items-center gap-2"><FileText className="size-4 text-muted-foreground" /> {p.exams} {p.exams === 1 ? 'exam' : 'exams'}</li>
                                    <li className="flex items-center gap-2"><CalendarClock className="size-4 text-muted-foreground" /> {p.access_days} days of access</li>
                                </ul>
                                <div className="mt-auto flex flex-wrap items-center justify-between gap-3 pt-5">
                                    <span className="text-xl font-semibold">{p.price === 0 ? 'Free' : money(p.price, p.currency)}</span>
                                    {inCart ? (
                                        <Button variant="outline" onClick={() => router.delete(`/shop/cart/${p.id}`, { preserveScroll: true })}><Check className="size-4" /> In cart · remove</Button>
                                    ) : (
                                        <Button onClick={() => router.post(`/shop/cart/${p.id}`, {}, { preserveScroll: true })}>Add to cart</Button>
                                    )}
                                </div>
                            </article>
                        );
                    })}
                </div>
            )}
            {cart.length > 0 && (
                <div className="sticky bottom-4 mt-8 flex justify-end">
                    <Button size="lg" onClick={() => router.visit('/shop/checkout')}>Check out ({cart.length})</Button>
                </div>
            )}
        </ShopShell>
    );
}
