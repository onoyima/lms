import { Head, Link } from '@inertiajs/react';
import { Flash, formatDateTime, PageHeader, Status, Table, Td } from '@/components/platform/ui';

type Payment = { id: number; provider: string; reference: string; status: string; paid_at: string | null; note: string | null; institution: string | null; tenant_id: number; amount_label: string };
type Open = { id: number; number: string; due_at: string; tenant_id: number; institution: string | null; amount_label: string; overdue: boolean };

export default function Payments({ payments, invoices }: { payments: Payment[]; invoices: Open[] }) {
    return (
        <>
            <Head title="Payments & invoices" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Payments" accent="& invoices" lead="Card payments are confirmed with Paystack before they count. Bank transfers are recorded from the institution's page once the money is in the account." />
                <Flash />
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Open invoices</h2>
                    <Table head={['Invoice', 'Institution', 'Amount', 'Due']}>
                        {invoices.length === 0 && <tr><Td className="text-muted-foreground">Nothing outstanding.</Td></tr>}
                        {invoices.map((i) => (
                            <tr key={i.id}>
                                <Td className="font-mono text-xs">{i.number}</Td>
                                <Td><Link className="hover:underline" href={`/platform/institutions/${i.tenant_id}`}>{i.institution}</Link></Td>
                                <Td className="tabular-nums">{i.amount_label}</Td>
                                <Td className={i.overdue ? 'font-medium text-status-critical' : ''}>{formatDateTime(i.due_at)}{i.overdue ? ' · overdue' : ''}</Td>
                            </tr>
                        ))}
                    </Table>
                </section>
                <section className="flex flex-col gap-3">
                    <h2 className="text-lg font-medium">Payments received</h2>
                    <Table head={['When', 'Institution', 'Amount', 'Method', 'Reference', 'Status']}>
                        {payments.length === 0 && <tr><Td className="text-muted-foreground">No payments yet.</Td></tr>}
                        {payments.map((p) => (
                            <tr key={p.id}>
                                <Td>{formatDateTime(p.paid_at)}</Td>
                                <Td><Link className="hover:underline" href={`/platform/institutions/${p.tenant_id}`}>{p.institution}</Link></Td>
                                <Td className="tabular-nums">{p.amount_label}</Td>
                                <Td>{p.provider.replace('_', ' ')}</Td>
                                <Td className="font-mono text-xs">{p.reference}</Td>
                                <Td><Status value={p.status} /></Td>
                            </tr>
                        ))}
                    </Table>
                </section>
            </div>
        </>
    );
}

Payments.layout = { breadcrumbs: [{ title: 'Payments & invoices', href: '/platform/payments' }] };
