import type { InertiaLinkProps } from '@inertiajs/react';
import { clsx } from 'clsx';
import type { ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}

/** The name to greet someone by: the first word after any title ("Dr. Ngozi Okeke" → "Ngozi"). */
export function firstName(full: string): string {
    const words = full.trim().split(/\s+/);
    const rest = words.filter((w) => !/^(dr|prof|professor|mr|mrs|ms|miss|engr|rev|arc|barr|chief|sir|hon|pharm|dcn|pst)\.?$/i.test(w));

    return rest[0] ?? words[0] ?? '';
}
