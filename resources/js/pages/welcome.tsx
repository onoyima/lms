import '../../css/landing.css';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Check, ChevronDown, Laptop, MonitorSmartphone, Network, ShieldCheck } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';

type Plan = {
    id: number; key: string; name: string; tagline: string | null; description: string | null; monthly: string | null; yearly: string | null;
    student_limit: number | null; features: string[]; highlighted: boolean;
};

/* ---------- content ---------- */

const formats = [
    { name: 'Objective', body: 'Single and multiple answer, true or false, matching, fill in the gap.' },
    { name: 'Essay', body: 'Long and short answers marked against criteria, double marking if you want it.' },
    { name: 'Practical', body: 'A checklist the examiner scores at the bench, station by station.' },
    { name: 'Viva', body: 'Panel scoring with the recording kept beside the marks.' },
    { name: 'Mixed', body: 'Any of the above in sections of one paper.' },
];

const tour = [
    { role: 'Lecturers', img: 'question-bank', title: 'Write or import questions once, reuse them for years', points: ['Paste from Word with equations, chemical formulas and diagrams intact', 'Every edit is a new version; papers already sat keep what candidates saw', 'Moderation by the head of department before anything goes live'] },
    { role: 'Exams office', img: 'exams', title: 'Plan every paper, hall and sitting from one list', points: ['CBT, essay, practical and viva follow the same approval path', 'Halls, seats, PINs and approved computers for on-campus CBT', 'Clash checks across the whole timetable before it is published'] },
    { role: 'Candidates', img: 'exam-room', title: 'A calm exam screen that keeps every answer', points: ['Answers are saved as they are typed, even if the connection drops', 'The clock is kept on the server, so a refresh never costs time', 'Larger text, high contrast and extra time for students who need them'] },
    { role: 'Proctors', img: 'proctoring', title: 'Watch the sittings you are assigned to, and nothing else', points: ['Browser checks, webcam, phone camera and screen, switched on per exam', 'Flags arrive as incidents with the evidence attached', 'Every decision is written to a log that cannot be edited later'] },
    { role: 'Students', img: 'student', title: 'Timetable, exam slips and results in one place', points: ['Course registration with the clash and credit-limit checks built in', 'Download exam slips and statements of result as signed PDFs', 'Results appear only after the senate-approved release'] },
    { role: 'Courses', img: 'course', title: 'The learning platform is part of the same system', points: ['Weekly course pages, assignments, quizzes, forums and live classes', 'Gradebook and attendance feed straight into continuous assessment', 'No separate Moodle to install, patch or keep in step'] },
];

const steps = [
    { title: 'Apply', body: 'Tell us about your institution with the form below. It takes two minutes.' },
    { title: 'We verify', body: 'We call the number you give and confirm the email domain, usually within two working days.' },
    { title: 'Trial for 30 days', body: 'Your administrator gets a private address, imports staff and students, and runs a mock exam.' },
    { title: 'Go live', body: 'Pay by card, bank transfer or USSD. Your trial data carries straight over.' },
];

const security = [
    'Each institution\'s records are kept apart; the platform team manages accounts and billing but cannot open your students, papers or results.',
    'Exam papers stay sealed until the sitting opens, and the paper a candidate sees is fixed when they start.',
    'Two-factor sign-in for staff, required for the roles you choose; single sign-on with your own SAML or OpenID provider.',
    'A tamper-evident audit trail of every change to questions, marks and results, with who, when and from where.',
    'Results move through marking, moderation and approval before anyone can publish them.',
    'Camera and identity evidence is kept only as long as your policy says, then deleted automatically.',
];

const channels = [
    { icon: MonitorSmartphone, name: 'Any browser', body: 'Coursework, practice tests and lower-stakes exams on laptops and phones.' },
    { icon: Laptop, name: 'Desktop exam app', body: 'Locks the computer for proctored and high-stakes papers on Windows, macOS and Linux.' },
    { icon: ShieldCheck, name: 'Safe Exam Browser', body: 'For faculties that already require it; each exam carries its own signed settings.' },
    { icon: Network, name: 'Campus CBT halls', body: 'Runs on your local network so a slow internet link never stops a sitting.' },
];

