import { Link } from '@inertiajs/react';
import { Phone } from 'lucide-react';

export type IncidentContact = { role: string; name: string; phone?: string | null; email?: string | null; hours?: string | null };

/** Who to call during an exam, as set in Policy (per campus where set), plus the written procedure. */
export function IncidentContacts({ contacts }: { contacts: IncidentContact[] }) {
    return (
        <section className="surface grid gap-2 p-4 text-sm" aria-label="Exam-day contacts">
            <p className="font-medium">Exam-day contacts</p>
            {contacts.length === 0 ? (
                <p className="text-muted-foreground">No contacts set. An administrator can add them under Policy.</p>
            ) : (
                <ul className="grid gap-1.5">
                    {contacts.map((c, i) => (
                        <li key={i} className="flex flex-wrap items-baseline justify-between gap-x-3">
                            <span><b className="font-medium">{c.role}</b> — {c.name}{c.hours ? <span className="text-xs text-muted-foreground"> ({c.hours})</span> : null}</span>
                            {c.phone && <a className="inline-flex items-center gap-1 text-brand" href={`tel:${c.phone.replace(/\s/g, '')}`}><Phone className="size-3.5" />{c.phone}</a>}
                        </li>
                    ))}
                </ul>
            )}
            <Link href="/help/exam-incidents-procedure" className="text-xs text-brand underline-offset-4 hover:underline">Procedure for flags, false positives, appeals and accessibility exceptions</Link>
        </section>
    );
}
