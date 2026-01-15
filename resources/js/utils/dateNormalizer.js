// Normalize various date inputs to ISO date string (YYYY-MM-DD) or null
// Accepts: null | string | Date
function normalizeDate(value) {
    const d = parseToDate(value);
    return d ? d.toISOString().slice(0, 10) : null;
}

function parseToDate(value) {
    if (value === null || value === undefined || value === "") return null;

    if (value instanceof Date && !isNaN(value)) {
        return new Date(
            Date.UTC(
                value.getUTCFullYear(),
                value.getUTCMonth(),
                value.getUTCDate(),
            ),
        );
    }

    const str = String(value).trim();

    // Direct ISO date
    if (/^\d{4}-\d{2}-\d{2}$/.test(str)) {
        const [y, m, d] = str.split("-").map((n) => parseInt(n, 10));
        return new Date(Date.UTC(y, m - 1, d));
    }

    // ISO datetime variants
    if (/^\d{4}-\d{2}-\d{2}T/.test(str)) {
        const dt = new Date(str);
        if (!isNaN(dt)) {
            return new Date(
                Date.UTC(
                    dt.getUTCFullYear(),
                    dt.getUTCMonth(),
                    dt.getUTCDate(),
                ),
            );
        }
    }

    // Slash separated: prefer m/d/Y unless first part > 12
    const slash = str.match(/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/);
    if (slash) {
        const p1 = parseInt(slash[1], 10);
        const p2 = parseInt(slash[2], 10);
        const y = parseInt(slash[3], 10);
        let m = p1;
        let d = p2;
        if (p1 > 12) {
            m = p2;
            d = p1;
        }
        return new Date(Date.UTC(y, m - 1, d));
    }

    // Dash separated d-m-Y
    const dash = str.match(/^(\d{1,2})-(\d{1,2})-(\d{4})$/);
    if (dash) {
        const d = parseInt(dash[1], 10);
        const m = parseInt(dash[2], 10);
        const y = parseInt(dash[3], 10);
        return new Date(Date.UTC(y, m - 1, d));
    }

    // Fallback to native Date parsing
    const dt = new Date(str);
    if (!isNaN(dt)) {
        return new Date(
            Date.UTC(dt.getUTCFullYear(), dt.getUTCMonth(), dt.getUTCDate()),
        );
    }

    return null;
}

export { normalizeDate, parseToDate };
