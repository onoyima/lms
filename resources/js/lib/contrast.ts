/** Near-black or white text for a background colour (WCAG relative luminance). Mirrors App\Support\Branding. */
export function readableTextOn(hex: string): string {
    const m = /^#([0-9a-f]{6})$/i.exec(hex);

    if (!m) {
        return '#0b0d10';
    }

    const [r, g, b] = [0, 2, 4].map((i) => parseInt(m[1].slice(i, i + 2), 16) / 255).map((c) => (c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4));
    const lum = 0.2126 * r + 0.7152 * g + 0.0722 * b;

    return (lum + 0.05) / 0.05 >= 1.05 / (lum + 0.05) ? '#0b0d10' : '#ffffff';
}
