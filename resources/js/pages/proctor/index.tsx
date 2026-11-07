import { Head, Link } from '@inertiajs/react';
import { formatDateTime, PageHeader, Status, Table, Td } from '@/components/platform/ui';

type Row = { id: number; exam: string; name: string | null; venue: string | null; mode: string; status: string; starts_at: string; ends_at: string };

export default function ProctorIndex({ sessions }: { sessions: Row[] }) {
    return (
        <>
            <Head title="Proctoring" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Sittings" accent="you watch." lead="Today's and upcoming sittings where you are a proctor, invigilator or supervisor." />
                <Table head={['Exam', 'Sitting', 'Where', 'When', 'Status', '']}>
                    {sessions.map((s) => (
                        <tr key={s.id}>
                            <Td className="font-medium">{s.exam}</Td>
                            <Td>{s.name ?? `#${s.id}`}</Td>
                            <Td>{s.venue ?? s.mode}</Td>
                            <Td className="text-xs">{formatDateTime(s.starts_at)}</Td>
                            <Td><Status value={s.status} /></Td>
                            <Td><Link className="text-brand" href={`/proctor/sessions/${s.id}`}>Open console</Link></Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

ProctorIndex.layout = { breadcrumbs: [{ title: 'Proctoring', href: '/proctor' }] };
