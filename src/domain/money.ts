const DECIMAL_AMOUNT_PATTERN =
    /^(?<sign>-?)(?<whole>\d+(?:\.\d{3})*|\d+)(?:[,.](?<fraction>\d+))?$/;
const PERCENT_PATTERN = /^(?<whole>\d+)(?:[.,](?<fraction>\d{1,2}))?$/;

function assertSafeInteger(value: number, label: string): void {
    if (!Number.isSafeInteger(value)) {
        throw new RangeError(`${label} güvenli tam sayı aralığında olmalıdır.`);
    }
}

export function toCents(value: string): number {
    const normalizedValue = value
        .trim()
        .replace(/^₺\s*/, "")
        .replace(/\s*₺$/, "")
        .replace(/\s/g, "");
    const match = DECIMAL_AMOUNT_PATTERN.exec(normalizedValue);

    if (!match?.groups) {
        throw new TypeError("Geçerli bir tutar girin.");
    }

    const wholePart = match.groups.whole.replaceAll(".", "");
    const fractionPart = match.groups.fraction ?? "";

    if (fractionPart.length > 2) {
        throw new TypeError("En fazla iki ondalık basamak kullanın.");
    }

    const absoluteCents =
        BigInt(wholePart) * 100n + BigInt(fractionPart.padEnd(2, "0") || "0");
    const signedCents =
        match.groups.sign === "-" ? -absoluteCents : absoluteCents;
    const cents = Number(signedCents);

    assertSafeInteger(cents, "Tutar");

    return cents;
}

export function toDecimal(cents: number): string {
    assertSafeInteger(cents, "Kuruş");

    const amount = BigInt(cents);
    const isNegative = amount < 0n;
    const absoluteAmount = isNegative ? -amount : amount;
    const wholePart = absoluteAmount / 100n;
    const fractionPart = (absoluteAmount % 100n).toString().padStart(2, "0");

    return `${isNegative ? "-" : ""}${wholePart}.${fractionPart}`;
}

export function formatCurrency(cents: number): string {
    assertSafeInteger(cents, "Kuruş");

    const amount = BigInt(cents);
    const isNegative = amount < 0n;
    const absoluteAmount = isNegative ? -amount : amount;
    const wholePart = absoluteAmount / 100n;
    const fractionPart = (absoluteAmount % 100n).toString().padStart(2, "0");
    const formattedWholePart = new Intl.NumberFormat("tr-TR", {
        maximumFractionDigits: 0,
        useGrouping: true,
    }).format(Number(wholePart));

    return `${isNegative ? "-" : ""}₺${formattedWholePart},${fractionPart}`;
}

export function percentageDiscountCents(
    amountCents: number,
    percentage: string,
): number {
    assertSafeInteger(amountCents, "İndirim tabanı");

    if (amountCents < 0) {
        throw new RangeError("İndirim tabanı negatif olamaz.");
    }

    const normalizedPercentage = percentage.trim();
    const match = PERCENT_PATTERN.exec(normalizedPercentage);

    if (!match?.groups) {
        throw new TypeError("Geçerli bir indirim yüzdesi girin.");
    }

    const basisPoints =
        BigInt(match.groups.whole) * 100n +
        BigInt((match.groups.fraction ?? "").padEnd(2, "0") || "0");

    if (basisPoints > 10_000n) {
        throw new RangeError("İndirim yüzde 100 değerini aşamaz.");
    }

    const discountCents =
        (BigInt(amountCents) * basisPoints + 5_000n) / 10_000n;
    const result = Number(discountCents);

    assertSafeInteger(result, "İndirim");

    return result;
}