const faqs = [
    { q: 'Do we still need Moodle or another LMS?', a: 'No. Course pages, assignments, quizzes, forums, gradebook and attendance are built in, and they share students, courses and results with the exams side.' },
    { q: 'What happens if the power or internet goes during an exam?', a: 'Answers are saved on the server as candidates type. When the computer comes back, the candidate continues from the same question with the time they had left, and the proctor sees the gap in the log.' },
    { q: 'Can the platform team see our students\' data?', a: 'No. Platform staff manage your subscription, invoices and which modules you have. They have no access to people, papers, marks or results inside your institution.' },
    { q: 'Our questions are in Word with lots of equations. Will they import?', a: 'Yes. Upload the Word file and the platform keeps equations, chemical formulas, tables and images. You check each question before it is saved.' },
    { q: 'How do we pay?', a: 'By card, bank transfer or USSD through Paystack, or by direct bank transfer against the invoice. Invoices are issued two weeks before each renewal.' },
    { q: 'What if we outgrow our plan?', a: 'Nothing stops working. We contact you before the next invoice to move you to the right plan, and you can add single modules such as live proctoring without changing plan.' },
];

/* ---------- page ---------- */

export default function Welcome({ plans = [] }: { plans: Plan[] }) {
    const { auth, platform } = usePage().props;
    const home = auth.user ? (auth.isPlatformAdmin ? '/platform' : '/dashboard') : '/login';

    return (
        <>
            <Head title="Exams and learning for institutions" />
            <div className="landing min-h-screen">
                <header className="sticky top-0 z-20 border-b border-[var(--paper-2)] bg-[var(--paper)]/90 backdrop-blur">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                        <a href="#top" className="flex items-center gap-2.5">
                            <span className="flex size-9 items-center justify-center overflow-hidden rounded-[10px] bg-[var(--green)] text-white">
                                {platform?.logo_url ? <img src={platform.logo_url} alt="" className="size-full object-cover" /> : <AppLogoIcon className="size-5 fill-current" />}
                            </span>
                            <span className="display text-lg">{platform?.short_name}</span>
                        </a>
                        <nav className="hidden items-center gap-7 text-sm text-[var(--ink-2)] md:flex" aria-label="Sections">
                            <a href="#product" className="hover:text-[var(--ink)]">Product</a>
                            <a href="#security" className="hover:text-[var(--ink)]">Security</a>
                            <a href="#pricing" className="hover:text-[var(--ink)]">Pricing</a>
                            <a href="#questions" className="hover:text-[var(--ink)]">Questions</a>
                        </nav>
                        <div className="flex items-center gap-2">
                            <Link href={home} className="rounded-full px-4 py-2 text-sm font-medium hover:bg-[var(--paper-2)]">{auth.user ? `Open ${platform?.short_name}` : 'Sign in'}</Link>
                            <a href="#apply" className="hidden rounded-full bg-[var(--ink)] px-4 py-2 text-sm font-medium text-white hover:bg-black sm:inline-block">Apply</a>
                        </div>
                    </div>
                </header>

                <main id="top">
                    <Hero />
                    <Formats />
                    <Tour />
                    <Steps />
                    <Security />
                    <Pricing plans={plans} />
                    <Faq />
                    <Apply plans={plans} />
                </main>

                <footer className="border-t border-[var(--paper-2)]">
                    <div className="mx-auto flex max-w-6xl flex-col gap-2 px-4 py-8 text-sm text-[var(--ink-2)] sm:flex-row sm:items-center sm:justify-between sm:px-6">
                        <span>{platform?.name}</span>
                        <span className="flex gap-5">
                            <a href="#pricing" className="hover:text-[var(--ink)]">Pricing</a>
                            <a href="#apply" className="hover:text-[var(--ink)]">Apply</a>
                            <Link href={home} className="hover:text-[var(--ink)]">Sign in</Link>
                        </span>
                    </div>
                </footer>
            </div>
        </>
    );
}

