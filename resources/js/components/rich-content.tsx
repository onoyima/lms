import { useEffect, useRef } from 'react';
import { cn } from '@/lib/utils';

type MathJaxGlobal = {
    startup?: { promise?: Promise<unknown> };
    typesetPromise?: (elements?: Element[]) => Promise<unknown>;
    typesetClear?: (elements?: Element[]) => void;
};

/**
 * Shows question HTML (already sanitised on the server) and renders its maths:
 * LaTeX in \( \) / \[ \] / $ $, chemistry with \ce{}, using the local MathJax copy.
 */
export function RichContent({
    html,
    className,
    as: Tag = 'div',
}: {
    html: string;
    className?: string;
    as?: 'div' | 'span';
}) {
    const ref = useRef<HTMLElement>(null);

    useEffect(() => {
        const el = ref.current;
        const mj = (window as unknown as { MathJax?: MathJaxGlobal }).MathJax;

        if (!el || !mj) {
            return;
        }

        let cancelled = false;
        const run = () => {
            if (cancelled || !mj.typesetPromise) {
                return;
            }

            mj.typesetClear?.([el]);
            mj.typesetPromise([el]).catch((e) => console.warn('MathJax', e));
        };

        if (mj.startup?.promise) {
            mj.startup.promise.then(run);
        } else {
            // MathJax script still loading (deferred): try again shortly
            const timer = window.setInterval(() => {
                const ready = (window as unknown as { MathJax?: MathJaxGlobal }).MathJax;

                if (ready?.startup?.promise) {
                    window.clearInterval(timer);
                    ready.startup.promise.then(run);
                }
            }, 100);

            return () => {
                cancelled = true;
                window.clearInterval(timer);
            };
        }

        return () => {
            cancelled = true;
        };
    }, [html]);

    return (
        <Tag
            ref={ref as never}
            className={cn('rich-content', className)}
            dangerouslySetInnerHTML={{ __html: html }}
        />
    );
}
