import { Head, Link } from '@inertiajs/react';
import { PageHeader, Table, Td } from '@/components/platform/ui';

export default function MarkingIndex({ exams }: { exams: { id: number; title: string; type: string; to_mark: number | null }[] }) {
    return (
        <>
            <Head title="Marking" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Scripts and" accent="score sheets." lead="Essays are marked anonymously. Practical, viva and research sheets work like the current assessment page, one row per examiner." />
                <Table head={['Exam', 'Format', 'Waiting', '']}>
                    {exams.map((e) => (
                        <tr key={e.id}>
                            <Td className="font-medium">{e.title}</Td>
                            <Td className="font-mono text-xs">{e.type}</Td>
                            <Td className="tabular-nums">{e.to_mark ?? '—'}</Td>
                            <Td>
                                <Link className="text-brand" href={['practical', 'viva', 'research'].includes(e.type) ? `/marking/${e.id}/panel` : `/marking/${e.id}/essays`}>
                                    {['practical', 'viva', 'research'].includes(e.type) ? 'Open score sheet' : 'Mark scripts'}
                                </Link>
                            </Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

MarkingIndex.layout = { breadcrumbs: [{ title: 'Marking', href: '/marking' }] };
