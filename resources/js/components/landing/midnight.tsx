import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Building2, Cloud, FileWarning, PlugZap, ScanFace, ShieldCheck, ZapOff } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Apply, Faq, Pricing } from '@/components/landing/sections';
import type { Plan } from '@/components/landing/sections';

/** The "midnight" design: dark, bold type, one orange accent, centred and calm. */
export default function MidnightLanding({ plans, demo = false }: { plans: Plan[]; demo?: boolean }) {
    const { auth, platform } = usePage().props;
    const home = auth.user ? (auth.isPlatformAdmin ? '/platform' : '/dashboard') : '/login';

    return (
        <div className="min-h-screen overflow-x-clip">
            <header className="sticky top-0 z-20 border-b border-[var(--line)] bg-[var(--paper)]/90 backdrop-blur">
                <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                    <a href="#top" className="flex min-w-0 items-center gap-2.5">
                        <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-[var(--line)] text-[var(--green)]">
                            {platform?.logo_url ? <img src={platform.logo_url} alt="" className="size-full object-cover" /> : <AppLogoIcon className="size-5 fill-current" />}
                        </span>
                        <span className="truncate text-[17px] font-bold">{platform?.short_name}</span>
                    </a>
                    <nav className="hidden items-center gap-7 text-sm text-[var(--ink-2)] md:flex" aria-label="Sections">
                        <a href="#why" className="hover:text-white">Why us</a>
                        <a href="#run" className="hover:text-white">Where it runs</a>
                        <a href="#pricing" className="hover:text-white">Pricing</a>
                        <a href="#questions" className="hover:text-white">Questions</a>
                        {demo && <a href="/demo" className="text-[var(--green)] hover:text-white">Try the demo</a>}
                    </nav>
                    <div className="flex shrink-0 items-center gap-2">
                        <Link href={home} className="btn btn-quiet !py-2 text-sm">{auth.user ? `Open ${platform?.short_name}` : 'Sign in'}</Link>
                        <a href="#apply" className="btn btn-primary hidden !py-2 text-sm sm:inline-flex">Apply</a>
                    </div>
                </div>
            </header>

            <section id="top" className="glow">
                <div className="mx-auto max-w-4xl px-4 pt-24 pb-20 text-center sm:px-6 md:pt-32">
                    <p className="kicker">{platform?.name}</p>
                    <h1 className="display mt-6 text-[clamp(2.4rem,6vw,4.4rem)] leading-[1.05]">Computer-based exams,<br /><span className="text-[var(--green)]">run like the real thing.</span></h1>
                    <p className="mx-auto mt-6 max-w-2xl text-lg text-[var(--ink-2)]">Question banks, CBT labs, proctoring and results in one system, built with exams offices. From the first question uploaded to the last result approved.</p>
                    <div className="mt-9 flex flex-wrap justify-center gap-3">
                        <a href="#apply" className="btn btn-primary">Apply for your institution</a>
                        <a href={demo ? '/demo' : '#why'} className="btn btn-quiet">{demo ? 'Try the demo' : 'See what it does'}</a>
                    </div>
                    <p className="mt-6 text-sm text-[var(--ink-3)]">Used for GST papers, nursing CAOSCE and professional council mock exams.</p>
                </div>
                <div className="mx-auto max-w-5xl px-4 pb-24 sm:px-6">
                    <figure className="shot">
                        <img src="/images/landing/shot-exam-room.webp" alt="A candidate's exam screen with a multiple true/false question." width={1600} height={768} />
                    </figure>
                </div>
            </section>

            <section className="border-t border-[var(--line)]">
                <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                    <p className="kicker text-center">The problem</p>
                    <h2 className="h2 mx-auto mt-4 max-w-3xl text-center">Exam day is where general-purpose systems <span className="text-[var(--green)]">fall short.</span></h2>
                    <div className="mt-14 grid gap-5 md:grid-cols-3">
                        {[
                            { icon: PlugZap, title: 'Plugins for everything', body: 'Multiple true/false, one session per student and proctoring each need a different add-on, and each one can break at the next upgrade.' },
                            { icon: ZapOff, title: 'Power and network cuts', body: 'A dropped link or a dead generator should not cost a student their answers or their time.' },
                            { icon: FileWarning, title: 'Faulty questions', body: 'A wrong key found after the exam means re-marking by hand, one script at a time.' },
                        ].map((f) => (
                            <div key={f.title} className="panel p-6">
                                <f.icon className="size-5 text-[var(--ink-2)]" />
                                <h3 className="mt-4 text-lg font-semibold">{f.title}</h3>
                                <p className="mt-2 text-[15px] text-[var(--ink-2)]">{f.body}</p>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section id="why" className="scroll-mt-20 border-t border-[var(--line)]">
                <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                    <p className="kicker text-center">Why this platform</p>
                    <h2 className="h2 mx-auto mt-4 max-w-3xl text-center">Built for exams that <span className="text-[var(--green)]">have to go right.</span></h2>
                    <p className="mx-auto mt-4 max-w-2xl text-center text-[var(--ink-2)]">Three things the exams office notices on the first day.</p>
                    <div className="mt-14 grid gap-5 md:grid-cols-3">
                        {[
                            { icon: ShieldCheck, title: 'Nothing is lost', body: 'Answers are kept on each computer and sent when the link returns; minutes lost to a power cut are given back.' },
                            { icon: ScanFace, title: 'Monitoring that is fair', body: 'Cameras only where you want them, supervisors watching in batches, and AI alerts that a person always reviews.' },
                            { icon: ArrowRight, title: 'Results the same day', body: 'Objective questions mark themselves, faulty questions are fixed for everyone at once, approvals are recorded.' },
                        ].map((f) => (
                            <div key={f.title} className="panel p-6">
                                <span className="flex size-10 items-center justify-center rounded-xl bg-[var(--mint)] text-[var(--green)]"><f.icon className="size-5" /></span>
                                <h3 className="mt-5 text-lg font-semibold">{f.title}</h3>
                                <p className="mt-2 text-[15px] text-[var(--ink-2)]">{f.body}</p>
                            </div>
                        ))}
                    </div>
                    <figure className="shot mt-16">
                        <img src="/images/landing/shot-console.webp" alt="The exam-day console with candidates in batches, each batch given to a supervisor." width={1600} height={729} loading="lazy" />
                    </figure>
                </div>
            </section>

            <section id="run" className="scroll-mt-20 border-t border-[var(--line)]">
                <div className="mx-auto max-w-6xl px-4 py-24 sm:px-6">
                    <p className="kicker text-center">Where it runs</p>
                    <h2 className="h2 mx-auto mt-4 max-w-3xl text-center">In your CBT lab, in the cloud, <span className="text-[var(--green)]">or both.</span></h2>
                    <div className="mt-14 grid gap-5 md:grid-cols-2">
                        {[
                            { icon: Building2, tag: 'On campus', title: 'Your own Ubuntu server', body: 'Lab computers reach it by its address over cable or Wi-Fi. No internet needed during the exam, and every computer is tested before it starts.', meta: 'LAN · Wi-Fi · offline-ready' },
                            { icon: Cloud, tag: 'Online', title: 'Hosted for you', body: 'Students write from home or from study centres, with proctoring switched on only for the exams that need it.', meta: 'Remote · proctored · recorded' },
                        ].map((f) => (
                            <article key={f.title} className="panel flex flex-col p-7">
                                <div className="flex items-center justify-between text-sm"><span className="kicker !tracking-[0.18em]">{f.tag}</span><f.icon className="size-5 text-[var(--ink-3)]" /></div>
                                <h3 className="mt-5 text-2xl font-semibold">{f.title}</h3>
                                <p className="mt-3 text-[var(--ink-2)]">{f.body}</p>
                                <p className="mt-6 font-mono text-xs text-[var(--ink-3)]">{f.meta}</p>
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            <section className="border-t border-[var(--line)]">
                <div className="mx-auto max-w-4xl px-4 py-24 text-center sm:px-6">
                    <p className="text-[clamp(1.6rem,3.4vw,2.4rem)] leading-snug font-semibold tracking-tight">&ldquo;Every answer kept, even when the power goes. Every exam with its own rules. Every result with a trail.&rdquo;</p>
                    <p className="mt-6 text-sm text-[var(--ink-3)]">What the platform is built to promise.</p>
                </div>
            </section>

            <Pricing plans={plans} />
            <Faq />
            <Apply plans={plans} />

            <footer className="border-t border-[var(--line)]">
                <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-[var(--ink-3)] sm:flex-row sm:items-center sm:justify-between sm:px-6">
                    <span>{platform?.name}</span>
                    <span className="flex gap-5"><a href="#pricing" className="hover:text-white">Pricing</a><a href="#apply" className="hover:text-white">Apply</a><Link href={home} className="hover:text-white">Sign in</Link></span>
                </div>
            </footer>
        </div>
    );
}
