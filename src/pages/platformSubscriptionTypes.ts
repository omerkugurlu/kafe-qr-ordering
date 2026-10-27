import { subscriptionFeatureLabels } from "../domain/subscriptions.js";
import { toDecimal } from "../domain/money.js";

export type FeatureMap = Record<keyof typeof subscriptionFeatureLabels, boolean>;
export type PlatformSubscriptionSection = "plans" | "requests" | "cafes";

export interface Plan {
    id: number;
    code: string;
    name: string;
    annualPriceCents: number;
    maxTables: number;
    maxBranches: number | null;
    features: FeatureMap;
    isActive: boolean;
    sortOrder: number;
}

export interface SubscriptionRequest {
    id: number;
    cafeId: number;
    cafeName: string;
    planId: number;
    planName: string;
    annualPriceCents: number;
    maxTables: number;
    maxBranches: number | null;
    features: FeatureMap;
    status: string;
    requestedAt: string;
    requester: { name: string; email: string } | null;
}

export interface CafeSubscription {
    id: number;
    cafeId: number;
    planId: number;
    planName: string;
    startsAt: string;
    endsAt: string;
    annualPriceCents: number;
}

export interface CafeRecord {
    id: number;
    name: string;
    status: string;
    subscription: CafeSubscription | null;
    subscriptionState: "current" | "scheduled" | "expired" | null;
    daysRemaining: number;
    pendingRequest: { id: number; planName: string; requestedAt: string } | null;
}

export interface PlatformSubscriptionsData {
    plans: Plan[];
    requests: SubscriptionRequest[];
    cafes: CafeRecord[];
}

export type PlanDraft = {
    name: string;
    annualPrice: string;
    maxTables: string;
    maxBranches: string;
    unlimitedBranches: boolean;
    isActive: boolean;
    features: FeatureMap;
};

export type CafeDraft = {
    planId: string;
    endsOn: string;
    reason: string;
};

export function toPlanDraft(plan: Plan): PlanDraft {
    return {
        name: plan.name,
        annualPrice: toDecimal(plan.annualPriceCents),
        maxTables: String(plan.maxTables),
        maxBranches: plan.maxBranches === null ? "" : String(plan.maxBranches),
        unlimitedBranches: plan.maxBranches === null,
        isActive: plan.isActive,
        features: { ...plan.features },
    };
}

export function dateInputValue(value?: string | null): string {
    if (value) return value.slice(0, 10);
    const date = new Date();
    date.setFullYear(date.getFullYear() + 1);
    return date.toISOString().slice(0, 10);
}

export function formatDate(value: string): string {
    return new Intl.DateTimeFormat("tr-TR", {
        dateStyle: "medium",
        timeZone: "Europe/Istanbul",
    }).format(new Date(value));
}
