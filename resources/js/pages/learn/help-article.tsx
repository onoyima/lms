import { Head, Link, router } from '@inertiajs/react';
import { ThumbsDown, ThumbsUp } from 'lucide-react';
import { Flash, formatDateTime, PageHeader } from '@/components/platform/ui';
import { RichContent } from '@/components/rich-content';
import { Button } from '@/components/ui/button';

export default function HelpArticle({ article }: { article: { id: number; title: string; category: string; body_html: string; updated_at: string } }) {
    return (
        <>
            <Head title={article.title} />
            <div className="flex max-w-3xl flex-col gap-6 p-6 md:p-10">
                <PageHeader eyebrow={article.category} title={article.title} lead={`Updated ${formatDateTime(article.updated_at)}`} />
                <Flash />
                <RichContent html={article.body_html} className="prose max-w-none dark:prose-invert" />
                <div className="surface flex flex-wrap items-center gap-3 p-4 text-sm">
                    Did this answer your question?
                    <Button size="sm" variant="outline" onClick={() => router.post(`/help/articles/${article.id}/feedback`, { helpful: true }, { preserveScroll: true })}><ThumbsUp className="size-4" /> Yes</Button>
                    <Button size="sm" variant="outline" onClick={() => router.post(`/help/articles/${article.id}/feedback`, { helpful: false }, { preserveScroll: true })}><ThumbsDown className="size-4" /> No</Button>
                    <Link href="/help" className="ml-auto text-brand">Ask for help</Link>
                </div>
            </div>
        </>
    );
}

HelpArticle.layout = { breadcrumbs: [{ title: 'Help', href: '/help' }] };
