import { Head, Link, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Link2 } from 'lucide-react';
import { Flash, PageHeader } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Event = { kind: string; title: string; starts_at: string; ends_at: string | null; where: string | null; url: string | null; course: string | null };

const tone: Record<string, string> = {
    class: 'border-l-brand', meeting: 'border-l-brand', due: 'border-l-status-warning', exam: 'border-l-status-critical', shift: 'border-l-sky-500', registration: 'border-l-violet-500',
};
const time = (iso: string) => new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

export default function Calendar({ week, events, feed }: { week: string; events: Event[]; feed: string | null }) {
    const start = new Date(`${week}T00:00:00`);
    const days = Array.from({ length: 7 }, (_, i) => {
        const d = new Date(start);
        d.setDate(start.getDate() + i);

        return d;
    });
    const go = (n: number) => {
        const d = new Date(start);
        d.setDate(d.getDate() + n * 7);
        router.get('/learn/calendar', { week: d.toISOString().slice(0, 10) }, { preserveState: true });
    };

    return (
        <>
            <Head title="Calendar" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title={`Week of ${start.toLocaleDateString([], { day: 'numeric', month: 'long' })}`}
                    lead="Classes, deadlines, exams and shifts in one place."
                    actions={
                        <>
                            <Button variant="outline" size="icon" onClick={() => go(-1)} aria-label="Previous week"><ChevronLeft className="size-4" /></Button>
                            <Button variant="outline" onClick={() => router.get('/learn/calendar')}>This week</Button>
                            <Button variant="outline" size="icon" onClick={() => go(1)} aria-label="Next week"><ChevronRight className="size-4" /></Button>
                        </>
                    }
                />
                <Flash />
                <div className="grid gap-3 md:grid-cols-7">
                    {days.map((d) => {
                        const key = d.toDateString();
                        const today = key === new Date().toDateString();
                        const list = events.filter((e) => new Date(e.starts_at).toDateString() === key);

                        return (
                            <section key={key} className={`surface grid content-start gap-2 p-3 ${today ? 'ring-2 ring-brand' : ''}`}>
                                <p className="text-xs font-medium text-muted-foreground">{d.toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' })}</p>
                                {list.length === 0 && <p className="text-xs text-muted-foreground/60">—</p>}
                                {list.map((e, i) => {
                                    const body = (
                                        <>
                                            <span className="block font-mono text-[11px] text-muted-foreground">{time(e.starts_at)}{e.ends_at ? `–${time(e.ends_at)}` : ''}</span>
                                            <span className="block font-medium">{e.title}</span>
                                            {e.where && <span className="block text-[11px] text-muted-foreground">{e.where}</span>}
                                        </>
                                    );

                                    return e.url ? (
                                        <Link key={i} href={e.url} className={`rounded-md border-l-4 bg-muted/40 p-2 text-xs hover:bg-muted ${tone[e.kind] ?? ''}`}>{body}</Link>
                                    ) : (
                                        <div key={i} className={`rounded-md border-l-4 bg-muted/40 p-2 text-xs ${tone[e.kind] ?? ''}`}>{body}</div>
                                    );
                                })}
                            </section>
                        );
                    })}
                </div>
                <div className="surface flex flex-wrap items-center gap-3 p-4 text-sm">
                    <Link2 className="size-4 text-brand" />
                    {feed ? (
                        <>
                            <span>Add to your phone or Outlook/Google calendar with this private link:</span>
                            <code className="rounded bg-muted px-2 py-1 text-xs break-all">{feed}</code>
                            <Button size="sm" variant="outline" onClick={() => router.post('/learn/calendar/token')}>New link</Button>
                            <Button size="sm" variant="ghost" onClick={() => router.post('/learn/calendar/token', { remove: true })}>Turn off</Button>
                        </>
                    ) : (
                        <>
                            <span>Want this in your phone's calendar?</span>
                            <Button size="sm" onClick={() => router.post('/learn/calendar/token')}>Make a private calendar link</Button>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}

Calendar.layout = { breadcrumbs: [{ title: 'Calendar', href: '/learn/calendar' }] };
