import { Head, Link, router } from '@inertiajs/react';
import { ShieldOff } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';

/** Shown to everyone at a suspended institution except its administrators (who are sent to billing). */
export default function Suspended({ institution, reason, signedIn }: { institution: string | null; reason: string | null; signedIn: boolean }) {
    return (
        <>
            <Head title="Access suspended" />
            <main className="flex min-h-svh items-center justify-center bg-background p-6">
                <div className="surface max-w-md p-8 text-center">
                    <ShieldOff className="mx-auto size-10 text-status-critical" />
                    <h1 className="mt-4 text-2xl font-semibold tracking-tight">Access is paused</h1>
                    <p className="mt-3 text-muted-foreground">
                        {institution ?? 'This institution'} cannot use this service at the moment.{reason ? ` ${reason}` : ''} Your work is safe and nothing has been deleted.
                    </p>
                    <p className="mt-2 text-sm text-muted-foreground">Please contact your institution's ICT or exams office. Administrators can sign in to settle the account.</p>
                    <div className="mt-6 flex justify-center gap-2">
                        {signedIn ? <Button variant="outline" onClick={() => router.post('/logout')}>Sign out</Button> : <Button asChild><Link href="/login">Administrator sign in</Link></Button>}
                    </div>
                </div>
            </main>
            <Toaster />
        </>
    );
}