/* ---------- hero: a printed answer sheet being filled in ---------- */

const answers = [1, 3, 0, 2, 2, 0, 3, 1, 0, 2];
const letters = ['A', 'B', 'C', 'D'];

function Hero() {
    const { platform } = usePage().props;

    return (
        <section className="mx-auto grid max-w-6xl items-center gap-12 overflow-x-clip px-4 pt-14 pb-16 sm:px-6 md:pt-20 lg:grid-cols-[1.15fr_1fr] lg:gap-16">
            <div>
                <h1 className="display text-[clamp(2.4rem,5.2vw,3.9rem)]">Exams and courses for your whole institution, in one place.</h1>
                <p className="mt-6 max-w-[34rem] text-lg leading-relaxed text-[var(--ink-2)]">
                    {platform?.short_name} runs question banks, CBT halls, proctored online exams, marking, results and course pages for universities, polytechnics
                    and colleges. Equations from Word stay intact, and every answer is saved the moment it is typed.
                </p>
                <div className="mt-8 flex flex-wrap gap-3">
                    <a href="#apply" className="rounded-full bg-[var(--green)] px-6 py-3 font-medium text-white shadow-[0_10px_30px_-10px_var(--green)] hover:bg-[var(--green-deep)]">Apply for your institution</a>
                    <a href="#pricing" className="rounded-full border border-[var(--ink)]/20 px-6 py-3 font-medium hover:border-[var(--ink)]">See pricing</a>
                </div>
                <p className="mt-6 text-sm text-[var(--ink-2)]">30-day trial with your own data. No card needed to start.</p>
            </div>

            <figure aria-label="An answer sheet being filled in and saved" className="relative mx-auto w-full max-w-[420px]">
                <div className="relative rotate-[1.5deg] rounded-[6px] border border-[var(--sheet)]/40 bg-white p-6 shadow-[0_30px_60px_-30px_rgba(16,32,39,0.45)]">
                    <div className="flex items-start justify-between border-b-2 border-[var(--sheet)] pb-3 text-[var(--sheet)]">
                        <div>
                            <p className="text-[11px] font-semibold">CHM 101 General Chemistry I</p>
                            <p className="text-[11px]">First semester examination</p>
                        </div>
                        <div className="grid grid-cols-6 gap-[3px]" aria-hidden>
                            {Array.from({ length: 12 }).map((_, i) => <span key={i} className={`h-3 w-1.5 ${i % 3 === 0 ? 'bg-[var(--ink)]' : 'bg-transparent'}`} />)}
                        </div>
                    </div>
                    <ol className="mt-4 grid grid-cols-2 gap-x-6 gap-y-2.5" aria-hidden>
                        {answers.map((a, row) => (
                            <li key={row} className="flex items-center gap-1.5">
                                <span className="w-5 text-right text-[11px] font-semibold text-[var(--sheet)] tabular-nums">{row + 1}</span>
                                {letters.map((l, col) => (
                                    <span key={l} className={`omr-bubble ${col === a ? 'is-marked' : ''}`} style={{ ['--d' as string]: `${500 + row * 170}ms` }}>{l}</span>
                                ))}
                            </li>
                        ))}
                    </ol>
                    <div className="mt-5 flex items-end justify-between border-t border-[var(--sheet)]/40 pt-3 text-[11px] text-[var(--sheet)]">
                        <span>Candidate VUA/21/0457</span>
                        <span>Seat B-14</span>
                    </div>
                    <div className="omr-stamp absolute -right-4 -bottom-5 rounded-md border-2 border-[var(--green)] bg-white/90 px-3 py-1.5 text-center text-[var(--green)]" style={{ ['--d' as string]: `${500 + answers.length * 170 + 250}ms` }}>
                        <p className="display text-base leading-none">Saved</p>
                        <p className="text-[10px] font-semibold">10 of 10 answers</p>
                    </div>
                </div>
            </figure>
        </section>
    );
}

