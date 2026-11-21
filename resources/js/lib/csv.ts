/** A small CSV reader for files people save from Excel (commas, semicolons or tabs; quoted cells; a UTF-8 mark). */
export function parseCsv(text: string): string[][] {
    text = text.replace(/^\uFEFF/, '').replace(/\r\n?/g, '\n');
    const first = text.split('\n', 1)[0] ?? '';
    const counts: [string, number][] = [',', ';', '\t'].map((d) => [d, first.split(d).length - 1]);
    const delim = counts.sort((a, b) => b[1] - a[1])[0][1] > 0 ? counts[0][0] : ',';
    const rows: string[][] = [];
    let row: string[] = [];
    let cell = '';
    let quoted = false;

    for (let i = 0; i < text.length; i++) {
        const c = text[i];

        if (quoted) {
            if (c === '"' && text[i + 1] === '"') {
                cell += '"';
                i++;
            } else if (c === '"') {
                quoted = false;
            } else {
                cell += c;
            }
        } else if (c === '"' && cell === '') {
            quoted = true;
        } else if (c === delim) {
            row.push(cell.trim());
            cell = '';
        } else if (c === '\n') {
            row.push(cell.trim());
            rows.push(row);
            row = [];
            cell = '';
        } else {
            cell += c;
        }
    }

    if (cell !== '' || row.length) {
        row.push(cell.trim());
        rows.push(row);
    }

    return rows.filter((r) => r.some((c) => c !== ''));
}
