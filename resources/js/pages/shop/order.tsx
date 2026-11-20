import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Clock3, Download } from 'lucide-react';
import { formatDateTime, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/money';

type Order = {
    number: string; status: string; subtotal: number; discount: number; total: number; currency: string; provider: string | null;
    receipt_code: string | null; paid_at: string | null; created_at: string; items: { title: string; price: number }[];
};

/** One order: paid (with receipt and a way to the exams) or still waiting for the payment to be confirmed. */
export default function ShopOrder({ order }: { order: Order }) {
    const paid = order.status === 'paid';

    return (
        <>
            <Head title={`Order ${order.number}`} />
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader title={`Order ${order.number}`} lead={`Placed ${formatDateTime(order.created_at)}`} />
                <section className="surface flex flex-wrap items-center gap-4 p-5">
                    {paid ? <CheckCircle2 className="size-8 shrink-0 text-status-good" /> : <Clock3 className="size-8 shrink-0 text-status-warning" />}
                    <p className="min-w-0 flex-1">{paid ? 'Paid. Your exams are ready in My test series.' : 'Waiting for the payment to be confirmed. If you have paid, this page updates within a few minutes; reload it.'}</p>
                    {paid && <Button asChild><Link href="/my/test-series">My test series</Link></Button>}
                </section>
                <section className="surface p-5">
                    <ul className="divide-y divide-border">
                        {order.items.map((i, k) => <li key={k} className="flex justify-between gap-4 py-2.5"><span className="min-w-0">{i.title}</span><span>{money(i.price, order.currency)}</span></li>)}
                        {order.discount > 0 && <li className="flex justify-between gap-4 py-2.5 text-muted-foreground"><span>Discount</span><span>−{money(order.discount, order.currency)}</span></li>}
                        <li className="flex justify-between gap-4 py-2.5 font-semibold"><span>Total</span><span>{money(order.total, order.currency)}</span></li>
                    </ul>
                    {paid && <Button variant="outline" className="mt-4" asChild><a href={`/shop/orders/${order.number}/receipt.pdf`}><Download className="size-4" /> Receipt (PDF)</a></Button>}
                </section>
            </div>
        </>
    );
}