function Formats() {
    return (
        <section className="border-y border-[var(--paper-2)] bg-white">
            <div className="mx-auto max-w-6xl px-4 py-10 sm:px-6">
                <p className="mb-6 max-w-xl text-[var(--ink-2)]">Every kind of assessment your senate approves, with the same timetable, approval, marking and results workflow:</p>
                <dl className="grid gap-6 sm:grid-cols-2 lg:grid-cols-5">
                    {formats.map((f) => (
                        <div key={f.name} className="border-l-2 border-[var(--green)] pl-4">
                            <dt className="display text-xl">{f.name}</dt>
                            <dd className="mt-1 text-sm leading-relaxed text-[var(--ink-2)]">{f.body}</dd>
                        </div>
                    ))}
                </dl>
            </div>
        </section>
    );
}

/* ---------- product tour with real screens ---------- */

function Tour() {
    const [i, setI] = useState(0);
    const t = tour[i];

    return (
        <section id="product" className="mx-auto max-w-6xl scroll-mt-20 px-4 py-20 sm:px-6">
            <h2 className="h2 max-w-2xl">One system for everyone who touches an exam or a course.</h2>
            <p className="mt-4 max-w-2xl text-[var(--ink-2)]">Each person sees only the menus their role allows. Choose a role to see their screen.</p>
            <div className="mt-10 grid gap-8 lg:grid-cols-[240px_1fr]">
                <div role="tablist" aria-label="Roles" className="flex gap-2 overflow-x-auto pb-1 lg:flex-col lg:overflow-visible">
                    {tour.map((x, n) => (
                        <button key={x.role} role="tab" aria-selected={n === i} aria-controls="tour-panel" onClick={() => setI(n)}
                            className={`shrink-0 rounded-xl px-4 py-3 text-left text-sm font-medium transition-colors ${n === i ? 'bg-[var(--ink)] text-white' : 'text-[var(--ink-2)] hover:bg-[var(--paper-2)]'}`}>
                            {x.role}
                        </button>
                    ))}
                </div>
                <div id="tour-panel" role="tabpanel" key={t.img} className="tour-shot">
                    <div className="overflow-hidden rounded-xl border border-[var(--ink)]/10 bg-white shadow-[0_24px_60px_-30px_rgba(16,32,39,0.35)]">
                        <div className="flex items-center gap-1.5 border-b border-[var(--paper-2)] px-4 py-2.5" aria-hidden>
                            <span className="size-2.5 rounded-full bg-[var(--paper-2)]" /><span className="size-2.5 rounded-full bg-[var(--paper-2)]" /><span className="size-2.5 rounded-full bg-[var(--paper-2)]" />
                        </div>
                        <img src={`/images/landing/${t.img}.webp`} alt={`${t.role}: ${t.title}`} width={1200} height={750} loading="lazy" className="block h-auto w-full" />
                    </div>
                    <div className="mt-6 grid gap-4 md:grid-cols-[1fr_1.3fr]">
                        <h3 className="display text-2xl">{t.title}</h3>
                        <ul className="grid gap-2 text-[var(--ink-2)]">
                            {t.points.map((p) => <li key={p} className="flex gap-2"><Check className="mt-1 size-4 shrink-0 text-[var(--green)]" />{p}</li>)}
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    );
}

