import { router, usePage } from '@inertiajs/react';
import { ChevronDown, ChevronUp, Eye, EyeOff, GripVertical, LayoutGrid, Maximize2, Minimize2, RotateCcw, X } from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/*
 * Cards a person can arrange for themselves: drag to reorder (mouse, touch, or the arrow keys on the
 * handle), minimise to a title bar and back, widen to the full row or back, hide one by one or all at
 * once, show again, and reset. Saved to their account for this page (PUT /arrangement/{page}).
 */

export type ArrangeCard = {
    key: string;
    title: string;
    node: ReactNode;
    /** Spans the whole row unless the person narrows it. */
    wide?: boolean;
};

type Saved = { order?: string[]; hidden?: string[]; collapsed?: string[]; wide?: string[]; narrow?: string[] };
type State = { order: string[]; hidden: string[]; collapsed: string[]; wide: string[]; narrow: string[] };

const reduced = () => typeof window !== 'undefined' && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

export function useArrangement(page: string, keys: string[]) {
    const saved = ((usePage().props as { layouts?: Record<string, Saved> }).layouts ?? {})[page] ?? {};
    const [state, setState] = useState<State>(() => ({
        order: saved.order ?? [], hidden: saved.hidden ?? [], collapsed: saved.collapsed ?? [], wide: saved.wide ?? [], narrow: saved.narrow ?? [],
    }));
    const timer = useRef<number | undefined>(undefined);

    const order = useMemo(() => [...state.order.filter((k) => keys.includes(k)), ...keys.filter((k) => !state.order.includes(k))], [state.order, keys]);

    const save = useCallback((next: State) => {
        setState(next);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => {
            router.put(`/arrangement/${page}`, next, { preserveScroll: true, preserveState: true, only: [] });
        }, 400);
    }, [page]);

    return { state, order, save, setState };
}

