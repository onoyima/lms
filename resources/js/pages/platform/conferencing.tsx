import { Head } from '@inertiajs/react';
import { ServerList } from '@/components/conferencing/servers';
import type { Server } from '@/components/conferencing/servers';
import { Flash, PageHeader, Stat } from '@/components/platform/ui';

type Stats = { live: number; in_meetings_now: number; meetings_30d: number; hours_30d: number; webhook_events_24h: number };

/** Shared BigBlueButton servers every institution can use (an institution may also add its own). Counts only. */
export default function PlatformConferencing({ servers, stats }: { servers: Server[]; stats: Stats }) {
    return (
        <>
            <Head title="Conferencing servers" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Conferencing servers" lead="Shared BigBlueButton servers. Meetings go to the least busy one; an institution's own servers are used first for its meetings." />
                <Flash />
                <div className="grid gap-4 sm:grid-cols-3 lg:grid-cols-5">
                    <Stat label="Meetings now" value={stats.live} />
                    <Stat label="People in meetings" value={stats.in_meetings_now} />
                    <Stat label="Meetings, 30 days" value={stats.meetings_30d} />
                    <Stat label="Participant hours, 30 days" value={stats.hours_30d} />
                    <Stat label="Server events, 24 h" value={stats.webhook_events_24h} />
                </div>
                <ServerList servers={servers} base="/platform/conferencing" />
            </div>
        </>
    );
}

PlatformConferencing.layout = { breadcrumbs: [{ title: 'Conferencing servers', href: '/platform/conferencing' }] };
