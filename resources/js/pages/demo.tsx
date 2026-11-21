import '../../css/landing.css';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';

type Role = { key: string; title: string; body: string };

/** The public demo: pick a role and look around. Sample data only, replaced every night. */
export default function Demo({ available, roles }: { available: boolean; roles: Role[] }) {
    const { platform } = usePage().props;

    return (
        <>
            <Head title="Try the demo" />
            <div className="landing min-h-screen">
                <header className="border-b border-[var(--line)]">
                    <div className="mx-auto flex max-w-5xl items-center justify-between px-4 py-3 sm:px-6">
                        <Link href="/" className="flex items-center gap-2.5">
                            <span className="flex size-9 items-center justify-center rounded-lg bg-[var(--green)] text-white"><AppLogoIcon className="size-5 fill-current" /></span>
                            <span className="display text-lg">{platform?.short_name}</span>
                        </Link>
                        <Link href="/#apply" className="btn btn-primary !py-2 text-sm">Apply for your institution</Link>
                    </div>
                </header>
                <main className="mx-auto max-w-5xl px-4 py-14 sm:px-6">
                    <h1 className="display text-[clamp(2.2rem,5vw,3.4rem)]">Try it as anyone in the exams process.</h1>
                    <p className="lead mt-4">Pick a role and you are signed in to <b>Demo University</b>, a sample institution with courses, question banks, exams, sittings and results already in it. Nothing here is real, nothing is sent to anyone, and everything is reset every night.</p>
                    {!available ? (
                        <p className="mt-10 rounded-2xl border border-[var(--line)] bg-white p-6">The demo is not available right now. Please try again later, or apply and we will show you around.</p>
                    ) : (
                        <ul className="mt-10 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            {roles.map((r) => (
                                <li key={r.key}>
                                    <button onClick={() => router.post(`/demo/${r.key}`)} className="group flex h-full w-full flex-col rounded-2xl border border-[var(--line)] bg-white p-5 text-left transition hover:border-[var(--green)]">
                                        <span className="flex items-center justify-between font-semibold">{r.title}<ArrowRight className="size-4 text-[var(--ink-3)] transition group-hover:translate-x-0.5 group-hover:text-[var(--green)]" /></span>
                                        <span className="mt-1.5 text-sm text-[var(--ink-2)]">{r.body}</span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                    <p className="mt-8 text-sm text-[var(--ink-3)]">A few settings are switched off in the demo so the next visitor finds it working: passwords, e-mail addresses, two-factor sign-in, single sign-on, integrations, billing and branding.</p>
                </main>
            </div>
        </>
    );
}
