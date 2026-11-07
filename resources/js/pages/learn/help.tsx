import { Head, Link, router, useForm } from '@inertiajs/react';
import { LifeBuoy, Search } from 'lucide-react';
import { useState } from 'react';
import { Field, Flash, formatDateTime, inputClass, PageHeader, Status, Table, Td, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Article = { id: number; title: string; slug: string; category: string; audience: string; views: number };
type Ticket = { id: number; subject: string; category: string; priority: string; status: string; from: string | null; identifier: string | null; last_activity_at: string | null };

export default function Help({ articles, q, desk, tickets, queue, categories }: { articles: Article[]; q: string; desk: boolean; tickets: Ticket[]; queue: boolean; categories: string[] }) {
    const [text, setText] = useState(q);
    const [asking, setAsking] = useState(false);
    const [writing, setWriting] = useState(false);
    const ticket = useForm({ subject: '', category: 'general', body: '' });
    const article = useForm({ title: '', category: 'General', audience: 'all', body_html: '', published: true });
    const groups = [...new Set(articles.map((a) => a.category))];

    return (
        <>
            <Head title="Help" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader
                    title="How can we help?"
                    actions={
                        <>
                            {desk && <Button variant="outline" onClick={() => router.get('/help', queue ? {} : { queue: 1 })}>{queue ? 'My requests' : 'Support queue'}</Button>}
                            {desk && <Button variant="outline" onClick={() => setWriting(!writing)}>Write an article</Button>}
                            <Button onClick={() => setAsking(!asking)}><LifeBuoy className="size-4" /> Ask for help</Button>
                        </>
                    }
                />
                <Flash />
                <form className="relative max-w-2xl" onSubmit={(e) => {
 e.preventDefault(); router.get('/help', { q: text }); 
}}>
                    <Search className="absolute top-3 left-3 size-4 text-muted-foreground" />
                    <input className={`${inputClass} pl-9`} value={text} onChange={(e) => setText(e.target.value)} placeholder="Search answers, e.g. “forgot password”, “camera”, “resit”" aria-label="Search help" />
                </form>
                {asking && (
                    <form className="surface grid gap-3 p-5" onSubmit={(e) => {
 e.preventDefault(); ticket.post('/help/tickets'); 
}}>
                        <p className="eyebrow">New support request</p>
                        <div className="grid gap-3 md:grid-cols-3">
                            <div className="md:col-span-2"><Field label="Subject" error={ticket.errors.subject}><input className={inputClass} value={ticket.data.subject} onChange={(e) => ticket.setData('subject', e.target.value)} required /></Field></div>
                            <Field label="About">
                                <select className={inputClass} value={ticket.data.category} onChange={(e) => ticket.setData('category', e.target.value)}>
                                    {categories.map((c) => <option key={c} value={c}>{c}</option>)}
                                </select>
                            </Field>
                        </div>
                        <Field label="What happened?" error={ticket.errors.body}><textarea className={textareaClass} value={ticket.data.body} onChange={(e) => ticket.setData('body', e.target.value)} required /></Field>
                        <p className="text-xs text-muted-foreground">If you are in an exam right now, use “I need help” in the exam window instead — a proctor will answer at once.</p>
                        <Button className="w-fit" disabled={ticket.processing}>Send</Button>
                    </form>
                )}
                {writing && (
                    <form className="surface grid gap-3 p-5" onSubmit={(e) => {
 e.preventDefault(); article.post('/help/articles'); 
}}>
                        <p className="eyebrow">New help article</p>
                        <div className="grid gap-3 md:grid-cols-3">
                            <Field label="Title"><input className={inputClass} value={article.data.title} onChange={(e) => article.setData('title', e.target.value)} required /></Field>
                            <Field label="Category"><input className={inputClass} value={article.data.category} onChange={(e) => article.setData('category', e.target.value)} /></Field>
                            <Field label="For">
                                <select className={inputClass} value={article.data.audience} onChange={(e) => article.setData('audience', e.target.value)}>
                                    <option value="all">everyone</option><option value="students">students</option><option value="staff">staff</option>
                                </select>
                            </Field>
                        </div>
                        <Field label="Answer (basic HTML allowed)"><textarea className={`${textareaClass} min-h-40`} value={article.data.body_html} onChange={(e) => article.setData('body_html', e.target.value)} required /></Field>
                        <Button className="w-fit" disabled={article.processing}>Publish</Button>
                    </form>
                )}
                {tickets.length > 0 && (
                    <section className="grid gap-2">
                        <p className="eyebrow">{queue ? 'Open requests' : 'My requests'}</p>
                        <Table head={['Subject', queue ? 'From' : 'About', 'Priority', 'Status', 'Last activity']}>
                            {tickets.map((t) => (
                                <tr key={t.id}>
                                    <Td><Link href={`/help/tickets/${t.id}`} className="text-brand">{t.subject}</Link></Td>
                                    <Td>{queue ? `${t.from ?? ''} ${t.identifier ?? ''}` : t.category}</Td>
                                    <Td><Status value={t.priority} /></Td>
                                    <Td><Status value={t.status} /></Td>
                                    <Td>{formatDateTime(t.last_activity_at)}</Td>
                                </tr>
                            ))}
                        </Table>
                    </section>
                )}
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {groups.map((g) => (
                        <section key={g} className="surface grid content-start gap-2 p-5">
                            <p className="eyebrow">{g}</p>
                            {articles.filter((a) => a.category === g).map((a) => <Link key={a.id} href={`/help/${a.slug}`} className="text-sm hover:text-brand">{a.title}</Link>)}
                        </section>
                    ))}
                    {articles.length === 0 && <p className="text-sm text-muted-foreground">No articles {q ? `match “${q}”` : 'yet'}. Ask for help and the right office will reply.</p>}
                </div>
            </div>
        </>
    );
}

Help.layout = { breadcrumbs: [{ title: 'Help', href: '/help' }] };
