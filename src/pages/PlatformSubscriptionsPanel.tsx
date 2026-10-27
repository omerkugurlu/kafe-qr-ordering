import { LoaderCircle } from "lucide-react";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { toCents } from "../domain/money.js";
import { ApiClientError, apiPatch, apiPost, apiRequest } from "../lib/api.js";
import { PlatformCafeSubscriptionsPanel } from "./PlatformCafeSubscriptionsPanel.js";
import { PlatformPlansPanel } from "./PlatformPlansPanel.js";
import { PlatformRequestsPanel } from "./PlatformRequestsPanel.js";
import {
    dateInputValue,
    toPlanDraft,
    type CafeDraft,
    type CafeRecord,
    type Plan,
    type PlanDraft,
    type PlatformSubscriptionSection,
    type PlatformSubscriptionsData,
    type SubscriptionRequest,
} from "./platformSubscriptionTypes.js";

function errorMessage(error: unknown): string {
    return error instanceof ApiClientError
        ? error.message
        : "İşlem tamamlanamadı. Tekrar deneyin.";
}

export function PlatformSubscriptionsPanel({
    section,
    csrfToken,
}: {
    section: PlatformSubscriptionSection;
    csrfToken: string | null;
}) {
    const [data, setData] = useState<PlatformSubscriptionsData | null>(null);
    const [drafts, setDrafts] = useState<Record<number, PlanDraft>>({});
    const [cafeDrafts, setCafeDrafts] = useState<Record<number, CafeDraft>>({});
    const [paymentConfirmed, setPaymentConfirmed] = useState<
        Record<number, boolean>
    >({});
    const [rejectionNotes, setRejectionNotes] = useState<
        Record<number, string>
    >({});
    const [busy, setBusy] = useState(false);
    const [savingPlanId, setSavingPlanId] = useState<number | null>(null);
    const [savingCafeId, setSavingCafeId] = useState<number | null>(null);
    const [error, setError] = useState("");
    const [notice, setNotice] = useState("");

    const refresh = useCallback(async () => {
        try {
            const [planData, requestData, cafeData] = await Promise.all([
                apiRequest<{ plans: Plan[] }>(
                    "/api/platform/subscription-plans",
                ),
                apiRequest<{ requests: SubscriptionRequest[] }>(
                    "/api/platform/subscription-requests",
                ),
                apiRequest<{ cafes: CafeRecord[] }>(
                    "/api/platform/subscription-cafes",
                ),
            ]);
            const next: PlatformSubscriptionsData = {
                plans: planData.plans,
                requests: requestData.requests,
                cafes: cafeData.cafes,
            };
            setData(next);
            setDrafts(
                Object.fromEntries(
                    next.plans.map((plan) => [plan.id, toPlanDraft(plan)]),
                ),
            );
            setCafeDrafts(
                Object.fromEntries(
                    next.cafes.map((cafe) => [
                        cafe.id,
                        {
                            planId: String(
                                cafe.subscription?.planId ??
                                    next.plans.find((plan) => plan.isActive)
                                        ?.id ??
                                    "",
                            ),
                            endsOn:
                                cafe.subscriptionState === "expired"
                                    ? dateInputValue()
                                    : dateInputValue(cafe.subscription?.endsAt),
                            reason: "",
                        },
                    ]),
                ),
            );
            setError("");
        } catch (reason) {
            setError(errorMessage(reason));
        }
    }, []);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    async function savePlan(plan: Plan) {
        const draft = drafts[plan.id];
        if (!draft) return;
        setSavingPlanId(plan.id);
        setError("");
        setNotice("");
        try {
            await apiPatch(
                `/api/platform/subscription-plans/${plan.id}`,
                {
                    name: draft.name,
                    annualPriceCents: toCents(draft.annualPrice),
                    maxTables: Number(draft.maxTables),
                    maxBranches: draft.unlimitedBranches
                        ? null
                        : Number(draft.maxBranches),
                    features: draft.features,
                    isActive: draft.isActive,
                },
                csrfToken,
            );
            setNotice(
                `${plan.name} paketi kaydedildi. Eski abonelik dönemlerinin fiyat ve özellik kayıtları korunuyor.`,
            );
            await refresh();
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setSavingPlanId(null);
        }
    }

    async function approveRequest(item: SubscriptionRequest) {
        if (!paymentConfirmed[item.id]) {
            setError("Talebi onaylamadan önce ödemenin alındığını doğrulayın.");
            return;
        }
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await apiPost(
                `/api/platform/subscription-requests/${item.id}/approve`,
                { paymentReceived: true },
                csrfToken,
            );
            setNotice(
                `${item.cafeName} için ${item.planName} paketi etkinleştirildi.`,
            );
            await refresh();
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setBusy(false);
        }
    }

    async function rejectRequest(item: SubscriptionRequest) {
        const note = rejectionNotes[item.id]?.trim() ?? "";
        if (note.length < 3) {
            setError("Talebi reddetmek için en az 3 karakterlik bir açıklama yazın.");
            return;
        }
        setBusy(true);
        setError("");
        setNotice("");
        try {
            await apiPost(
                `/api/platform/subscription-requests/${item.id}/reject`,
                { note },
                csrfToken,
            );
            setNotice(`${item.cafeName} paket talebi reddedildi.`);
            await refresh();
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setBusy(false);
        }
    }

    async function saveCafeSubscription(
        event: FormEvent<HTMLFormElement>,
        cafe: CafeRecord,
    ) {
        event.preventDefault();
        const draft = cafeDrafts[cafe.id];
        if (!draft) return;
        setSavingCafeId(cafe.id);
        setError("");
        setNotice("");
        try {
            await apiPatch(
                `/api/platform/cafes/${cafe.id}/subscription`,
                {
                    subscriptionId: cafe.subscription?.id ?? null,
                    planId: Number(draft.planId),
                    endsOn: draft.endsOn,
                    reason: draft.reason,
                },
                csrfToken,
            );
            setNotice(`${cafe.name} paket ve bitiş tarihi güncellendi.`);
            await refresh();
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setSavingCafeId(null);
        }
    }

    function updatePlanDraft(planId: number, update: Partial<PlanDraft>) {
        setDrafts((current) => ({
            ...current,
            [planId]: { ...current[planId], ...update } as PlanDraft,
        }));
    }

    function updateCafeDraft(cafeId: number, update: Partial<CafeDraft>) {
        setCafeDrafts((current) => ({
            ...current,
            [cafeId]: { ...current[cafeId], ...update } as CafeDraft,
        }));
    }

    if (!data)
        return (
            <section className="card mt-5 flex items-center gap-3 p-5 text-sm text-muted">
                <LoaderCircle className="size-4 animate-spin" /> Paket yönetimi
                yükleniyor…
                {error && <span className="text-danger">{error}</span>}
            </section>
        );

    return (
        <section className="mt-5 space-y-4">
            {(error || notice) && (
                <div
                    aria-live="polite"
                    className={`rounded-2xl p-3 text-sm font-semibold ${error ? "bg-danger-soft text-danger" : "bg-success-soft text-success"}`}
                >
                    {error || notice}
                </div>
            )}
            {section === "plans" && (
                <PlatformPlansPanel
                    drafts={drafts}
                    onDraftChange={updatePlanDraft}
                    onSave={(plan) => void savePlan(plan)}
                    plans={data.plans}
                    savingPlanId={savingPlanId}
                />
            )}
            {section === "requests" && (
                <PlatformRequestsPanel
                    busy={busy}
                    onApprove={(item) => void approveRequest(item)}
                    onNoteChange={(id, value) =>
                        setRejectionNotes((current) => ({
                            ...current,
                            [id]: value,
                        }))
                    }
                    onPaymentChange={(id, value) =>
                        setPaymentConfirmed((current) => ({
                            ...current,
                            [id]: value,
                        }))
                    }
                    onReject={(item) => void rejectRequest(item)}
                    paymentConfirmed={paymentConfirmed}
                    rejectionNotes={rejectionNotes}
                    requests={data.requests}
                />
            )}
            {section === "cafes" && (
                <PlatformCafeSubscriptionsPanel
                    cafes={data.cafes}
                    drafts={cafeDrafts}
                    onDraftChange={updateCafeDraft}
                    onSave={(event, cafe) =>
                        void saveCafeSubscription(event, cafe)
                    }
                    plans={data.plans}
                    savingCafeId={savingCafeId}
                />
            )}
        </section>
    );
}
