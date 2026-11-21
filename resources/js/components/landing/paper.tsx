import { Link, usePage } from '@inertiajs/react';
import { Check } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Apply, Faq, Pricing } from '@/components/landing/sections';
import type { Plan } from '@/components/landing/sections';

/** The "paper" design: light and quiet, real screenshots, and the exam slip every candidate knows. */
export default function PaperLanding({ plans, demo = false }: { plans: Plan[]; demo?: boolean }) {
    const { auth, platform } = usePage().props;
    const home = auth.user ? (auth.isPlatformAdmin ? '/platform' : '/dashboard') : '/login';

    return (
        <>
            <div className="min-h-screen overflow-x-clip">
                <header className="sticky top-0 z-20 border-b border-[var(--line)] bg-[var(--paper)]/95 backdrop-blur">
                    <div className="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3 sm:px-6">
                        <a href="#top" className="flex min-w-0 items-center gap-2.5">
                            <span className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-[var(--green)] text-white">
                                {platform?.logo_url ? <img src={platform.logo_url} alt="" className="size-full object-cover" /> : <AppLogoIcon className="size-5 fill-current" />}
                            </span>
                            <span className="display truncate text-lg">{platform?.short_name}</span>
                        </a>
                        <nav className="hidden items-center gap-7 text-[15px] text-[var(--ink-2)] md:flex" aria-label="Sections">
                            <a href="#how" className="hover:text-[var(--ink)]">How it works</a>
                            <a href="#lab" className="hover:text-[var(--ink)]">CBT labs</a>
                            <a href="#pricing" className="hover:text-[var(--ink)]">Pricing</a>
                            <a href="#questions" className="hover:text-[var(--ink)]">Questions</a>
                            {demo && <a href="/demo" className="font-medium text-[var(--green)] hover:text-[var(--ink)]">Try the demo</a>}
                        </nav>
                        <div className="flex shrink-0 items-center gap-2">
                            <Link href={home} className="rounded-full px-4 py-2 text-[15px] font-medium hover:bg-[var(--mint)]">{auth.user ? `Open ${platform?.short_name}` : 'Sign in'}</Link>
                            <a href="#apply" className="btn btn-primary hidden !py-2 sm:inline-flex">Apply</a>
                        </div>
                    </div>
                </header>

                <main id="top">
                    <Hero demo={demo} />
                    <Rules />
                    <Lab />
                    <How />
                    <WhenThingsGoWrong />
                    <Pricing plans={plans} />
                    <Faq />
                    <Apply plans={plans} />
                </main>

                <footer className="border-t border-[var(--line)]">
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

/* ---------- hero: the candidate's screen, and the exam slip on the desk beside it ---------- */

function Hero({ demo }: { demo: boolean }) {
    return (
        <section className="mx-auto grid max-w-6xl items-center gap-14 px-4 pt-14 pb-24 sm:px-6 md:pt-20 lg:grid-cols-[0.9fr_1.25fr]">
            <div className="rise">
                <p className="text-[15px] font-medium text-[var(--green)]">For universities, polytechnics and colleges</p>
                <h1 className="display mt-4">Exam day, without the panic.</h1>
                <p className="lead mt-6">
                    Set the paper, add the students, and let them write: in your CBT lab, in a hall, or from home. Answers are kept even when the power goes, results come out question by question, and every rule is yours to choose.
                </p>
                <div className="mt-8 flex flex-wrap gap-3">
                    <a href="#apply" className="btn btn-primary">Apply for your institution</a>
                    <a href={demo ? '/demo' : '#how'} className="btn btn-quiet">{demo ? 'Try the demo, no sign-up' : 'See an exam from start to finish'}</a>
                </div>
                <p className="mt-6 text-sm text-[var(--ink-3)]">Already used for GST papers, nursing CAOSCE and professional council mock exams.</p>
            </div>

            <div className="rise rise-2 relative pb-10 lg:-mr-16 lg:pb-0">
                <figure className="shot">
                    <img src="/images/landing/shot-exam-room.webp" alt="A candidate's exam screen with a multiple true/false question, four statements each marked True or False, and the time left at the top." width={1600} height={768} />
                </figure>
                <ExamSlip />
            </div>
        </section>
    );
}

function ExamSlip() {
    return (
        <aside aria-label="An example exam slip" className="slip absolute -bottom-6 right-3 w-56 rotate-[3deg] sm:right-6 lg:-bottom-24 lg:right-10">
            <div className="flex items-start justify-between gap-2 px-4 pt-3">
                <div>
                    <p className="font-semibold">Exam slip</p>
                    <p className="text-[0.72rem] text-[var(--ink-3)]">NSC 401 · Medical-Surgical Nursing</p>
                </div>
                <span className="stamp">CHECKED IN</span>
            </div>
            <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 px-4 py-3">
                <div><dt>Name</dt><dd>Ngozi Suleiman</dd></div>
                <div><dt>Matric no.</dt><dd className="font-mono text-[0.78rem]">NSC/22/0005</dd></div>
                <div><dt>Hall</dt><dd>CBT Lab 2</dd></div>
                <div><dt>Seat</dt><dd>B-14</dd></div>
                <div><dt>Time</dt><dd>09:00, 45 min</dd></div>
                <div><dt>PIN</dt><dd className="font-mono tracking-widest">4 8 1 7</dd></div>
            </dl>
            <div className="perforation" aria-hidden />
            <div className="barcode mx-4 my-3" aria-hidden />
        </aside>
    );
}

/* ---------- every exam sets its own rules ---------- */

const modes = [
    {
        title: 'Supervised in the hall',
        body: 'Invigilators are in the room. Students sign in and start; nothing is recorded and no camera is asked for.',
        on: ['One computer per student', 'Exam PIN from the invigilator'],
        off: ['Camera and recording', 'Checks before starting'],
    },
    {
        title: 'Written from home',
        body: 'Supervisors watch online, a batch of 20, 50 or 100 students at a time, while everyone is recorded.',
        on: ['Camera and screen', 'Live watching in batches', 'Recording for review'],
        off: [],
    },
    {
        title: 'High stakes, locked down',
        body: 'Only approved lab computers, only the secure exam app or Safe Exam Browser, and a seat for each candidate.',
        on: ['Approved computers only', 'Safe Exam Browser', 'Assigned seats'],
        off: ['Camera, unless you want it'],
    },
];

function Rules() {
    return (
        <section className="border-y border-[var(--line)] bg-[var(--card)]">
            <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <div className="max-w-2xl">
                    <h2 className="h2">Every exam sets its own rules.</h2>
                    <p className="lead mt-4">Not every paper needs a camera. A test in a supervised hall can be as simple as signing in and writing. Turn on monitoring only where it earns its place.</p>
                </div>
                <div className="mt-12 grid gap-6 md:grid-cols-3">
                    {modes.map((m) => (
                        <article key={m.title} className="rounded-2xl border border-[var(--line)] bg-[var(--paper)] p-6">
                            <h3 className="display text-xl">{m.title}</h3>
                            <p className="mt-2 text-[15px] text-[var(--ink-2)]">{m.body}</p>
                            <ul className="mt-5 grid gap-2.5 text-[15px]">
                                {m.on.map((x) => <li key={x} className="flex items-center justify-between gap-3">{x}<span className="switch on" aria-label="on" /></li>)}
                                {m.off.map((x) => <li key={x} className="flex items-center justify-between gap-3 text-[var(--ink-3)]">{x}<span className="switch" aria-label="off" /></li>)}
                            </ul>
                        </article>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ---------- runs in your own CBT lab ---------- */

function Lab() {
    const seats = Array.from({ length: 40 }, (_, i) => i);

    return (
        <section id="lab" className="scroll-mt-20">
            <div className="mx-auto grid max-w-6xl items-center gap-14 px-4 py-20 sm:px-6 lg:grid-cols-2">
                <div>
                    <h2 className="h2">Runs in your CBT lab, even when the internet doesn&apos;t.</h2>
                    <p className="lead mt-4">Install it on an Ubuntu server in your own building. Lab computers reach it by its address over cable or Wi-Fi, so a slow or broken internet link never stops a sitting.</p>
                    <ul className="mt-6 grid gap-3 text-[16px]">
                        {[
                            'Students type the server address, or scan the code shown at the front of the lab',
                            'Approve the lab\'s computers once; others are turned away',
                            'Test every computer before the exam, and see which ones failed and why',
                            'Prefer the cloud? Use both: package a sitting for an offline lab and bring the scripts back',
                        ].map((x) => <li key={x} className="flex gap-3"><Check className="mt-1 size-5 shrink-0 text-[var(--green)]" />{x}</li>)}
                    </ul>
                </div>
                <figure aria-label="A CBT lab plan: forty computers connected to a server in the same building" className="rounded-2xl border border-[var(--line)] bg-[var(--card)] p-6">
                    <div className="flex items-center justify-between text-sm">
                        <span className="font-semibold">CBT Lab 2</span>
                        <span className="font-mono text-[var(--ink-2)]">http://10.10.2.5</span>
                    </div>
                    <div className="mt-5 grid grid-cols-8 gap-2">
                        {seats.map((i) => <span key={i} className={`seat ${i % 11 === 3 ? '' : 'writing'}`} />)}
                    </div>
                    <div className="mt-6 flex items-center gap-3 text-sm">
                        <span className="rounded-md border border-[var(--line)] bg-[var(--paper)] px-3 py-2 font-medium">Exam server</span>
                        <span className="h-px flex-1 bg-[var(--line)]" />
                        <span className="text-[var(--ink-2)]">36 writing · 4 free seats · no internet needed</span>
                    </div>
                </figure>
            </div>
        </section>
    );
}

/* ---------- an exam from start to finish ---------- */

const how = [
    {
        n: 'Before',
        title: 'Bring your questions as they are',
        body: 'Upload the Word, PDF or text files your lecturers already write, with equations, pictures and multiple true/false. Put questions in topic banks, and give each student their own paper with at least a few questions from every topic.',
        img: 'shot-upload', alt: 'Uploading questions from a Word, text, CSV or PDF file, with the expected layout shown beside the form.', w: 1600, h: 1013,
    },
    {
        n: 'During',
        title: 'Watch the room, one batch at a time',
        body: 'The exams office sees who is writing, who has submitted and who lost contact. Supervisors watch their own batches, message a student, add time or pause the whole sitting if something goes wrong.',
        img: 'shot-console', alt: 'The exam-day console showing batches of candidates, each given to a supervisor.', w: 1600, h: 729,
    },
    {
        n: 'After',
        title: 'See how each question did',
        body: 'Objective questions mark themselves. For every question you see how many got it right and which option they chose, and a question students reported as faulty can be corrected for everyone at once.',
        img: 'shot-results', alt: 'Results question by question, with the share of students who judged each true/false statement correctly.', w: 1600, h: 1047,
    },
];

function How() {
    return (
        <section id="how" className="scroll-mt-20 border-t border-[var(--line)] bg-[var(--card)]">
            <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <h2 className="h2 max-w-2xl">An exam from start to finish.</h2>
                <div className="mt-14 grid gap-20">
                    {how.map((s, i) => (
                        <div key={s.n} className="grid items-center gap-10 lg:grid-cols-[0.8fr_1.2fr]">
                            <div className={i % 2 ? 'lg:order-2' : ''}>
                                <p className="text-[15px] font-semibold text-[var(--green)]">{s.n} the exam</p>
                                <h3 className="display mt-2 text-[1.75rem]">{s.title}</h3>
                                <p className="mt-4 text-[var(--ink-2)]">{s.body}</p>
                            </div>
                            <figure className="shot">
                                <img src={`/images/landing/${s.img}.webp`} alt={s.alt} width={s.w} height={s.h} loading="lazy" />
                            </figure>
                        </div>
                    ))}
                </div>
            </div>
        </section>
    );
}

/* ---------- when things go wrong ---------- */

const mishaps = [
    { what: 'The internet drops', then: 'Students keep writing. Answers wait on the computer and go up when the link is back.' },
    { what: 'The power goes off', then: 'They continue where they stopped, and the minutes the computer was off are given back.' },
    { what: 'A computer fails', then: 'Move the student to another seat; the exam opens where it was, and the old screen stops.' },
    { what: 'A question is wrong', then: 'Students report it without losing time. Fix it once and everyone is marked again.' },
];

function WhenThingsGoWrong() {
    return (
        <section className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
            <h2 className="h2 max-w-2xl">Because something always goes wrong on exam day.</h2>
            <dl className="mt-12 grid gap-x-10 gap-y-8 md:grid-cols-2">
                {mishaps.map((m) => (
                    <div key={m.what} className="rule pt-5">
                        <dt className="display text-xl">{m.what}</dt>
                        <dd className="mt-2 text-[var(--ink-2)]">{m.then}</dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}

