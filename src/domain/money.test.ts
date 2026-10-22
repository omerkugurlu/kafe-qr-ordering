import { describe, expect, it } from "vitest";
import {
    formatCurrency,
    percentageDiscountCents,
    toCents,
    toDecimal,
} from "./money.js";

describe("money helpers", () => {
    it.each([
        ["75", 7500],
        ["75.00", 7500],
        ["0.1", 10],
        ["1,25", 125],
        ["1.234,56", 123456],
    ])("parses %s into exact cents", (value, expected) => {
        expect(toCents(value)).toBe(expected);
    });

    it("rejects values with more than two fractional digits", () => {
        expect(() => toCents("12,345")).toThrow("En fazla iki ondalık basamak");
    });

    it("rejects ambiguous or malformed currency input", () => {
        expect(() => toCents("1,234.56")).toThrow("Geçerli bir tutar");
    });

    it("serializes cents as an exact decimal string", () => {
        expect(toDecimal(123456)).toBe("1234.56");
        expect(toDecimal(5)).toBe("0.05");
    });

    it("formats a Turkish lira amount without losing kuruş", () => {
        expect(formatCurrency(123456)).toBe("₺1.234,56");
    });

    it("calculates percentage discounts using integer arithmetic", () => {
        expect(percentageDiscountCents(26000, "10")).toBe(2600);
        expect(percentageDiscountCents(1, "50")).toBe(1);
        expect(percentageDiscountCents(10000, "12.5")).toBe(1250);
    });
});
