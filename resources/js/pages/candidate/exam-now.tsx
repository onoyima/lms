import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight, Clock, LogOut, MapPin } from 'lucide-react';
import { useEffect, useState } from 'react';
import { logout } from '@/routes';

type Open = { candidate_id: number; title: string; starts_at: string; ends_at: string; duration: number; venue: string | null; under_way: boolean };

/**
 * Emergency exams: after signing in, the student sees only the exam open now and starts it with one
 * press. No checks or notices come first; the invigilator keeps time in the room and the exam's own
 * timer still runs on screen.
 */
export default function ExamNow({ exams }: { exams: Open[] }) {
    const { tenant, auth } = usePage().props;
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const t = window.setInterval(() => setNow(Date.now()), 1000);
        // when the exam closes, the server sends the student back to their usual pages
        const r = window.setInterval(() => router.reload({ only: ['exams'] }), 30000);

        return () => (window.clearInterval(t), window.clearInterval(r));
    }, []);

    return (
        <>
            <Head title="Your exam" />
            <div className="flex min-h-screen flex-col bg-background">
                <header className="flex items-center justify-between gap-3 px-5 py-4 md:px-8">
                    <span className="flex min-w-0 items-center gap-3">
                        {tenant?.logo_url && <img src={tenant.logo_url} alt="" className="size-9 rounded-lg object-contain" />}
                        <b className="truncate">{tenant?.short_name ?? tenant?.name}</b>
                    </span>
                    <span className="flex items-center gap-3 text-sm text-muted-foreground">
                        <span className="hidden sm:inline">{auth.user.name}</span>
                        <Link href={logout()} method="post" as="button" className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1 hover:bg-muted"><LogOut className="size-4" /> Sign out</Link>
                    </span>
                </header>
                <main className="grid flex-1 place-items-center px-5 pb-16">
                    <div className="grid w-full max-w-xl gap-5">
                        <p className="text-center text-muted-foreground">{exams.length === 1 ? 'Your exam is open now' : 'Your exams open now'}</p>
                        {exams.map((e) => {
                            const left = Math.max(0, new Date(e.ends_at).getTime() - now);
                            const h = Math.floor(left / 3600000);
                            const m = Math.floor((left % 3600000) / 60000);

                            return (
                                <section key={e.candidate_id} className="surface grid gap-5 p-6 text-center md:p-8">
                                    <h1 className="text-3xl leading-tight font-semibold md:text-4xl">{e.title}</h1>
                                    <p className="flex flex-wrap items-center justify-center gap-x-5 gap-y-1 text-muted-foreground">
                                        <span className="flex items-center gap-1.5"><Clock className="size-4" /> {e.duration} minutes</span>
                                        {e.venue && <span className="flex items-center gap-1.5"><MapPin className="size-4" /> {e.venue}</span>}
                                        <span className="tabular-nums">Closes in {h > 0 ? `${h} h ` : ''}{m} min</span>
                                    </p>
                                    <Link href={`/my/exams/${e.candidate_id}`}
                                        // the exam fills the screen: entering full screen needs this press (it lasts into the exam page)
                                        onClick={() => document.fullscreenEnabled && !document.fullscreenElement && void document.documentElement.requestFullscreen?.().catch(() => undefined)}
                                        className="mx-auto inline-flex h-16 w-full max-w-sm items-center justify-center gap-2 rounded-2xl bg-brand text-xl font-semibold text-brand-foreground shadow-[0_16px_36px_-16px_var(--brand)] transition-transform hover:-translate-y-0.5 active:scale-[.98]">
                                        {e.under_way ? 'Continue my exam' : 'Start exam'} <ArrowRight className="size-5" />
                                    </Link>
                                    <p className="text-sm text-muted-foreground">Questions appear straight away. Your answers save as you go.</p>
                                </section>
                            );
                        })}
                    </div>
                </main>
            </div>
        </>
    );
}
