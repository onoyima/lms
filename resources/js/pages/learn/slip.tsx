import { Head } from '@inertiajs/react';
import { Printer } from 'lucide-react';
import { formatDateTime } from '@/components/platform/ui';
import { usePlatform } from '@/hooks/use-platform';

type Props = {
    student: {
        name: string;
        identifier: string | null;
        programme: string | null;
        level: number | null;
    };
    semester: string;
    rows: {
        code: string;
        title: string;
        units: number;
        category: string;
        status: string;
    }[];
    generated_at: string;
    reference: string;
};

export default function Slip({
    student,
    semester,
    rows,
    generated_at,
    reference,
}: Props) {
    const { tenant } = usePlatform();

    return (
        <>
            <Head title="Registration slip" />
            <div className="mx-auto w-full max-w-3xl p-6 md:p-10 print:p-0">
                <button
                    onClick={() => window.print()}
                    className="mb-6 flex items-center gap-1 rounded-xl border border-border px-3 py-2 text-sm print:hidden"
                >
                    <Printer className="size-4" /> Print
                </button>
                <div className="rounded-lg border border-border bg-white p-10 text-[#0b0d10]">
                    <p className="text-xs font-semibold tracking-[0.25em] uppercase">
                        {tenant?.name}
                    </p>
                    <h1 className="mt-2 text-2xl font-semibold">
                        Course registration slip
                    </h1>
                    <p className="text-sm text-gray-600">{semester}</p>
                    <dl className="mt-6 grid grid-cols-2 gap-2 text-sm">
                        <dt className="text-gray-500">Name</dt>
                        <dd>{student.name}</dd>
                        <dt className="text-gray-500">Matric number</dt>
                        <dd className="font-mono">{student.identifier}</dd>
                        <dt className="text-gray-500">Programme</dt>
                        <dd>{student.programme ?? '—'}</dd>
                        <dt className="text-gray-500">Level</dt>
                        <dd>{student.level ?? '—'}</dd>
                    </dl>
                    <table className="mt-6 w-full text-sm">
                        <thead>
                            <tr className="border-b border-gray-300 text-left">
                                <th className="py-2">Code</th>
                                <th>Title</th>
                                <th>Type</th>
                                <th className="text-right">Units</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((r) => (
                                <tr
                                    key={r.code}
                                    className="border-b border-gray-100"
                                >
                                    <td className="py-2 font-mono">{r.code}</td>
                                    <td>
                                        {r.title}
                                        {r.status === 'pending' && ' (pending)'}
                                    </td>
                                    <td className="capitalize">{r.category}</td>
                                    <td className="text-right">{r.units}</td>
                                </tr>
                            ))}
                            <tr>
                                <td colSpan={3} className="py-2 font-semibold">
                                    Total units
                                </td>
                                <td className="text-right font-semibold">
                                    {rows.reduce((a, r) => a + r.units, 0)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p className="mt-8 text-xs text-gray-500">
                        Generated {formatDateTime(generated_at)} · Verification
                        reference <span className="font-mono">{reference}</span>
                    </p>
                    <div className="mt-10 grid grid-cols-2 gap-10 text-xs text-gray-500">
                        <p className="border-t border-gray-300 pt-2">Student</p>
                        <p className="border-t border-gray-300 pt-2">
                            Level adviser / HOD
                        </p>
                    </div>
                </div>
            </div>
        </>
    );
}
