import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, House } from 'lucide-react';
import { Button } from '@/components/ui/button';

const TITLES: Record<number, [string, string]> = {
    403: ['Not available to you', 'You do not have access to this page.'],
    404: ['Page not found', 'This page does not exist or has been removed.'],
    500: ['Something went wrong', 'It has been recorded. Try again in a moment.'],
    503: ['Down for maintenance', 'We will be back shortly.'],
};

/** A refused or missing page, inside the app: the reason, then Back and Home. */
export default function ErrorPage({ status, message }: { status: number; message?: string }) {
    const [title, fallback] = TITLES[status] ?? TITLES[500];
    const auth = (usePage().props as { auth?: { isPlatformAdmin?: boolean } }).auth;
    const module = /subscription|switched off/i.test(message ?? '');

    return (
        <>
            <Head title={title} />
            <div className="grid min-h-[60vh] place-items-center p-6">
                <div className="surface grid max-w-lg gap-4 p-8 text-center">
                    <p className="font-mono text-sm text-muted-foreground">{status}</p>
                    <h1 className="text-2xl font-semibold">{module ? 'This feature is switched off' : title}</h1>
                    <p className="text-muted-foreground">
                        {module ? 'Your institution does not have this part of the platform at the moment. Ask your administrator if you need it.' : message || fallback}
                    </p>
                    <div className="flex flex-wrap justify-center gap-2">
                        <Button variant="outline" onClick={() => window.history.back()}>
                            <ArrowLeft className="size-4" /> Go back
                        </Button>
                        <Button asChild>
                            <Link href={auth?.isPlatformAdmin ? '/platform' : '/dashboard'}>
                                <House className="size-4" /> Home
                            </Link>
                        </Button>
                    </div>
                </div>
            </div>
        </>
    );
}
