import { Link, useForm, usePage } from '@inertiajs/react';
import { Check, ChevronDown } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

/*
 * The parts every landing design shares (pricing, questions, the application form). They are styled with
 * the design's colour variables, so each design only sets --paper, --card, --ink, --line, --green and so on.
 */

export type Plan = {
    id: number; key: string; name: string; tagline: string | null; description: string | null; monthly: string | null; yearly: string | null;
    student_limit: number | null; features: string[]; highlighted: boolean;
};

export const faqs = [
    { q: 'Does every exam need cameras and system checks?', a: 'No. Each exam chooses its own rules. A paper supervised in the hall can run with no camera, no recording and no checks before starting: students sign in and write. Cameras, screen sharing, live watching and pre-exam checks are switched on only for the exams that need them.' },
    { q: 'Can it run on our own server in the CBT lab?', a: 'Yes. Install it on an Ubuntu server on your campus network and students reach it by its IP address over cable or Wi-Fi, with no internet needed during the exam. You can also run it in the cloud, or both, with sittings packaged for labs that are offline.' },
    { q: 'What happens if the power or the network goes during an exam?', a: 'Every answer is kept on the computer as it is typed and sent when the connection returns. If the computer goes off, the candidate continues where they stopped and the lost minutes are given back.' },
    { q: 'Our questions are in Word, with equations and multiple true/false. Will they come in?', a: 'Yes. Upload Word, PDF, text or CSV files in the layout your lecturers already use. Equations, pictures and multiple true/false statements come through, and you check every question before it is saved.' },
    { q: 'Do we still need Moodle?', a: 'Not for exams. Course pages, assignments, quizzes and the gradebook are also built in, but you can keep Moodle for teaching if you prefer.' },
    { q: 'How do we pay?', a: 'By card, bank transfer or USSD through Paystack, or by bank transfer against an invoice. Invoices are issued two weeks before each renewal.' },
];

export function Pricing({ plans }: { plans: Plan[] }) {
    const [yearly, setYearly] = useState(true);

    return (
        <section id="pricing" className="scroll-mt-20 border-t border-[var(--line)] bg-[var(--card)]">
            <div className="mx-auto max-w-6xl px-4 py-20 sm:px-6">
                <div className="flex flex-wrap items-end justify-between gap-6">
                    <div className="max-w-xl">
                        <h2 className="h2">Priced by the size of your institution.</h2>
                        <p className="mt-4 text-[var(--ink-2)]">Prices are per institution and include hosting, updates, backups and support. Each plan shows its own currency; tax is added on the invoice where it applies.</p>
                    </div>
                    <div role="group" aria-label="Billing period" className="inline-flex rounded-full border border-[var(--line)] p-1 text-sm">
                        {[true, false].map((y) => (
                            <button key={String(y)} aria-pressed={yearly === y} onClick={() => setYearly(y)} className={`rounded-full px-4 py-1.5 font-medium ${yearly === y ? 'bg-[var(--green)] text-[var(--on-accent,#fff)]' : 'text-[var(--ink-2)]'}`}>
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
                            <article key={p.id} className={`flex flex-col rounded-2xl p-6 ${dark ? 'border-2 border-[var(--green)] bg-[var(--mint)]' : 'border border-[var(--line)] bg-[var(--paper)]'}`}>
                                <h3 className="display text-xl">{p.name}</h3>
                                {dark && <p className="mt-1 text-sm font-medium text-[var(--green)]">Chosen by most universities</p>}
                                <p className={`mt-2 min-h-10 text-sm text-[var(--ink-2)]`}>{p.tagline}</p>
                                <p className="mt-6">
                                    {price ? (
                                        <><span className="display text-3xl">{price.replace('.00', '')}</span><span className={`text-sm text-[var(--ink-2)]`}> per {yearly ? 'year' : 'month'}</span></>
                                    ) : <span className="display text-2xl">Priced on request</span>}
                                </p>
                                <p className={`mt-1 text-sm text-[var(--ink-2)]`}>{p.student_limit ? `Up to ${p.student_limit.toLocaleString()} students` : 'Any number of students'}</p>
                                <ul className="mt-6 grid gap-2 text-sm">
                                    {p.features.map((f) => <li key={f} className="flex gap-2"><Check className={`mt-0.5 size-4 shrink-0 text-[var(--green)]`} />{f}</li>)}
                                </ul>
                                <div className="mt-auto pt-8">
                                    <a href="#apply" onClick={() => window.dispatchEvent(new CustomEvent('choose-plan', { detail: { id: p.id, yearly } }))}
                                        className={`block rounded-full px-5 py-2.5 text-center text-sm font-medium ${dark ? 'bg-[var(--green)] text-[var(--on-accent,#fff)] hover:bg-[var(--green-deep)]' : 'border border-[var(--ink)]/20 hover:border-[var(--ink)]'}`}>
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

export function Faq() {
    return (
        <section id="questions" className="mx-auto max-w-3xl scroll-mt-20 px-4 py-20 sm:px-6">
            <h2 className="h2">Questions we are often asked.</h2>
            <div className="mt-8 divide-y divide-[var(--line)] border-y border-[var(--line)]">
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

const field = 'h-11 w-full rounded-[10px] border border-[var(--line)] bg-[var(--paper)] px-3 text-[15px] text-[var(--ink)] outline-none focus:border-[var(--green)]';

export function Apply({ plans }: { plans: Plan[] }) {
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
        <section id="apply" className="scroll-mt-20 border-t border-[var(--line)] bg-[var(--mint)]">
            <div className="mx-auto grid max-w-6xl gap-12 px-4 py-20 sm:px-6 lg:grid-cols-[1fr_1.5fr]">
                <div>
                    <h2 className="h2">Apply for your institution.</h2>
                    <p className="mt-4 text-[var(--ink-2)]">Tell us about your institution. We check the details, usually within two working days, and your administrator receives a link to start a 30-day trial with your own students and papers.</p>
                    <p className="mt-4 text-[var(--ink-2)]">Already using {platform?.short_name}? <Link href="/login" className="font-medium text-[var(--ink)] underline underline-offset-4">Sign in</Link>.</p>
                </div>
                {flash?.success ? (
                    <div role="status" className="self-start rounded-2xl border-2 border-[var(--green)] bg-[var(--card)] p-8">
                        <p className="display text-2xl text-[var(--green)]">Application received</p>
                        <p className="mt-3 text-[var(--ink-2)]">{flash.success}</p>
                    </div>
                ) : (
                    <form className="relative grid gap-4 rounded-2xl border border-[var(--line)] bg-[var(--card)] p-6 sm:grid-cols-2 sm:p-8" onSubmit={(e) => {
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
                            <button disabled={form.processing} className="btn btn-primary disabled:opacity-60">{form.processing ? 'Sending…' : 'Send application'}</button>
                            <span className="text-xs text-[var(--ink-2)]">We use these details only to review your application.</span>
                        </div>
                    </form>
                )}
            </div>
        </section>
    );
}
