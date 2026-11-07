import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, Lock, Pin, Trash2 } from 'lucide-react';
import { Flash, formatDateTime, textareaClass } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type Post = {
    id: number;
    author: string;
    staff: boolean;
    body_html: string;
    created_at: string;
    is_answer: boolean;
    mine: boolean;
    parent_id: number | null;
};
type Props = {
    thread: {
        id: number;
        title: string;
        locked: boolean;
        pinned: boolean;
        item_id: number;
        item: string;
    };
    posts: {
        data: Post[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    teacher: boolean;
};

export default function Thread({ thread, posts, teacher }: Props) {
    const form = useForm({ body_html: '' });
    const moderate = (body: Record<string, unknown>) =>
        router.post(`/learn/threads/${thread.id}/moderate`, body as never, {
            preserveScroll: true,
        });

    return (
        <>
            <Head title={thread.title} />
            <div className="mx-auto flex w-full max-w-4xl flex-col gap-6 p-6 md:p-10">
                <Link
                    href={`/learn/items/${thread.item_id}`}
                    className="flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-brand"
                >
                    <ArrowLeft className="size-4" /> {thread.item}
                </Link>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {thread.title}
                    </h1>
                    {teacher && (
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    moderate({ pinned: !thread.pinned })
                                }
                            >
                                <Pin className="size-4" />{' '}
                                {thread.pinned ? 'Unpin' : 'Pin'}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() =>
                                    moderate({ locked: !thread.locked })
                                }
                            >
                                <Lock className="size-4" />{' '}
                                {thread.locked ? 'Reopen' : 'Close'}
                            </Button>
                        </div>
                    )}
                </div>
                <Flash />
                <ol className="grid gap-4">
                    {posts.data.map((p) => (
                        <li
                            key={p.id}
                            className={`surface p-5 ${p.is_answer ? 'border-brand/60' : ''}`}
                        >
                            <div className="mb-3 flex items-center justify-between gap-3 text-sm">
                                <span className="font-medium">
                                    {p.author}{' '}
                                    {p.staff && (
                                        <span className="ml-1 rounded-full bg-brand-soft px-2 py-0.5 text-[11px] text-brand">
                                            staff
                                        </span>
                                    )}
                                </span>
                                <span className="text-xs text-muted-foreground">
                                    {formatDateTime(p.created_at)}
                                </span>
                            </div>
                            <RichContent
                                html={p.body_html}
                                className="leading-relaxed"
                            />
                            <div className="mt-3 flex gap-3 text-xs">
                                {p.is_answer && (
                                    <span className="flex items-center gap-1 text-brand">
                                        <CheckCircle2 className="size-3.5" />{' '}
                                        Accepted answer
                                    </span>
                                )}
                                {teacher && !p.is_answer && (
                                    <button
                                        className="text-muted-foreground hover:text-brand"
                                        onClick={() =>
                                            moderate({ answer_post_id: p.id })
                                        }
                                    >
                                        Mark as answer
                                    </button>
                                )}
                                {teacher && (
                                    <button
                                        className="text-muted-foreground hover:text-status-critical"
                                        onClick={() =>
                                            confirm('Remove this post?') &&
                                            moderate({ delete_post_id: p.id })
                                        }
                                    >
                                        <Trash2 className="inline size-3.5" />{' '}
                                        Remove
                                    </button>
                                )}
                            </div>
                        </li>
                    ))}
                </ol>
                {posts.links.length > 3 && (
                    <div className="flex gap-1">
                        {posts.links.map(
                            (l, i) =>
                                l.url && (
                                    <Link
                                        key={i}
                                        href={l.url}
                                        className={`rounded-lg px-3 py-1 text-sm ${l.active ? 'bg-brand text-brand-foreground' : 'border border-border'}`}
                                        dangerouslySetInnerHTML={{
                                            __html: l.label,
                                        }}
                                    />
                                ),
                        )}
                    </div>
                )}
                {thread.locked && !teacher ? (
                    <p className="text-sm text-muted-foreground">
                        This discussion is closed.
                    </p>
                ) : (
                    <form
                        className="surface grid gap-3 p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(`/learn/threads/${thread.id}/posts`, {
                                preserveScroll: true,
                                onSuccess: () => form.reset(),
                            });
                        }}
                    >
                        <textarea
                            className={`${textareaClass} min-h-28`}
                            placeholder="Reply… maths \( x^2 \), chemistry \( \ce{CO2} \)"
                            value={form.data.body_html}
                            onChange={(e) =>
                                form.setData('body_html', e.target.value)
                            }
                            required
                        />
                        <Button className="w-fit" disabled={form.processing}>
                            Reply
                        </Button>
                    </form>
                )}
            </div>
        </>
    );
}