function Steps() {
    return (
        <section className="bg-[var(--ink)] text-white">
            <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <h2 className="h2 max-w-xl">From application to your first exam.</h2>
                <ol className="mt-12 grid gap-10 md:grid-cols-4 md:gap-6">
                    {steps.map((s, n) => (
                        <li key={s.title} className="relative">
                            <span className="display flex size-11 items-center justify-center rounded-full border-2 border-[var(--green)] text-lg text-[var(--green)]">{n + 1}</span>
                            {n < steps.length - 1 && <span aria-hidden className="absolute top-[21px] left-14 hidden h-0.5 w-[calc(100%-3.5rem)] bg-white/15 md:block" />}
                            <h3 className="display mt-5 text-xl">{s.title}</h3>
                            <p className="mt-2 text-sm leading-relaxed text-white/70">{s.body}</p>
                        </li>
                    ))}
                </ol>
            </div>
        </section>
    );
}

function Security() {
    return (
        <section id="security" className="mx-auto grid max-w-6xl scroll-mt-20 gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1fr_1.4fr]">
            <div>
                <h2 className="h2">Built for papers that must not leak and results that must not change.</h2>
                <p className="mt-4 text-[var(--ink-2)]">Your institution's information belongs to your institution. These are the rules the platform enforces, not settings someone can forget.</p>
                <h3 className="display mt-10 text-xl">Where exams run</h3>
                <ul className="mt-4 grid gap-4">
                    {channels.map((c) => (
                        <li key={c.name} className="flex gap-3">
                            <c.icon className="mt-0.5 size-5 shrink-0 text-[var(--green)]" />
                            <span><b className="font-semibold">{c.name}.</b> <span className="text-[var(--ink-2)]">{c.body}</span></span>
                        </li>
                    ))}
                </ul>
            </div>
            <ul className="grid content-start gap-px overflow-hidden rounded-2xl border border-[var(--paper-2)] bg-[var(--paper-2)]">
                {security.map((s) => (
                    <li key={s} className="flex gap-4 bg-white p-5 leading-relaxed">
                        <ShieldCheck className="mt-0.5 size-5 shrink-0 text-[var(--green)]" />{s}
                    </li>
                ))}
            </ul>
        </section>
    );
}

/* ---------- pricing, from the plans the platform publishes ---------- */

function Pricing({ plans }: { plans: Plan[] }) {
    const [yearly, setYearly] = useState(true);

    return (
        <section id="pricing" className="scroll-mt-20 border-t border-[var(--paper-2)] bg-white">
            <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <div className="max-w-xl">
                        <h2 className="h2">Pricing by the size of your institution.</h2>
                        <p className="mt-4 text-[var(--ink-2)]">Prices are per institution and include hosting, updates, backups and support. Prices in naira; VAT is added on the invoice.</p>
                    </div>
                    <div role="group" aria-label="Billing period" className="inline-flex rounded-full border border-[var(--ink)]/15 p-1 text-sm">
                        {[true, false].map((y) => (
                            <button key={String(y)} aria-pressed={yearly === y} onClick={() => setYearly(y)} className={`rounded-full px-4 py-1.5 font-medium ${yearly === y ? 'bg-[var(--ink)] text-white' : 'text-[var(--ink-2)]'}`}>
                                {y ? 'Yearly' : 'Monthly'}
                            </button>
                        ))}
                    </div>
                </div>
                <div className="mt-12 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                    {plans.map((p) => {
                        const price = yearly ? p.yearly : p.monthly;
                        const dark = p.highlighted;

                        return (
                            <article key={p.id} className={`flex flex-col rounded-2xl p-6 ${dark ? 'bg-[var(--ink)] text-white lg:-my-3 lg:py-9' : 'border border-[var(--paper-2)] bg-[var(--paper)]'}`}>
                                <h3 className="display text-xl">{p.name}</h3>
                                {dark && <p className="mt-1 text-sm font-medium text-[var(--green)]">Chosen by most universities</p>}
                                <p className={`mt-2 min-h-10 text-sm ${dark ? 'text-white/70' : 'text-[var(--ink-2)]'}`}>{p.tagline}</p>
                                <p className="mt-6">
                                    {price ? (
                                        <><span className="display text-3xl">{price.replace('.00', '')}</span><span className={`text-sm ${dark ? 'text-white/60' : 'text-[var(--ink-2)]'}`}> per {yearly ? 'year' : 'month'}</span></>
                                    ) : <span className="display text-2xl">Priced on request</span>}
                                </p>
                                <p className={`mt-1 text-sm ${dark ? 'text-white/60' : 'text-[var(--ink-2)]'}`}>{p.student_limit ? `Up to ${p.student_limit.toLocaleString()} students` : 'Any number of students'}</p>
                                <ul className="mt-6 grid gap-2 text-sm">
                                    {p.features.map((f) => <li key={f} className="flex gap-2"><Check className="mt-0.5 size-4 shrink-0 text-[var(--green)]" />{f}</li>)}
                                </ul>
                                <div className="mt-auto pt-8">
                                    <a href="#apply" onClick={() => window.dispatchEvent(new CustomEvent('choose-plan', { detail: { id: p.id, yearly } }))}
                                        className={`block rounded-full px-5 py-2.5 text-center text-sm font-medium ${dark ? 'bg-[var(--green)] text-white hover:bg-[var(--green-deep)]' : 'border border-[var(--ink)]/20 hover:border-[var(--ink)]'}`}>
                                        {price ? `Apply for ${p.name}` : 'Talk to us'}
                                    </a>
                                </div>
                            </article>
                        );
                    })}
                </div>
            </div>
        </section>
    );
}

