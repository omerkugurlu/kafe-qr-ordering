export const subscriptionFeatureLabels = {
    customer_accounts: "Müşteri girişi ve müşteri kayıtları",
    coupons: "Kupon sistemi",
    loyalty: "Sadakat ve ödül sistemi",
    reports: "Raporlar ve dışa aktarma",
    announcements: "Şube duyuruları",
} as const;

export type SubscriptionFeature = keyof typeof subscriptionFeatureLabels;

export const subscriptionFeatureKeys = Object.keys(
    subscriptionFeatureLabels,
) as SubscriptionFeature[];

export function defaultSubscriptionFeatures(
    enabled: boolean,
): Record<SubscriptionFeature, boolean> {
    return Object.fromEntries(
        subscriptionFeatureKeys.map((key) => [key, enabled]),
    ) as Record<SubscriptionFeature, boolean>;
}

export function parseSubscriptionFeatures(
    value: unknown,
): Record<SubscriptionFeature, boolean> {
    let parsed: unknown = value;
    if (typeof value === "string") {
        try {
            parsed = JSON.parse(value);
        } catch {
            parsed = null;
        }
    }

    if (typeof parsed !== "object" || parsed === null || Array.isArray(parsed))
        return defaultSubscriptionFeatures(false);

    const source = parsed as Record<string, unknown>;
    return Object.fromEntries(
        subscriptionFeatureKeys.map((key) => [key, source[key] === true]),
    ) as Record<SubscriptionFeature, boolean>;
}
