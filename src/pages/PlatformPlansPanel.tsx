import { LoaderCircle, Save } from "lucide-react";
import {
    subscriptionFeatureLabels,
    subscriptionFeatureKeys,
} from "../domain/subscriptions.js";
import type { Plan, PlanDraft } from "./platformSubscriptionTypes.js";

export function PlatformPlansPanel({
    plans,
    drafts,
    savingPlanId,
    onDraftChange,
    onSave,
}: {
    plans: Plan[];
    drafts: Record<number, PlanDraft>;
    savingPlanId: number | null;
    onDraftChange: (planId: number, update: Partial<PlanDraft>) => void;
    onSave: (plan: Plan) => void;
}) {
    return (
        <>
            <header>
                <p className="eyebrow">Satış ve kullanım sınırları</p>
                <h1 className="mt-1 font-display text-2xl font-semibold">
                    Yıllık paketleri düzenle
                </h1>
                <p className="mt-1 text-sm text-muted">
                    Fiyat, masa/şube sınırı ve paket özellikleri yeni talep ve
                    dönemlere uygulanır. Başlamış dönemlerin kayıtları değişmez.
                </p>
            </header>
            <div className="grid gap-4 xl:grid-cols-3">
                {plans.map((plan) => {
                    const draft = drafts[plan.id];
                    if (!draft) return null;
                    return (
                        <article className="card p-4 sm:p-5" key={plan.id}>
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <p className="eyebrow">{plan.code}</p>
                                    <h2 className="mt-1 font-display text-xl font-semibold">
                                        {plan.name}
                                    </h2>
                                </div>
                                <span
                                    className={
                                        draft.isActive
                                            ? "badge-paid"
                                            : "badge-cancelled"
                                    }
                                >
                                    {draft.isActive ? "Satışta" : "Kapalı"}
                                </span>
                            </div>
                            <label className="mt-4 block">
                                <span className="label">Paket adı</span>
                                <input
                                    className="input"
                                    maxLength={80}
                                    minLength={2}
                                    onChange={(event) =>
                                        onDraftChange(plan.id, {
                                            name: event.target.value,
                                        })
                                    }
                                    value={draft.name}
                                />
                            </label>
                            <label className="mt-3 block">
                                <span className="label">Yıllık fiyat (₺)</span>
                                <input
                                    className="input"
                                    inputMode="decimal"
                                    onChange={(event) =>
                                        onDraftChange(plan.id, {
                                            annualPrice: event.target.value,
                                        })
                                    }
                                    value={draft.annualPrice}
                                />
                            </label>
                            <div className="mt-3 grid grid-cols-2 gap-2">
                                <label>
                                    <span className="label">Masa sınırı</span>
                                    <input
                                        className="input"
                                        max="5000"
                                        min="1"
                                        onChange={(event) =>
                                            onDraftChange(plan.id, {
                                                maxTables: event.target.value,
                                            })
                                        }
                                        type="number"
                                        value={draft.maxTables}
                                    />
                                </label>
                                <label>
                                    <span className="label">Şube sınırı</span>
                                    <input
                                        className="input"
                                        disabled={draft.unlimitedBranches}
                                        max="500"
                                        min="1"
                                        onChange={(event) =>
                                            onDraftChange(plan.id, {
                                                maxBranches: event.target.value,
                                            })
                                        }
                                        type="number"
                                        value={draft.maxBranches}
                                    />
                                </label>
                            </div>
                            <label className="mt-3 flex items-center gap-2 text-sm">
                                <input
                                    checked={draft.unlimitedBranches}
                                    onChange={(event) =>
                                        onDraftChange(plan.id, {
                                            unlimitedBranches:
                                                event.target.checked,
                                        })
                                    }
                                    type="checkbox"
                                />
                                Sınırsız şube
                            </label>
                            <fieldset className="mt-4 space-y-2 border-t border-line pt-4">
                                <legend className="label">Dahil özellikler</legend>
                                {subscriptionFeatureKeys.map((key) => (
                                    <label
                                        className="flex items-start gap-2 text-sm"
                                        key={key}
                                    >
                                        <input
                                            checked={draft.features[key]}
                                            onChange={(event) =>
                                                onDraftChange(plan.id, {
                                                    features: {
                                                        ...draft.features,
                                                        [key]: event.target.checked,
                                                    },
                                                })
                                            }
                                            type="checkbox"
                                        />
                                        <span>{subscriptionFeatureLabels[key]}</span>
                                    </label>
                                ))}
                            </fieldset>
                            <label className="mt-4 flex items-center gap-2 text-sm font-semibold">
                                <input
                                    checked={draft.isActive}
                                    onChange={(event) =>
                                        onDraftChange(plan.id, {
                                            isActive: event.target.checked,
                                        })
                                    }
                                    type="checkbox"
                                />
                                Paket talebe açık
                            </label>
                            <button
                                className="btn-primary mt-4 w-full"
                                disabled={savingPlanId !== null}
                                onClick={() => onSave(plan)}
                                type="button"
                            >
                                {savingPlanId === plan.id ? (
                                    <LoaderCircle className="size-4 animate-spin" />
                                ) : (
                                    <Save className="size-4" />
                                )}
                                Paketi kaydet
                            </button>
                        </article>
                    );
                })}
            </div>
        </>
    );
}
