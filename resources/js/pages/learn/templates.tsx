import { Head } from '@inertiajs/react';
import { Download } from 'lucide-react';
import { PageHeader } from '@/components/platform/ui';

type T = { key: string; title: string; about: string; file: string };

/** Sample templates for every import and integration this person can use. */
export default function Templates({ templates }: { templates: T[] }) {
    return (
        <>
            <Head title="Templates" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Import and integration templates" lead="Each file is filled with sample rows that import cleanly. Replace the rows with your own, keep the first line, and upload. CSV files open in Excel." />
                <div className="grid gap-3 md:grid-cols-2">
                    {templates.map((t) => (
                        <a key={t.key} href={`/templates/${t.key}`} className="surface flex items-start gap-3 p-5 hover:border-brand/50">
                            <Download className="mt-0.5 size-5 shrink-0 text-brand" />
                            <span>
                                <span className="font-medium">{t.title}</span>
                                <span className="block font-mono text-xs text-muted-foreground">{t.file}</span>
                                <span className="mt-1 block text-sm text-muted-foreground">{t.about}</span>
                            </span>
                        </a>
                    ))}
                </div>
            </div>
        </>
    );
}

Templates.layout = { breadcrumbs: [{ title: 'Templates', href: '/templates' }] };