function Faq() {
    return (
        <section id="questions" className="mx-auto max-w-3xl scroll-mt-20 px-4 py-20 sm:px-6">
            <h2 className="h2">Questions institutions ask us.</h2>
            <div className="mt-8 divide-y divide-[var(--paper-2)] border-y border-[var(--paper-2)]">
                {faqs.map((f) => (
                    <details key={f.q} className="group py-4">
                        <summary className="flex cursor-pointer list-none items-center justify-between gap-4 font-medium">
                            {f.q}<ChevronDown className="size-5 shrink-0 transition-transform group-open:rotate-180" />
                        </summary>
                        <p className="mt-3 leading-relaxed text-[var(--ink-2)]">{f.a}</p>
                    </details>
                ))}
            </div>
        </section>
    );
}

/* ---------- application form ---------- */

const field = 'h-11 w-full rounded-lg border border-[var(--ink)]/15 bg-white px-3 text-[15px] outline-none focus:border-[var(--green)]';

function Apply({ plans }: { plans: Plan[] }) {
    const { flash, platform } = usePage().props as { flash?: { success?: string | null }; platform?: { short_name: string } };
    const recommended = useMemo(() => plans.find((p) => p.highlighted)?.id ?? plans[0]?.id ?? '', [plans]);
    const form = useForm({ institution: '', slug: '', contact_name: '', contact_email: '', contact_phone: '', country: 'Nigeria', students: '', plan_id: recommended as number | string, cycle: 'yearly', message: '', website: '' });

    // an "Apply for …" button in the pricing section preselects that plan
    const { setData } = form;
    useEffect(() => {
        const choose = (e: Event) => {
            const d = (e as CustomEvent<{ id: number; yearly: boolean }>).detail;
            setData((v) => ({ ...v, plan_id: d.id, cycle: d.yearly ? 'yearly' : 'monthly' }));
        };
        window.addEventListener('choose-plan', choose);

        return () => window.removeEventListener('choose-plan', choose);
    }, [setData]);

    const label = (text: string, error: string | undefined, child: React.ReactNode, hint?: string) => (
        <label className="grid gap-1.5 text-sm">
            <span className="font-medium">{text}</span>
            {child}
            {hint && !error && <span className="text-xs text-[var(--ink-2)]">{hint}</span>}
            {error && <span className="text-xs text-[#b42318]">{error}</span>}
        </label>
    );

    return (
        <section id="apply" className="scroll-mt-20 border-t border-[var(--paper-2)] bg-[var(--sheet-soft)]">
            <div className="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1fr_1.5fr]">
                <div>
                    <h2 className="h2">Apply for your institution.</h2>
                    <p className="mt-4 text-[var(--ink-2)]">Use an official email address for your institution. Once we have checked the details, your administrator receives a link to set a password and start the 30-day trial.</p>
                    <p className="mt-4 text-[var(--ink-2)]">Already using {platform?.short_name}? <Link href="/login" className="font-medium text-[var(--ink)] underline underline-offset-4">Sign in</Link>.</p>
                </div>
                {flash?.success ? (
                    <div role="status" className="self-start rounded-2xl border-2 border-[var(--green)] bg-white p-8">
                        <p className="display text-2xl text-[var(--green)]">Application received</p>
                        <p className="mt-3 text-[var(--ink-2)]">{flash.success}</p>
                    </div>
                ) : (
                    <form className="grid gap-4 rounded-2xl bg-white p-6 shadow-[0_20px_50px_-30px_rgba(16,32,39,0.3)] sm:grid-cols-2 sm:p-8" onSubmit={(e) => {
 e.preventDefault(); form.post('/apply', { preserveScroll: true }); 
}}>
                        <div className="sm:col-span-2">{label('Institution name', form.errors.institution, <input className={field} value={form.data.institution} onChange={(e) => form.setData('institution', e.target.value)} required autoComplete="organization" />)}</div>
                        {label('Short name', form.errors.slug, <input className={field} value={form.data.slug} onChange={(e) => form.setData('slug', e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))} required placeholder="e.g. unilag" />, 'Used in your web address on this platform')}
                        {label('Number of students', form.errors.students, <input type="number" min={1} className={field} value={form.data.students} onChange={(e) => form.setData('students', e.target.value)} />)}
                        {label('Your name', form.errors.contact_name, <input className={field} value={form.data.contact_name} onChange={(e) => form.setData('contact_name', e.target.value)} required autoComplete="name" />)}
                        {label('Official email', form.errors.contact_email, <input type="email" className={field} value={form.data.contact_email} onChange={(e) => form.setData('contact_email', e.target.value)} required autoComplete="email" />)}
                        {label('Phone', form.errors.contact_phone, <input type="tel" className={field} value={form.data.contact_phone} onChange={(e) => form.setData('contact_phone', e.target.value)} autoComplete="tel" />)}
                        {label('Country', form.errors.country, <input className={field} value={form.data.country} onChange={(e) => form.setData('country', e.target.value)} autoComplete="country-name" />)}
                        {label('Plan', form.errors.plan_id, <select className={field} value={form.data.plan_id} onChange={(e) => form.setData('plan_id', e.target.value ? Number(e.target.value) : '')}>
                            {plans.map((p) => <option key={p.id} value={p.id}>{p.name}</option>)}<option value="">Not sure yet</option>
                        </select>)}
                        {label('Billing', form.errors.cycle, <select className={field} value={form.data.cycle} onChange={(e) => form.setData('cycle', e.target.value)}><option value="yearly">Yearly</option><option value="monthly">Monthly</option></select>)}
                        <div className="sm:col-span-2">{label('Anything we should know?', form.errors.message, <textarea className={`${field} h-28 py-2`} value={form.data.message} onChange={(e) => form.setData('message', e.target.value)} placeholder="Faculties starting first, exam dates, systems you use today" />)}</div>
                        <div aria-hidden className="absolute -left-[9999px] h-0 overflow-hidden"><label>Website<input tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(e) => form.setData('website', e.target.value)} /></label></div>
                        <div className="flex flex-wrap items-center gap-4 sm:col-span-2">
                            <button disabled={form.processing} className="rounded-full bg-[var(--green)] px-6 py-3 font-medium text-white hover:bg-[var(--green-deep)] disabled:opacity-60">{form.processing ? 'Sending…' : 'Send application'}</button>
                            <span className="text-xs text-[var(--ink-2)]">We use these details only to review your application.</span>
                        </div>
                    </form>
                )}
            </div>
        </section>
    );
}
