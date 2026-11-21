import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, BarChart3, Check, Headphones, MonitorCheck, ShieldCheck, Shuffle, Video, Wifi } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Apply, Faq, Pricing } from '@/components/landing/sections';
import type { Plan } from '@/components/landing/sections';

/** The "campus" design: photographs of students at work, institutional blue, a floating navigation bar. */
export default function CampusLanding({ plans, demo = false }: { plans: Plan[]; demo?: boolean }) {
    const { auth, platform } = usePage().props;
    const home = auth.user ? (auth.isPlatformAdmin ? '/platform' : '/dashboard') : '/login';

    return (
        <div className="min-h-screen overflow-x-clip">
            {/* hero with the floating navigation */}
            <section className="hero-photo relative pb-36 text-white lg:pb-44">
                <header className="mx-auto max-w-6xl px-4 pt-5 sm:px-6">
                    <div className="flex items-center justify-between gap-4 rounded-full bg-white px-4 py-2.5 text-[var(--ink)] shadow-[0_18px_40px_-24px_rgb(0_0_0/0.6)] sm:px-6">
                        <a href="#top" className="flex min-w-0 items-center gap-2.5">
                            <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-[var(--ink)] text-white">
                                {platform?.logo_url ? <img src={platform.logo_url} alt="" className="size-full object-cover" /> : <AppLogoIcon className="size-5 fill-current" />}
                            </span>
                            <span className="truncate text-lg font-extrabold tracking-tight">{platform?.short_name}</span>
                        </a>
                        <nav className="hidden items-center gap-7 text-[15px] text-[var(--ink-2)] md:flex" aria-label="Sections">
                            <a href="#features" className="hover:text-[var(--green)]">Features</a>
                            <a href="#lab" className="hover:text-[var(--green)]">CBT labs</a>
                            <a href="#pricing" className="hover:text-[var(--green)]">Pricing</a>
                            <a href="#questions" className="hover:text-[var(--green)]">Support</a>
                            {demo && <a href="/demo" className="font-semibold text-[var(--green)]">Live demo</a>}
                        </nav>
                        <Link href={home} className="inline-flex items-center gap-1.5 rounded-full bg-[var(--green)] px-4 py-2 text-sm font-semibold text-white hover:bg-[var(--green-deep)]">
                            {auth.user ? `Open ${platform?.short_name}` : 'Sign in'} <ArrowRight className="size-4" />
                        </Link>
                    </div>
                </header>
                <div id="top" className="mx-auto max-w-6xl px-4 pt-16 sm:px-6 md:pt-24">
                    <div className="max-w-xl">
                        <p className="text-sm font-semibold tracking-[0.18em] text-[#7fb2ff]">SMARTER · SECURE · RELIABLE</p>
                        <h1 className="display mt-4 text-[clamp(2.4rem,5.4vw,4rem)] leading-[1.04]">Next-generation examination <span className="text-[#5b9bff]">system</span></h1>
                        <p className="mt-5 text-lg text-white/85">A secure, dependable platform for computer-based exams, proctoring and results, in your CBT lab, in the hall or online.</p>
                        <div className="mt-8 flex flex-wrap gap-3">
                            <a href="#apply" className="inline-flex items-center gap-2 rounded-full bg-[var(--green)] px-6 py-3 font-semibold hover:bg-[#2f6cf0]">Apply for your institution <ArrowRight className="size-4" /></a>
                            <a href={demo ? '/demo' : '#features'} className="inline-flex items-center gap-2 rounded-full border border-white/50 px-6 py-3 font-semibold hover:bg-white/10">{demo ? 'Try the live demo' : 'See how it works'}</a>
                        </div>
                    </div>
                </div>
            </section>

            {/* the strip of four that overlaps the photograph */}
            <section className="relative z-10 mx-auto -mt-24 max-w-6xl px-4 sm:px-6">
                <div className="float-card grid gap-6 rounded-3xl bg-white p-6 sm:grid-cols-2 lg:grid-cols-4 lg:divide-x lg:divide-[var(--line)]">
                    {[
                        { img: null, icon: ShieldCheck, title: 'Locked-down security', body: 'One session per student, encrypted evidence, every change on record.' },
                        { img: 'campus-proctoring', title: 'Proctoring when you need it', body: 'Camera, screen and live watching, switched on per exam.' },
                        { img: 'campus-analytics', title: 'Results you can read', body: 'Every question\'s statistics, ready the moment students submit.' },
                        { img: 'campus-support', title: 'People who answer', body: 'A support team on call while your exams are running.' },
                    ].map((f) => (
                        <div key={f.title} className="flex items-start gap-4 lg:px-4 lg:first:pl-0">
                            {f.img ? <img src={`/images/landing/${f.img}.webp`} alt="" className="size-16 shrink-0 rounded-xl object-cover" loading="lazy" /> : <span className="flex size-16 shrink-0 items-center justify-center rounded-xl bg-[var(--green)] text-white">{f.icon && <f.icon className="size-7" />}</span>}
                            <div><p className="font-bold">{f.title}</p><p className="mt-1 text-sm text-[var(--ink-2)]">{f.body}</p></div>
                        </div>
                    ))}
                </div>
            </section>

            {/* features with the product */}
            <section id="features" className="scroll-mt-20">
                <div className="mx-auto grid max-w-6xl items-center gap-12 px-4 py-24 sm:px-6 lg:grid-cols-[0.85fr_1.15fr]">
                    <div>
                        <span className="rounded-full bg-[var(--mint)] px-3 py-1 text-sm font-semibold text-[var(--green)]">A complete examination solution</span>
                        <h2 className="h2 mt-5">Powerful features for academic excellence</h2>
                        <p className="mt-4 text-lg text-[var(--ink-2)]">From multiple true/false questions to live proctoring in batches, everything an exams office needs is in one place, and every rule is yours to switch on or off.</p>
                        <a href="#key" className="mt-7 inline-flex items-center gap-2 rounded-full bg-[var(--ink)] px-6 py-3 font-semibold text-white hover:bg-black">Explore features <ArrowRight className="size-4" /></a>
                    </div>
                    <div className="relative">
                        <div className="absolute -top-6 -right-6 size-40 rounded-full bg-[var(--mint)]" aria-hidden />
                        <figure className="shot relative">
                            <img src="/images/landing/shot-results.webp" alt="Results question by question, with how many students judged each statement correctly." width={1600} height={1047} loading="lazy" />
                        </figure>
                        <div className="absolute -bottom-6 left-6 flex items-center gap-2 rounded-2xl bg-white px-4 py-3 text-sm font-semibold shadow-[0_20px_40px_-20px_rgb(11_27_58/0.4)]">
                            <span className="size-2.5 rounded-full bg-[#16a34a]" /> Every answer saved, even offline
                        </div>
                    </div>
                </div>
            </section>

            {/* key features with pictures */}
            <section id="key" className="bg-white">
                <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                    <p className="text-center text-sm font-bold tracking-[0.2em] text-[var(--green)]">KEY FEATURES</p>
                    <h2 className="h2 mt-3 text-center">Built for modern examination needs</h2>
                    <div className="mt-12 grid gap-5 sm:grid-cols-2 lg:grid-cols-4">
                        {[
                            { img: 'campus-formats', title: 'Eleven question formats', body: 'Single and multiple answer, true/false and multiple true/false, matching, ordering, numeric and essay.' },
                            { img: 'campus-shuffle', title: 'A different paper each', body: 'Questions drawn from topic banks and shuffled, with at least a few from every topic.' },
                            { img: 'campus-lab', title: 'Proctoring in batches', body: 'Supervisors watch 20, 50 or 100 students at a time. Everyone is recorded.' },
                            { img: 'campus-marking', title: 'Marking in seconds', body: 'Objective answers mark themselves; essays and practicals are marked online.' },
                        ].map((f) => (
                            <article key={f.title} className="overflow-hidden rounded-2xl border border-[var(--line)] bg-[var(--paper)]">
                                <img src={`/images/landing/${f.img}.webp`} alt="" className="aspect-[4/3] w-full object-cover" loading="lazy" />
                                <div className="p-5"><h3 className="font-bold">{f.title}</h3><p className="mt-1.5 text-sm text-[var(--ink-2)]">{f.body}</p></div>
                            </article>
                        ))}
                    </div>
                </div>
            </section>

            {/* the CBT lab and the choice of rules */}
            <section id="lab" className="scroll-mt-20">
                <div className="mx-auto grid max-w-6xl gap-6 px-4 py-20 sm:px-6 lg:grid-cols-3">
                    {[
                        { icon: Wifi, title: 'Runs in your CBT lab', body: 'Install it on an Ubuntu server on campus. Lab computers reach it by its address over cable or Wi-Fi, with no internet needed.' },
                        { icon: MonitorCheck, title: 'Computers tested before the exam', body: 'Open one link on every lab computer; each tests itself and reports. Problems appear with the computer\'s IP address.' },
                        { icon: Video, title: 'Monitoring only where it is needed', body: 'A supervised hall needs no camera. Online exams can stream live, record and upload, or keep short clips of incidents.' },
                    ].map((f) => (
                        <div key={f.title} className="rounded-2xl border border-[var(--line)] bg-white p-6">
                            <span className="flex size-11 items-center justify-center rounded-xl bg-[var(--mint)] text-[var(--green)]"><f.icon className="size-5" /></span>
                            <h3 className="mt-4 text-lg font-bold">{f.title}</h3>
                            <p className="mt-2 text-[var(--ink-2)]">{f.body}</p>
                        </div>
                    ))}
                </div>
            </section>

            {/* trust band */}
            <section className="mx-auto max-w-6xl px-4 sm:px-6">
                <div className="grid items-center gap-8 rounded-3xl bg-[var(--mint)] p-8 md:grid-cols-[1.2fr_2fr] md:p-10">
                    <div>
                        <h2 className="text-2xl font-extrabold">Trusted for real exams</h2>
                        <p className="mt-2 text-[var(--ink-2)]">Already used for GST papers, nursing CAOSCE and professional council mock exams.</p>
                    </div>
                    <dl className="grid grid-cols-3 gap-4 text-center">
                        {[[BarChart3, '11', 'question formats'], [Shuffle, '4', 'ways to record'], [Headphones, '24/7', 'on exam days']].map(([Icon, n, l]) => {
                            const I = Icon as typeof BarChart3;

                            return (
                                <div key={l as string}><I className="mx-auto size-6 text-[var(--green)]" /><dt className="mt-2 text-3xl font-extrabold">{n as string}</dt><dd className="text-sm text-[var(--ink-2)]">{l as string}</dd></div>
                            );
                        })}
                    </dl>
                </div>
            </section>

            <Pricing plans={plans} />
            <Faq />

            {/* call to action */}
            <section className="mx-auto max-w-6xl px-4 pb-16 sm:px-6">
                <div className="flex flex-wrap items-center justify-between gap-6 rounded-3xl bg-gradient-to-r from-[#0b2559] to-[#1f5bd8] p-8 text-white md:p-10">
                    <div>
                        <h2 className="text-2xl font-extrabold">Upgrade your examination system</h2>
                        <p className="mt-1 text-white/80">A smarter, safer and more dependable way to run every exam.</p>
                    </div>
                    <a href="#apply" className="inline-flex items-center gap-2 rounded-full bg-white px-6 py-3 font-semibold text-[var(--ink)]">Apply now <ArrowRight className="size-4" /></a>
                </div>
            </section>

            <Apply plans={plans} />

            <footer className="bg-[#08183f] text-white/80">
                <div className="mx-auto grid max-w-6xl gap-8 px-4 py-12 sm:px-6 md:grid-cols-[1.5fr_1fr_1fr]">
                    <div>
                        <p className="text-xl font-extrabold text-white">{platform?.name}</p>
                        <p className="mt-2 text-sm">{platform?.tagline}</p>
                    </div>
                    <ul className="grid gap-2 text-sm">
                        <li><a href="#features" className="hover:text-white">Features</a></li>
                        <li><a href="#pricing" className="hover:text-white">Pricing</a></li>
                        <li><a href="#apply" className="hover:text-white">Apply</a></li>
                    </ul>
                    <ul className="grid gap-2 text-sm">
                        <li><a href="#questions" className="hover:text-white">Questions</a></li>
                        <li><Link href={home} className="hover:text-white">Sign in</Link></li>
                        <li className="flex items-center gap-2"><Check className="size-4 text-[#7fb2ff]" /> Hosted in your lab or in the cloud</li>
                    </ul>
                </div>
            </footer>
        </div>
    );
}
