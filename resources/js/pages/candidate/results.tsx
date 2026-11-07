import { Head, router, usePage } from '@inertiajs/react';
import { PageHeader, Status, Table, Td } from '@/components/platform/ui';

type Row = { id: number; exam: string; type: string; score: number | null; out_of: number; grade: string | null; published_at: string | null; appeal: string | null };

export default function MyResults({ results }: { results: Row[] }) {
    const { flash } = usePage().props;

    return (
        <>
            <Head title="My results" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader title="Published" accent="results." lead="If you believe a mark is wrong, request a recheck within the period your institution allows." />
                {flash?.success && <p className="text-sm text-status-good">{flash.success}</p>}
                <Table head={['Exam', 'Score', 'Grade', 'Published', 'Recheck']}>
                    {results.map((r) => (
                        <tr key={r.id}>
                            <Td className="font-medium">{r.exam}</Td>
                            <Td className="tabular-nums">{r.score} / {r.out_of}</Td>
                            <Td className="font-semibold">{r.grade}</Td>
                            <Td className="text-xs">{r.published_at}</Td>
                            <Td>{r.appeal ? <Status value={r.appeal} /> : <button className="text-sm text-brand" onClick={() => {
 const statement = prompt('Explain why you want this result rechecked (at least 20 characters):');

 if (statement) {
router.post(`/my/results/${r.id}/appeal`, { statement });
} 
}}>Request recheck</button>}</Td>
                        </tr>
                    ))}
                </Table>
            </div>
        </>
    );
}

MyResults.layout = { breadcrumbs: [{ title: 'My results', href: '/my/results' }] };
