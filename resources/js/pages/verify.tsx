import { Head } from '@inertiajs/react';
import { CheckCircle2, ShieldX } from 'lucide-react';
import { formatDateTime } from '@/components/platform/ui';
import { Toaster } from '@/components/ui/sonner';

type Doc = { type: string; summary: string; holder: string; identifier: string | null; institution: string; issued_at: string; revoked: boolean; hash: string };

export default function Verify({ doc, code }: { doc: Doc | null; code: string }) {
    const ok = doc && !doc.revoked;

    return (
        <>
            <Head title="Verify a document" />
            <div className="flex min-h-screen items-center justify-center bg-background p-6">
                <div className="surface w-full max-w-lg p-8">
                    <p className="eyebrow">Document verification · {code}</p>
                    {ok ? <CheckCircle2 className="mt-4 size-10 text-status-good" /> : <ShieldX className="mt-4 size-10 text-status-critical" />}
                    <h1 className="mt-3 text-2xl font-semibold">{ok ? 'This document is genuine.' : doc ? 'This document has been withdrawn.' : 'No document has this code.'}</h1>
                    {doc && (
                        <dl className="mt-6 grid grid-cols-[140px_1fr] gap-2 text-sm">
                            <dt className="text-muted-foreground">Issued by</dt><dd>{doc.institution}</dd>
                            <dt className="text-muted-foreground">Document</dt><dd className="capitalize">{doc.summary}</dd>
                            <dt className="text-muted-foreground">Holder</dt><dd>{doc.holder} {doc.identifier && <span className="font-mono text-xs">({doc.identifier})</span>}</dd>
                            <dt className="text-muted-foreground">Issued</dt><dd>{formatDateTime(doc.issued_at)}</dd>
                            <dt className="text-muted-foreground">Fingerprint</dt><dd className="font-mono text-[10px] break-all">{doc.hash}</dd>
                        </dl>
                    )}
                    <p className="mt-6 text-xs text-muted-foreground">Compare these details with the printed document. If anything differs, the document is not genuine.</p>
                </div>
            </div>
            <Toaster />
        </>
    );
}
