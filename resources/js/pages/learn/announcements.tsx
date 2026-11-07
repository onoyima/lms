import { Head, Link, useForm } from '@inertiajs/react';
import { Megaphone } from 'lucide-react';
import {
    Flash,
    formatDateTime,
    inputClass,
    PageHeader,
    textareaClass,
} from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

type A = {
    id: number;
    title: string;
    body_html: string;
    urgent: boolean;
    scope: string;
    author: string;
    publish_at: string;
};
type Props = {
    announcements: {
        data: A[];
        links: { url: string | null; label: string; active: boolean }[];
    };
    targets: { scope: string; id: number | null; label: string }[];
};

export default function Announcements({ announcements, targets }: Props) {
    const form = useForm({
        target: targets[0] ? `${targets[0].scope}:${targets[0].id ?? ''}` : '',
        title: '',
        body_html: '',
        urgent: false,
        publish_at: '',
    });

    return (
        <>
            <Head title="Announcements" />
            <div className="flex flex-col gap-8 p-6 md:p-10">
                <PageHeader
                    title="What's new"
                    lead="From your courses, department, faculty and the university."
                />
                <Flash />
                <div className="grid gap-8 lg:grid-cols-[1fr_380px]">
                    <div className="grid content-start gap-4">
                        {announcements.data.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                Nothing yet.
                            </p>
                        )}
                        {announcements.data.map((a) => (
                            <article
                                key={a.id}
                                className={`surface p-6 ${a.urgent ? 'border-status-critical/60' : ''}`}
                            >
                                <p className="eyebrow">
                                    {a.urgent && (
                                        <span className="text-status-critical">
                                            Urgent ·{' '}
                                        </span>
                                    )}
                                    {a.scope} · {a.author} ·{' '}
                                    {formatDateTime(a.publish_at)}
                                </p>
                                <h3 className="mt-2 font-medium">{a.title}</h3>
                                <RichContent
                                    html={a.body_html}
                                    className="mt-2 text-sm leading-relaxed"
                                />
                            </article>
                        ))}
                        {announcements.links.length > 3 && (
                            <div className="flex gap-1">
                                {announcements.links.map(
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
                    </div>
                    {targets.length > 0 && (
                        <form
                            className="surface grid content-start gap-3 p-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                const [scope, id] = form.data.target.split(':');
                                form.transform((d) => ({
                                    scope,
                                    scope_id: id ? Number(id) : null,
                                    title: d.title,
                                    body_html: d.body_html,
                                    urgent: d.urgent,
                                    publish_at: d.publish_at || null,
                                }));
                                form.post('/learn/announcements', {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.reset('title', 'body_html'),
                                });
                            }}
                        >
                            <p className="eyebrow flex items-center gap-2">
                                <Megaphone className="size-3.5" /> New
                                announcement
                            </p>
                            <select
                                className={inputClass}
                                value={form.data.target}
                                onChange={(e) =>
                                    form.setData('target', e.target.value)
                                }
                            >
                                {targets.map((t) => (
                                    <option
                                        key={`${t.scope}:${t.id}`}
                                        value={`${t.scope}:${t.id ?? ''}`}
                                    >
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                            <input
                                className={inputClass}
                                placeholder="Title"
                                value={form.data.title}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                                required
                            />
                            <textarea
                                className={`${textareaClass} min-h-32`}
                                placeholder="Message"
                                value={form.data.body_html}
                                onChange={(e) =>
                                    form.setData('body_html', e.target.value)
                                }
                                required
                            />
                            <label className="grid gap-1 text-xs">
                                Send later (optional)
                                <input
                                    type="datetime-local"
                                    className={inputClass}
                                    value={form.data.publish_at}
                                    onChange={(e) =>
                                        form.setData(
                                            'publish_at',
                                            e.target.value,
                                        )
                                    }
                                />
                            </label>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    checked={form.data.urgent}
                                    onChange={(e) =>
                                        form.setData('urgent', e.target.checked)
                                    }
                                />{' '}
                                Urgent
                            </label>
                            <Button disabled={form.processing}>Send</Button>
                        </form>
                    )}
                </div>
            </div>
        </>
    );
}
