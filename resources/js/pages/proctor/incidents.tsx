import { Head, Link, router } from '@inertiajs/react';
import { PageHeader, Status, Table, Td } from '@/components/platform/ui';
import type { Paginated } from '@/types';

type Row = { id: number; type: string; severity: string; status: string; source: string; needs_review: boolean; title: string; exam: string | null; candidate: string | null; identifier: string | null; assignee: string | null; created_at: string };

export default function Incidents({ incidents, filters }: { incidents: Paginated<Row>; filters: { status?: string; review?: string } }) {
    return (
        <>
            <Head title="Incidents" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Incidents," accent="most serious first." lead="Automatic alerts need a person to confirm or dismiss them before any action against a candidate." />
                <div className="flex flex-wrap gap-2 text-sm">
                    {[['', 'Open'], ['resolved', 'Resolved'], ['dismissed', 'Dismissed']].map(([v, l]) => (
                        <button key={l} onClick={() => router.get('/incidents', { ...filters, status: v || undefined })} className={`rounded-full border px-3 py-1 ${(filters.status ?? '') === v ? 'border-brand bg-brand-soft' : 'border-border'}`}>
                            {l}
                        </button>
                    ))}
                    <button onClick={() => router.get('/incidents', { ...filters, review: filters.review ? undefined : 1 })} className={`rounded-full border px-3 py-1 ${filters.review ? 'border-brand bg-brand-soft' : 'border-border'}`}>
                        Awaiting human review
                    </button>
                </div>
                <Table head={['Severity', 'Incident', 'Candidate', 'Exam', 'Status', 'Assigned', 'Opened']}>
                    {incidents.data.map((i) => (
                        <tr key={i.id} className="hover:bg-muted/40">
                            <Td><Status value={i.severity} /></Td>
                            <Td>
                                <Link href={`/incidents/${i.id}`} className="font-medium hover:text-brand">{i.title}</Link>
                                <div className="text-xs text-muted-foreground">{i.type} · {i.source}{i.needs_review && ' · needs review'}</div>
                            </Td>
                            <Td>{i.candidate}<div className="font-mono text-xs text-muted-foreground">{i.identifier}</div></Td>
                            <Td>{i.exam}</Td>
                            <Td><Status value={i.status} /></Td>
                            <Td>{i.assignee ?? '—'}</Td>
                            <Td className="text-xs">{i.created_at}</Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

Incidents.layout = { breadcrumbs: [{ title: 'Incidents', href: '/incidents' }] };
