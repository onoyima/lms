import { Head, Link } from '@inertiajs/react';
import { formatDateTime, PageHeader, Status, Table, Td } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';
import { money } from '@/lib/money';

type Pkg = { id: number; title: string; expires_at: string; active: boolean; exams: { title: string; minutes: number; candidate: number | null }[] };
type Order = { number: string; status: string; total: number; currency: string; created_at: string; receipt_code: string | null };

/** The packages a candidate has bought, their exams, and past orders. */
export default function MyTestSeries({ packages, orders }: { packages: Pkg[]; orders: Order[] }) {
    return (
        <>
            <Head title="My test series" />
            <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader title="My test series" lead="Practice and mock exams you have bought." actions={<Button variant="outline" asChild><Link href="/shop">Browse packages</Link></Button>} />
                {packages.length === 0 && <p className="surface p-6 text-sm text-muted-foreground">You have not bought a package yet.</p>}
                {packages.map((p) => (
                    <section key={p.id} className="surface p-5">
                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 className="mr-auto text-lg font-semibold">{p.title}</h2>
                            <span className={`text-sm ${p.active ? 'text-muted-foreground' : 'text-status-critical'}`}>{p.active ? 'Access until' : 'Access ended'} {formatDateTime(p.expires_at)}</span>
                        </div>
                        <ul className="mt-3 divide-y divide-border">
                            {p.exams.map((e, i) => (
                                <li key={i} className="flex flex-wrap items-center gap-3 py-2.5">
                                    <span className="min-w-0 flex-1">{e.title} <span className="text-sm text-muted-foreground">· {e.minutes} min</span></span>
                                    {p.active && e.candidate ? <Button size="sm" asChild><Link href={`/my/exams/${e.candidate}`}>Open</Link></Button> : <span className="text-sm text-muted-foreground">Not available</span>}
                                </li>
                            ))}
                        </ul>
                    </section>
                ))}
                {orders.length > 0 && (
                    <Table head={['Order', 'Date', 'Total', 'Status', '']}>
                        {orders.map((o) => (
                            <tr key={o.number}>
                                <Td className="font-mono text-xs"><Link href={`/shop/orders/${o.number}`} className="underline-offset-4 hover:underline">{o.number}</Link></Td>
                                <Td>{formatDateTime(o.created_at)}</Td><Td>{money(o.total, o.currency)}</Td><Td><Status value={o.status} /></Td>
                                <Td>{o.status === 'paid' && <a className="text-sm underline-offset-4 hover:underline" href={`/shop/orders/${o.number}/receipt.pdf`}>Receipt</a>}</Td>
                            </tr>
                        ))}
                    </Table>
                )}
            </div>
        </>
    );
}
