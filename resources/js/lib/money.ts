/** Amounts are kept in minor units (kobo, cents); show them in the package's own currency. */
export function money(minor: number, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: minor % 100 === 0 ? 0 : 2 }).format(minor / 100);
    } catch {
        return `${currency} ${(minor / 100).toFixed(2)}`;
    }
}
