import { Head } from '@inertiajs/react';
import { Wrench } from 'lucide-react';

/** Shown while the platform team has switched on maintenance mode. */
export default function Maintenance({ message }: { message: string }) {
    return (
        <>
            <Head title="Down for maintenance" />
            <main className="flex min-h-svh items-center justify-center bg-background p-6">
                <div className="surface max-w-md p-8 text-center">
                    <Wrench className="mx-auto size-10 text-status-warning" />
                    <h1 className="mt-4 text-2xl font-semibold tracking-tight">We'll be right back</h1>
                    <p className="mt-3 text-muted-foreground">{message}</p>
                    <p className="mt-2 text-sm text-muted-foreground">Nothing has been lost. Refresh this page in a few minutes.</p>
                </div>
            </main>
        </>
    );
}
