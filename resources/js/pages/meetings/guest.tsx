import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Video } from 'lucide-react';
import { useEffect } from 'react';
import { Field, formatDateTime, inputClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Props = {
    meeting: { title: string; starts_at: string; ends_at: string; status: string; timezone: string; description: string | null; institution: string | null };
    needsPasscode: boolean;
    known: { name: string } | null;
    open: boolean;
    notice: string | null;
    token: string;
};

/** The page a guest link opens: who you are (name, optional e-mail, passcode), then into the meeting. No account needed. */
export default function GuestJoin({ meeting, needsPasscode, known, open, notice, token }: Props) {
    const { flash } = usePage<{ flash?: { error?: string } }>().props;
    const form = useForm({ name: known?.name ?? '', email: '', passcode: '' });

    useEffect(() => {
        if (open || ['ended', 'cancelled'].includes(meeting.status)) {
            return;
        }

        // waiting for the host: look every 15 seconds
        const t = setInterval(async () => {
            const r = await fetch(`/join/${token}/status`, { headers: { Accept: 'application/json' } }).catch(() => null);

            if (r?.ok && (await r.json()).status === 'live') {
                router.reload();
            }
        }, 15000);

        return () => clearInterval(t);
    }, [open, meeting.status, token]);

    return (
        <div className="flex min-h-dvh items-center justify-center bg-background p-4">
            <Head title={meeting.title} />
            <div className="surface w-full max-w-md p-6 md:p-8">
                {meeting.institution && <p className="eyebrow">{meeting.institution}</p>}
                <h1 className="mt-1 text-2xl font-semibold">{meeting.title}</h1>
                <p className="mt-1 text-sm text-muted-foreground">{formatDateTime(meeting.starts_at)} – {new Date(meeting.ends_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</p>
                {meeting.description && <p className="mt-3 whitespace-pre-line text-sm">{meeting.description}</p>}

                {(flash?.error || notice) && <p className="mt-4 rounded-md bg-muted px-3 py-2 text-sm">{flash?.error ?? notice}</p>}

                {!['ended', 'cancelled'].includes(meeting.status) && (
                    <form className="mt-5 grid gap-3" onSubmit={(e) => (e.preventDefault(), form.post(`/join/${token}`))}>
                        <Field label="Your name" hint="Shown to everyone in the meeting" error={form.errors.name}>
                            <input className={inputClass} value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required minLength={2} maxLength={80} autoComplete="name" />
                        </Field>
                        <Field label="E-mail (optional)" error={form.errors.email}>
                            <input type="email" className={inputClass} value={form.data.email} onChange={(e) => form.setData('email', e.target.value)} autoComplete="email" />
                        </Field>
                        {needsPasscode && (
                            <Field label="Passcode" error={form.errors.passcode}>
                                <input className={inputClass} value={form.data.passcode} onChange={(e) => form.setData('passcode', e.target.value)} required autoComplete="off" />
                            </Field>
                        )}
                        <Button size="lg" disabled={!open || form.processing}><Video className="size-4" /> Join the meeting</Button>
                        <p className="text-xs text-muted-foreground">You join as a guest. A host lets you in from the waiting room. Your camera and microphone are used only in the meeting.</p>
                    </form>
                )}
            </div>
        </div>
    );
}
