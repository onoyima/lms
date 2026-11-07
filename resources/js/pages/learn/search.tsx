import { Head, Link, router } from '@inertiajs/react';
import { Search as SearchIcon } from 'lucide-react';
import { useState } from 'react';
import { inputClass, PageHeader, Status } from '@/components/platform/ui';

type Result = { kind: string; title: string; course: string | null; url: string; snippet: string | null };

export default function Search({ q, results }: { q: string; results: Result[] }) {
    const [text, setText] = useState(q);

    return (
        <>
            <Head title="Search" />
            <div className="flex max-w-4xl flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Find anything" lead="Course pages, materials, assignments, forums, announcements and help articles you can open." />
                <form className="relative" onSubmit={(e) => {
 e.preventDefault(); router.get('/learn/search', { q: text }); 
}}>
                    <SearchIcon className="absolute top-3 left-3 size-4 text-muted-foreground" />
                    <input className={`${inputClass} pl-9`} value={text} onChange={(e) => setText(e.target.value)} placeholder="Search…" autoFocus aria-label="Search" />
                </form>
                {q && results.length === 0 && <p className="text-sm text-muted-foreground">Nothing found for “{q}”.</p>}
                <div className="grid gap-2">
                    {results.map((r, i) => (
                        <Link key={i} href={r.url} className="surface grid gap-1 p-4 hover:border-brand/50">
                            <span className="flex items-center gap-2 text-xs text-muted-foreground"><Status value={r.kind} /> {r.course}</span>
                            <span className="font-medium">{r.title}</span>
                            {r.snippet && <span className="text-sm text-muted-foreground">{r.snippet}</span>}
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}

Search.layout = { breadcrumbs: [{ title: 'Search', href: '/learn/search' }] };
