import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowRight, Clock } from 'lucide-react';
import { useEffect } from 'react';
import { Field, formatDateTime, inputClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Props = {
    token: string;
    exam: { title: string; duration: number; instructions: string | null };
    sitting: { starts_at: string; ends_at: string };
    state: 'open' | 'not_yet' | 'closed' | 'off' | 'not_approved';
    audience: 'roster' | 'institution' | 'anyone';
    needsCode: boolean;
    ask: ('identifier' | 'email' | 'phone')[];
    signedIn: { name: string; guest: boolean } | null;
    candidateId: number | null;
    institution: string | null;
};

const labels = { identifier: 'Matric / registration number', email: 'E-mail', phone: 'Phone number' };

/** What a student sees when they open an exam link: one form, one button, then the exam room. */
export default function ExamLink({ token, exam, sitting, state, audience, needsCode, ask, signedIn, candidateId, institution }: Props) {
    const { flash } = usePage<{ flash?: { error?: string; status?: string } }>().props;
    const form = useForm({ name: '', identifier: '', email: '', phone: '', code: '' });
    const member = audience !== 'anyone';

    // not open yet: look again every 20 seconds so the button turns on by itself
    useEffect(() => {
        if (state !== 'not_yet' && state !== 'not_approved') {
            return;
        }

        const t = window.setInterval(() => router.reload({ only: ['state'] }), 20000);

        return () => window.clearInterval(t);
    }, [state]);

    const notice = {
        open: null,
        not_yet: `This exam opens at ${formatDateTime(sitting.starts_at)}. This page will let you in then.`,
        closed: 'This exam has closed.',
        off: 'This exam link has been switched off.',
        not_approved: 'This exam is waiting for approval. This page will let you in once it is ready.',
    }[state];

    return (
        <div className="flex min-h-dvh items-center justify-center bg-background p-4">
            <Head title={exam.title} />
            <div className="surface w-full max-w-md p-6 md:p-8">
                {institution && <p className="eyebrow">{institution}</p>}
                <h1 className="mt-1 text-2xl font-semibold">{exam.title}</h1>
                <p className="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"><Clock className="size-4" /> {exam.duration} minutes · open until {formatDateTime(sitting.ends_at)}</p>
                {exam.instructions && <p className="mt-3 text-sm whitespace-pre-line">{exam.instructions}</p>}

                {(flash?.error || flash?.status || notice) && <p className="mt-4 rounded-md bg-muted px-3 py-2 text-sm" role="status">{flash?.error ?? flash?.status ?? notice}</p>}

                {state === 'open' && candidateId && signedIn && !needsCode ? (
                    <Button asChild size="lg" className="mt-5 w-full"><Link href={`/my/exams/${candidateId}`}>Go to the exam <ArrowRight className="size-4" /></Link></Button>
                ) : state === 'open' || state === 'not_yet' || state === 'not_approved' ? (
                    <form className="mt-5 grid gap-3" onSubmit={(e) => (e.preventDefault(), form.post(`/x/${token}`))}>
                        {!member && (
                            <>
                                <Field label="Your full name" error={form.errors.name}>
                                    <input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required minLength={2} maxLength={120} autoComplete="name" autoFocus />
                                </Field>
                                {ask.map((k) => (
                                    <Field key={k} label={labels[k]} error={form.errors[k]}>
                                        <input className={inputClass} type={k === 'email' ? 'email' : 'text'} value={form.data[k]} onChange={(e) => form.setData(k, e.target.value)} required autoComplete={k === 'email' ? 'email' : k === 'phone' ? 'tel' : 'off'} />
                                    </Field>
                                ))}
                            </>
                        )}
                        {needsCode && (
                            <Field label="Access code" hint="Given by the person running the exam" error={form.errors.code}>
                                <input className={`${inputClass} font-mono tracking-wider`} value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} required autoComplete="off" autoFocus={member} />
                            </Field>
                        )}
                        {member && <p className="text-sm text-muted-foreground">{signedIn && !signedIn.guest ? `Signed in as ${signedIn.name}.` : 'You will sign in with your student account.'}</p>}
                        <Button size="lg" disabled={state !== 'open' || form.processing}>
                            {member && !(signedIn && !signedIn.guest) ? 'Sign in and start' : 'Continue to the exam'} <ArrowRight className="size-4" />
                        </Button>
                    </form>
                ) : null}
            </div>
        </div>
    );
}
