import { Head, Link, router } from '@inertiajs/react';
import { BellOff, Flag, Plus, Send } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { Flash, formatDateTime, inputClass, PageHeader, textareaClass } from '@/components/platform/ui';
import { Button } from '@/components/ui/button';

type Conv = { id: number; subject: string | null; with: string; last_message_at: string | null; preview: string; unread: boolean; muted: boolean };
type Thread = { id: number; subject: string | null; people: { id: number; name: string }[]; messages: { id: number; body: string; created_at: string; name: string; mine: boolean }[] };
type Person = { id: number; name: string; identifier: string | null; user_type: string };

function Compose({ onClose }: { onClose: () => void }) {
    const [q, setQ] = useState('');
    const [found, setFound] = useState<Person[]>([]);
    const [to, setTo] = useState<Person[]>([]);
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');

    useEffect(() => {
        if (q.trim().length < 2) {
            return;
        }

        const t = setTimeout(async () => {
            const r = await fetch(`/messages/recipients?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' } });

            if (r.ok) {
                setFound(await r.json());
            }
        }, 250);

        return () => clearTimeout(t);
    }, [q]);

    return (
        <div className="surface grid gap-3 p-5">
            <p className="eyebrow">New message</p>
            <div className="flex flex-wrap gap-1">
                {to.map((p) => <button key={p.id} className="rounded-full bg-brand-soft px-2 py-0.5 text-xs" onClick={() => setTo(to.filter((x) => x.id !== p.id))}>{p.name} ×</button>)}
            </div>
            <input className={inputClass} placeholder="To: type a name or matric number" value={q} onChange={(e) => setQ(e.target.value)} />
            {q.length >= 2 && found.length > 0 && (
                <div className="grid max-h-40 gap-1 overflow-y-auto rounded-xl border border-border p-2 text-sm">
                    {found.filter((p) => !to.some((x) => x.id === p.id)).map((p) => (
                        <button key={p.id} className="text-left hover:text-brand" onClick={() => {
 setTo([...to, p]); setQ(''); setFound([]); 
}}>
                            {p.name} <span className="text-xs text-muted-foreground">{p.identifier} · {p.user_type}</span>
                        </button>
                    ))}
                </div>
            )}
            <input className={inputClass} placeholder="Subject (optional)" value={subject} onChange={(e) => setSubject(e.target.value)} />
            <textarea className={textareaClass} placeholder="Message" value={body} onChange={(e) => setBody(e.target.value)} />
            <div className="flex gap-2">
                <Button disabled={!to.length || !body.trim()} onClick={() => router.post('/messages', { to: to.map((p) => p.id), subject, body })}><Send className="size-4" /> Send</Button>
                <Button variant="outline" onClick={onClose}>Cancel</Button>
            </div>
        </div>
    );
}

export default function Messages({ conversations, open }: { conversations: Conv[]; open: Thread | null }) {
    const [composing, setComposing] = useState(false);
    const [reply, setReply] = useState('');
    const end = useRef<HTMLDivElement>(null);
    useEffect(() => end.current?.scrollIntoView(), [open?.messages.length]);

    return (
        <>
            <Head title="Messages" />
            <div className="flex flex-col gap-6 p-6 md:p-10">
                <PageHeader title="Inbox" actions={<Button onClick={() => setComposing(true)}><Plus className="size-4" /> New message</Button>} />
                <Flash />
                {composing && <Compose onClose={() => setComposing(false)} />}
                <div className="grid gap-4 lg:grid-cols-[340px_1fr]">
                    <aside className="surface grid content-start divide-y divide-border">
                        {conversations.length === 0 && <p className="p-5 text-sm text-muted-foreground">No messages yet.</p>}
                        {conversations.map((c) => (
                            <Link key={c.id} href={`/messages?c=${c.id}`} preserveScroll className={`grid gap-0.5 p-3 text-sm ${open?.id === c.id ? 'bg-brand-soft' : 'hover:bg-muted'}`}>
                                <span className={`flex items-center justify-between gap-2 ${c.unread ? 'font-semibold' : ''}`}>
                                    <span className="truncate">{c.with || 'Only you'}</span>
                                    {c.muted && <BellOff className="size-3.5 text-muted-foreground" />}
                                </span>
                                {c.subject && <span className="truncate text-xs">{c.subject}</span>}
                                <span className="truncate text-xs text-muted-foreground">{c.preview}</span>
                            </Link>
                        ))}
                    </aside>
                    {open ? (
                        <section className="surface grid content-start gap-3 p-5">
                            <div className="flex items-center justify-between gap-2">
                                <div>
                                    <p className="font-medium">{open.subject ?? 'Conversation'}</p>
                                    <p className="text-xs text-muted-foreground">{open.people.map((p) => p.name).join(', ')}</p>
                                </div>
                                <Button size="sm" variant="ghost" onClick={() => router.post(`/messages/${open.id}/mute`, {}, { preserveScroll: true })}><BellOff className="size-4" /> Mute</Button>
                            </div>
                            <div className="grid max-h-[55vh] gap-2 overflow-y-auto">
                                {open.messages.map((m) => (
                                    <div key={m.id} className={`group max-w-[80%] rounded-lg p-3 text-sm ${m.mine ? 'ml-auto bg-brand-soft' : 'bg-muted'}`}>
                                        <p className="text-xs text-muted-foreground">{m.name} · {formatDateTime(m.created_at)}</p>
                                        <p className="whitespace-pre-wrap">{m.body}</p>
                                        {!m.mine && (
                                            <button className="mt-1 hidden items-center gap-1 text-[11px] text-muted-foreground group-hover:flex" onClick={() => {
 const reason = prompt('Why are you reporting this message?');

 if (reason) {
 router.post(`/messages/items/${m.id}/report`, { reason }, { preserveScroll: true }); 
} 
}}>
                                                <Flag className="size-3" /> Report
                                            </button>
                                        )}
                                    </div>
                                ))}
                                <div ref={end} />
                            </div>
                            <form className="flex gap-2" onSubmit={(e) => {
 e.preventDefault(); router.post(`/messages/${open.id}`, { body: reply }, { preserveScroll: true, onSuccess: () => setReply('') }); 
}}>
                                <textarea className={`${textareaClass} min-h-12`} value={reply} onChange={(e) => setReply(e.target.value)} placeholder="Reply" />
                                <Button disabled={!reply.trim()}><Send className="size-4" /></Button>
                            </form>
                        </section>
                    ) : (
                        <div className="surface grid place-items-center p-10 text-sm text-muted-foreground">Choose a conversation.</div>
                    )}
                </div>
            </div>
        </>
    );
}

Messages.layout = { breadcrumbs: [{ title: 'Messages', href: '/messages' }] };
