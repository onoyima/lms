import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Sign in with a one-time code by email (or text message) instead of a password. */
export default function LoginCode({ sent, sms }: { sent?: { to: string; login: string } | null; sms: boolean }) {
    const ask = useForm({ login: sent?.login ?? '', via: 'email' as 'email' | 'sms' });
    const check = useForm({ code: '' });

    return (
        <>
            <Head title="Sign in with a code" />
            {!sent ? (
                <form className="space-y-6" onSubmit={(e) => (e.preventDefault(), ask.post('/login/code'))}>
                    <div className="grid gap-2">
                        <Label htmlFor="login">Email or matric / staff number</Label>
                        <Input id="login" name="login" autoFocus autoComplete="username" value={ask.data.login} onChange={(e) => ask.setData('login', e.target.value)} required />
                        <InputError message={ask.errors.login} />
                    </div>
                    {sms && (
                        <fieldset className="grid gap-2 text-sm">
                            <legend className="mb-1 font-medium">Send the code</legend>
                            <label className="flex items-center gap-2"><input type="radio" checked={ask.data.via === 'email'} onChange={() => ask.setData('via', 'email')} /> By email</label>
                            <label className="flex items-center gap-2"><input type="radio" checked={ask.data.via === 'sms'} onChange={() => ask.setData('via', 'sms')} /> By text message to my mobile number</label>
                        </fieldset>
                    )}
                    <Button className="w-full" disabled={ask.processing}>{ask.processing && <LoaderCircle className="h-4 w-4 animate-spin" />} Send me a code</Button>
                </form>
            ) : (
                <form className="space-y-6" onSubmit={(e) => (e.preventDefault(), check.post('/login/code/verify'))}>
                    <p className="text-sm text-muted-foreground">We sent a 6-digit code to {sent.to}. It works for 10 minutes.</p>
                    <div className="grid gap-2">
                        <Label htmlFor="code">Code</Label>
                        <Input id="code" name="code" inputMode="numeric" autoComplete="one-time-code" maxLength={6} autoFocus className="text-center font-mono text-2xl tracking-[0.4em]"
                            value={check.data.code} onChange={(e) => check.setData('code', e.target.value.replace(/\D/g, '').slice(0, 6))} required />
                        <InputError message={check.errors.code} />
                    </div>
                    <Button className="w-full" disabled={check.processing || check.data.code.length !== 6}>{check.processing && <LoaderCircle className="h-4 w-4 animate-spin" />} Sign in</Button>
                    <button type="button" className="w-full text-center text-sm text-muted-foreground underline-offset-4 hover:underline" onClick={() => ask.post('/login/code')}>Send a new code</button>
                </form>
            )}
            <div className="mt-6 space-x-1 text-center text-sm text-muted-foreground">
                <span>Or sign in</span>
                <TextLink href="/login">with your password</TextLink>
            </div>
        </>
    );
}

LoginCode.layout = {
    title: 'Sign in with a code',
    description: 'We send a one-time code to your email or phone',
};
