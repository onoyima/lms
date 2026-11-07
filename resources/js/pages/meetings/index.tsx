import { Head, Link } from '@inertiajs/react';
import { BarChart3, Plus } from 'lucide-react';
import { Flash, formatDateTime, PageHeader, Status } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

export type MeetingCard = {
    uuid: string; title: string; type: string; type_label: string; status: string; starts_at: string; ends_at: string; timezone: string;
    course: string | null; recurring: boolean; moderator: boolean;
};
type Props = { live: MeetingCard[]; upcoming: MeetingCard[]; past: MeetingCard[]; course: string | null; courseId: number | null; canCreate: boolean };

function Row({ m }: { m: MeetingCard }) {
    return (
        <li>
            <Link href={`/meetings/${m.uuid}`} className="flex flex-wrap items-baseline gap-x-4 gap-y-1 px-4 py-3 hover:bg-muted/50">
                <span className="w-44 shrink-0 text-sm tabular-nums text-muted-foreground">{formatDateTime(m.starts_at)}</span>
                <span className="min-w-0 flex-1">
                    <span className="font-medium">{m.title}</span>
                    <span className="block text-xs text-muted-foreground">{m.type_label}{m.course ? ` · ${m.course}` : ''}{m.recurring ? ' · repeats' : ''}{m.moderator ? ' · you host' : ''}</span>
                </span>
                <Status value={m.status} />
            </Link>
        </li>
    );
}

function Section({ title, items, empty }: { title: string; items: MeetingCard[]; empty: string }) {
    return (
        <section className="flex flex-col gap-2">
            <h2 className="text-sm font-medium">{title}</h2>
            {items.length === 0 ? <p className="text-sm text-muted-foreground">{empty}</p> : (
                <ul className="divide-y divide-border rounded-lg border border-border bg-card">{items.map((m) => <Row key={m.uuid} m={m} />)}</ul>
            )}
        </section>
    );
}

/** Live classes and meetings this person can join or runs. */
export default function Meetings({ live, upcoming, past, course, courseId, canCreate }: Props) {
    return (
        <>
            <Head title="Live classes & meetings" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title={course ? `Live classes · ${course}` : 'Live classes & meetings'} lead="Classes, office hours, seminars and meetings on the institution's video service."
                    actions={canCreate && <>
                        <Button variant="outline" asChild><Link href={`/meeting-reports${courseId ? `?course=${courseId}` : ''}`}><BarChart3 className="size-4" /> Reports</Link></Button>
                        <Button asChild><Link href={`/meetings/create${courseId ? `?course=${courseId}` : ''}`}><Plus className="size-4" /> Schedule</Link></Button>
                    </>} />
                <Flash />
                {live.length > 0 && <Section title="Happening now" items={live} empty="" />}
                <Section title="Coming up" items={upcoming} empty="Nothing scheduled." />
                <Section title="Earlier" items={past} empty="No past meetings yet." />
            </div>
        </>
    );
}

Meetings.layout = { breadcrumbs: [{ title: 'Live classes & meetings', href: '/meetings' }] };