export function Arrangeable({ page, cards, className, toolbar, label = 'cards', emptyNote }: {
    page: string;
    cards: ArrangeCard[];
    /** Grid classes for the cards, e.g. "grid gap-4 md:grid-cols-2". */
    className: string;
    /** Extra controls shown in the arrange bar (e.g. which figures to show). */
    toolbar?: ReactNode;
    label?: string;
    emptyNote?: ReactNode;
}) {
    const keys = useMemo(() => cards.map((c) => c.key), [cards]);
    const { state, order, save, setState } = useArrangement(page, keys);
    const [arranging, setArranging] = useState(false);
    const [dragging, setDragging] = useState<string | null>(null);
    const grid = useRef<HTMLDivElement>(null);
    const rects = useRef(new Map<string, DOMRect>());
    const byKey = useMemo(() => new Map(cards.map((c) => [c.key, c])), [cards]);
    const visible = order.filter((k) => !state.hidden.includes(k));
    const hiddenHere = order.filter((k) => state.hidden.includes(k));

    const isWide = (c: ArrangeCard) => (c.wide ? !state.narrow.includes(c.key) : state.wide.includes(c.key));
    const toggleIn = (list: keyof Omit<State, 'order'>, k: string) => ({ ...state, [list]: state[list].includes(k) ? state[list].filter((x) => x !== k) : [...state[list], k] });
    // keys from other pages' versions (or other cards no longer here) are kept as they were
    const others = (list: string[]) => list.filter((k) => !keys.includes(k));

    // cards glide to their new place when the order changes
    const snapshot = () => grid.current?.querySelectorAll<HTMLElement>('[data-arr-key]').forEach((el) => rects.current.set(el.dataset.arrKey!, el.getBoundingClientRect()));
    useLayoutEffect(() => {
        if (reduced()) {
            return;
        }

        grid.current?.querySelectorAll<HTMLElement>('[data-arr-key]').forEach((el) => {
            const before = rects.current.get(el.dataset.arrKey!);
            const now = el.getBoundingClientRect();

            if (before && (before.left !== now.left || before.top !== now.top) && el.dataset.arrKey !== dragging) {
                el.animate([{ transform: `translate(${before.left - now.left}px, ${before.top - now.top}px)` }, { transform: 'none' }], { duration: 200, easing: 'cubic-bezier(.2,.8,.2,1)' });
            }
        });
        rects.current.clear();
    }, [order, dragging]);

    const move = (k: string, to: number) => {
        const o = order.filter((x) => x !== k);
        o.splice(Math.max(0, Math.min(o.length, to)), 0, k);
        snapshot();

        return { ...state, order: [...o, ...others(state.order)] };
    };

    const onPointerDown = (e: React.PointerEvent, k: string) => {
        e.preventDefault();
        (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
        setDragging(k);
    };
    const onPointerMove = (e: React.PointerEvent) => {
        if (!dragging) {
            return;
        }

        const over = (document.elementFromPoint(e.clientX, e.clientY) as HTMLElement | null)?.closest<HTMLElement>('[data-arr-key]');
        const target = over?.dataset.arrKey;

        if (target && target !== dragging && grid.current?.contains(over)) {
            // dropping on a card further on puts this one after it; on one before, in front of it
            const rest = order.filter((x) => x !== dragging);
            const at = rest.indexOf(target) + (order.indexOf(target) > order.indexOf(dragging) ? 1 : 0);
            setState(move(dragging, at));
        }
    };
    const onPointerUp = () => {
        if (dragging) {
            setDragging(null);
            save(state);
        }
    };
    const onKey = (e: React.KeyboardEvent, k: string) => {
        const d = e.key === 'ArrowLeft' || e.key === 'ArrowUp' ? -1 : e.key === 'ArrowRight' || e.key === 'ArrowDown' ? 1 : 0;

        if (d) {
            e.preventDefault();
            save(move(k, order.indexOf(k) + d));
        }
    };

    useEffect(() => {
        if (!arranging) {
            return;
        }

        const esc = (e: KeyboardEvent) => e.key === 'Escape' && setArranging(false);
        window.addEventListener('keydown', esc);

        return () => window.removeEventListener('keydown', esc);
    }, [arranging]);

    const bar = 'inline-flex h-8 items-center gap-1.5 rounded-lg border border-border bg-card px-2.5 text-[13px] font-medium transition-colors hover:border-brand hover:text-brand-ink';
    const tool = 'grid size-6 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-foreground/10 hover:text-foreground';

    if (cards.length === 0) {
        return null;
    }

    return (
        <div className="grid gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <button type="button" onClick={() => setArranging(!arranging)} aria-pressed={arranging} className={cn(bar, arranging && 'border-brand bg-brand text-brand-foreground hover:text-brand-foreground')}>
                    <LayoutGrid className="size-3.5" /> {arranging ? 'Done' : `Arrange ${label}`}
                </button>
                {arranging && (
                    <>
                        <span className="text-[13px] text-muted-foreground">Drag a card by <GripVertical className="inline size-3.5" />, or use the arrow keys on it.</span>
                        <button type="button" className={bar} onClick={() => save({ ...state, hidden: others(state.hidden) })}><Eye className="size-3.5" /> Show all</button>
                        <button type="button" className={bar} onClick={() => save({ ...state, hidden: [...others(state.hidden), ...keys] })}><EyeOff className="size-3.5" /> Hide all</button>
                        <button type="button" className={bar} onClick={() => save({ ...state, collapsed: [...others(state.collapsed), ...keys] })}><Minimize2 className="size-3.5" /> Minimise all</button>
                        <button type="button" className={bar} onClick={() => save({ ...state, collapsed: others(state.collapsed) })}><Maximize2 className="size-3.5" /> Open all</button>
                        <button type="button" className={bar} onClick={() => (snapshot(), save({ order: others(state.order), hidden: others(state.hidden), collapsed: others(state.collapsed), wide: others(state.wide), narrow: others(state.narrow) }))}>
                            <RotateCcw className="size-3.5" /> Reset
                        </button>
                    </>
                )}
                {hiddenHere.length > 0 && (
                    <span className="flex flex-wrap items-center gap-1.5 text-[13px] text-muted-foreground">
                        <span>Hidden:</span>
                        {hiddenHere.map((k) => (
                            <button key={k} type="button" onClick={() => save(toggleIn('hidden', k))} className="inline-flex items-center gap-1 rounded-full border border-dashed border-border px-2.5 py-0.5 hover:border-brand hover:text-brand-ink" title="Show this card">
                                <Eye className="size-3" /> {byKey.get(k)?.title}
                            </button>
                        ))}
                    </span>
                )}
                {arranging && toolbar}
            </div>

            {visible.length === 0 && <div className="rounded-2xl border border-dashed border-border p-8 text-center text-sm text-muted-foreground">{emptyNote ?? `All ${label} are hidden. Use Show all, or a name above, to bring them back.`}</div>}

            <div ref={grid} className={className} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={onPointerUp}>
                {visible.map((k) => {
                    const c = byKey.get(k)!;
                    const collapsed = state.collapsed.includes(k);
                    const wide = isWide(c);

                    return (
                        <div key={k} data-arr-key={k}
                            className={cn('group/arr relative min-w-0', wide && 'col-span-full', arranging && 'rounded-[1.25rem] outline-2 outline-offset-2 outline-dashed outline-border', dragging === k && 'z-10 scale-[1.01] opacity-80 outline-brand')}>
                            {collapsed ? (
                                <div className="surface flex items-center gap-2 px-4 py-3">
                                    <b className="min-w-0 flex-1 truncate text-sm font-semibold">{c.title}</b>
                                    <button type="button" className={tool} onClick={() => save(toggleIn('collapsed', k))} aria-label={`Open ${c.title}`} title="Open"><ChevronDown className="size-4" /></button>
                                </div>
                            ) : c.node}

                            <div className={cn('absolute -top-3.5 right-4 z-20 flex items-center gap-0.5 rounded-lg border border-border bg-card p-0.5 shadow-md transition-opacity',
                                arranging ? 'opacity-100' : collapsed ? 'hidden' : 'pointer-events-none opacity-0 group-focus-within/arr:pointer-events-auto group-focus-within/arr:opacity-100 group-hover/arr:pointer-events-auto group-hover/arr:opacity-100')}>
                                {arranging && (
                                    <button type="button" aria-label={`Move ${c.title}: drag, or use the arrow keys`} title="Drag to move" className={cn(tool, 'cursor-grab touch-none active:cursor-grabbing')}
                                        onPointerDown={(e) => onPointerDown(e, k)} onKeyDown={(e) => onKey(e, k)}>
                                        <GripVertical className="size-4" />
                                    </button>
                                )}
                                {!collapsed && (
                                    <button type="button" className={tool} onClick={() => save(toggleIn('collapsed', k))} aria-label={`Minimise ${c.title}`} title="Minimise"><ChevronUp className="size-4" /></button>
                                )}
                                <button type="button" className={cn(tool, 'hidden md:grid')} aria-label={wide ? `Make ${c.title} narrower` : `Make ${c.title} full width`} title={wide ? 'Narrower' : 'Full width'}
                                    onClick={() => (snapshot(), save(c.wide ? toggleIn('narrow', k) : toggleIn('wide', k)))}>
                                    {wide ? <Minimize2 className="size-3.5" /> : <Maximize2 className="size-3.5" />}
                                </button>
                                <button type="button" className={tool} onClick={() => (snapshot(), save(toggleIn('hidden', k)))} aria-label={`Hide ${c.title}`} title="Hide"><X className="size-4" /></button>
                            </div>
                        </div>
                    );
                })}
            </div>
        </div>
    );
}
