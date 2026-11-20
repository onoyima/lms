import { Head } from '@inertiajs/react';
import { Award, Download, ShieldCheck } from 'lucide-react';
import { PageHeader } from '@/components/platform/ui';
import { BADGE_ICONS } from '@/lib/badges';

type MyAward = { code: string; subject_title: string; percent: number | null; grade: string | null; created_at: string; name: string; kind: string; colour: string; badge_icon: string | null };

/** The student's certificates (download, share the check link) and badges. */
export default function MyCertificates({ awards }: { awards: MyAward[] }) {
    const badges = awards.filter((a) => a.kind === 'badge');
    const certificates = awards.filter((a) => a.kind !== 'badge');

    return (
        <>
            <Head title="Certificates and badges" />
            <div className="flex flex-col gap-6 p-4 sm:p-6 md:p-10">
                <PageHeader title="Certificates and badges" lead="Earned by passing exams. Anyone you share a certificate with can check it is genuine with its code." />
                {awards.length === 0 && <p className="surface p-6 text-muted-foreground">Nothing yet. Certificates arrive when your results are released.</p>}
                {badges.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">Badges</h2>
                        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                            {badges.map((b) => {
                                const I = BADGE_ICONS[b.badge_icon ?? 'award'] ?? Award;

                                return (
                                    <div key={b.code} className="surface grid justify-items-center gap-2 p-4 text-center">
                                        <span className="grid size-16 place-items-center rounded-full text-white shadow-lg" style={{ background: b.colour }}><I className="size-8" /></span>
                                        <p className="text-sm font-semibold">{b.name}</p>
                                        <p className="text-xs text-muted-foreground">{b.subject_title}</p>
                                    </div>
                                );
                            })}
                        </div>
                    </section>
                )}
                {certificates.length > 0 && (
                    <section className="grid gap-3">
                        <h2 className="text-lg font-semibold">Certificates</h2>
                        {certificates.map((c) => (
                            <article key={c.code} className="surface flex flex-wrap items-center gap-4 p-4">
                                <span className="grid size-12 shrink-0 place-items-center rounded-2xl text-white" style={{ background: c.colour }}><Award className="size-6" /></span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold">{c.subject_title}</p>
                                    <p className="text-xs text-muted-foreground">{c.name}{c.percent !== null ? ` · ${c.percent}%` : ''}{c.grade ? ` · grade ${c.grade}` : ''} · {new Date(c.created_at).toLocaleDateString()}</p>
                                </div>
                                <a href={`/certificates/${c.code}.pdf`} className="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-2 text-sm font-medium hover:border-brand"><Download className="size-4" /> PDF</a>
                                <a href={`/verify/${c.code}`} className="inline-flex items-center gap-1.5 rounded-xl border border-border px-3 py-2 text-sm font-medium hover:border-brand"><ShieldCheck className="size-4" /> Check link</a>
                            </article>
                        ))}
                    </section>
                )}
            </div>
        </>
    );
}
