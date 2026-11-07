import { Head, Link } from '@inertiajs/react';
import { ServerList } from '@/components/conferencing/servers';
import type { Server } from '@/components/conferencing/servers';
import { Flash, formatDateTime, PageHeader, Stat } from '@/components/platform/ui';

type Stats = { live: number; in_meetings_now: number; meetings_30d: number; hours_30d: number; webhook_events_24h: number };

/** The institution's conferencing: what is happening now, its own servers (optional) and the shared ones it can use. */
export default function Conferencing({ servers, shared, stats, liveMeetings }: { servers: Server[]; shared: number; stats: Stats; liveMeetings: { uuid: string; title: string; type: string; started_at: string }[] }) {
    return (
        <>
            <Head title="Conferencing" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Conferencing" lead={`Meetings use your own servers first, then the platform's shared servers (${shared} available).`} />
                <Flash />
                <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Stat label="Meetings now" value={stats.live} />
                    <Stat label="People in meetings" value={stats.in_meetings_now} />
                    <Stat label="Meetings, 30 days" value={stats.meetings_30d} />
                    <Stat label="Participant hours, 30 days" value={stats.hours_30d} />
                    <Stat label="Server events, 24 h" value={stats.webhook_events_24h} />
                </div>
                {liveMeetings.length > 0 && (
                    <section className="grid gap-2">
                        <h2 className="text-sm font-medium">Happening now</h2>
                        <ul className="divide-y divide-border rounded-lg border border-border bg-card">
                            {liveMeetings.map((m) => <li key={m.uuid}><Link className="flex justify-between gap-2 px-4 py-2.5 text-sm hover:bg-muted/50" href={`/meetings/${m.uuid}`}><span>{m.title}</span><span className="text-muted-foreground">since {formatDateTime(m.started_at)}</span></Link></li>)}
                        </ul>
                    </section>
                )}
                <ServerList servers={servers} base="/admin/conferencing" />
            </div>
        </>
    );
}

Conferencing.layout = { breadcrumbs: [{ title: 'Conferencing', href: '/admin/conferencing' }] };
