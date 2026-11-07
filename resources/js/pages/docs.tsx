import { Head, Link, router } from '@inertiajs/react';
import { BookOpen, Search } from 'lucide-react';
import { useState } from 'react';
import { inputClass } from '@/components/platform/ui';

type Props = {
    base: string; set: 'institution' | 'platform';
    nav: { section: string; pages: { slug: string; title: string }[] }[];
    page: { slug: string; title: string; section: string; html: string };
    q: string; results: { slug: string; title: string; snippet: string }[];
};

/** Application documentation, filtered to what the reader can use. */
export default function Docs({ base, set, nav, page, q, results }: Props) {
    const [query, setQuery] = useState(q);

    return (
        <>
            <Head title={`${page.title} · Documentation`} />
            <div className="grid gap-8 p-6 md:p-10 lg:grid-cols-[240px_1fr]">
                <aside className="grid content-start gap-5 lg:sticky lg:top-6 lg:self-start" aria-label="Documentation topics">
                    <p className="flex items-center gap-2 font-medium"><BookOpen className="size-4 text-brand" /> {set === 'platform' ? 'Platform documentation' : 'Documentation'}</p>
                    <form role="search" className="relative" onSubmit={(e) => {
 e.preventDefault(); router.get(`${base}/${page.slug}`, { q: query || undefined }, { preserveScroll: true }); 
}}>
                        <Search className="pointer-events-none absolute top-3 left-3 size-4 text-muted-foreground" />
                        <input aria-label="Search the documentation" className={`${inputClass} pl-9`} value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search" />
                    </form>
                    {nav.map((g) => (
                        <div key={g.section} className="grid gap-1">
                            <p className="text-xs font-medium text-muted-foreground">{g.section}</p>
                            {g.pages.map((p) => (
                                <Link key={p.slug} href={`${base}/${p.slug}`} className={`rounded-lg px-2 py-1 text-sm ${p.slug === page.slug ? 'bg-brand-soft font-medium text-brand' : 'hover:bg-muted'}`}>{p.title}</Link>
                            ))}
                        </div>
                    ))}
                    {set === 'institution' && <Link href="/templates" className="text-sm text-brand underline-offset-4 hover:underline">Import templates</Link>}
                </aside>
                <main className="min-w-0">
                    {q && (
                        <section className="surface mb-6 grid gap-2 p-5" aria-label="Search results">
                            <p className="text-sm font-medium">{results.length} result{results.length === 1 ? '' : 's'} for “{q}”</p>
                            {results.map((r) => (
                                <Link key={r.slug} href={`${base}/${r.slug}`} className="rounded-lg p-2 hover:bg-muted"><span className="font-medium">{r.title}</span><span className="block text-sm text-muted-foreground">{r.snippet}</span></Link>
                            ))}
                        </section>
                    )}
                    <p className="text-sm text-muted-foreground">{page.section}</p>
                    <h1 className="text-[1.75rem] leading-tight font-semibold">{page.title}</h1>
                    <article
                        className="mt-6 max-w-3xl leading-relaxed [&_a]:text-brand [&_a]:underline [&_code]:rounded [&_code]:bg-muted [&_code]:px-1 [&_code]:text-[0.9em] [&_h2]:mt-8 [&_h2]:mb-2 [&_h2]:text-xl [&_h2]:font-semibold [&_h3]:mt-6 [&_h3]:font-semibold [&_li]:my-1 [&_ol]:list-decimal [&_ol]:pl-6 [&_p]:my-3 [&_pre]:my-4 [&_pre]:overflow-x-auto [&_pre]:rounded-xl [&_pre]:bg-muted [&_pre]:p-4 [&_pre_code]:bg-transparent [&_pre_code]:p-0 [&_table]:my-4 [&_table]:w-full [&_table]:text-sm [&_td]:border-t [&_td]:p-2 [&_td]:align-top [&_th]:p-2 [&_th]:text-left [&_ul]:list-disc [&_ul]:pl-6"
                        dangerouslySetInnerHTML={{ __html: page.html }}
                    />
                </main>
            </div>
        </>
    );
}
