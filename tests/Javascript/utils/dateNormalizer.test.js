import { normalizeDate, parseToDate } from "@/utils/dateNormalizer";

describe("dateNormalizer (JS)", () => {
    test("normalizes ISO date", () => {
        expect(normalizeDate("2024-05-10")).toBe("2024-05-10");
    });

    test("normalizes ISO datetime", () => {
        expect(normalizeDate("2024-05-10T13:45:00Z")).toBe("2024-05-10");
    });

    test("normalizes slash dates preferring m/d/Y unless first part > 12", () => {
        expect(normalizeDate("05/10/2024")).toBe("2024-05-10");
        expect(normalizeDate("10/05/2024")).toBe("2024-10-05");
    });

    test("normalizes dash d-m-Y", () => {
        expect(normalizeDate("10-05-2024")).toBe("2024-05-10");
    });

    test("handles Date instance", () => {
        const d = new Date("2024-05-10T23:59:59Z");
        expect(normalizeDate(d)).toBe("2024-05-10");
    });

    test("returns null for invalid values", () => {
        expect(normalizeDate("not a date")).toBeNull();
        expect(normalizeDate("")).toBeNull();
        expect(normalizeDate(null)).toBeNull();
        expect(parseToDate("not a date")).toBeNull();
    });
});
