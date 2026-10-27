import { Download, LoaderCircle } from "lucide-react";
import { useCallback, useEffect, useState, type FormEvent } from "react";
import { formatCurrency } from "../domain/money.js";
import { apiRequest } from "../lib/api.js";
import { errorMessage, LoadingCard } from "./cafeFeatureUtils.js";

interface ReportData {
    from: string;
    to: string;
    summary: {
        paidOrders: number;
        revenueCents: number;
        averageOrderCents: number;
        cancelledOrders: number;
    };
    daily: { day: string; paidOrders: number; revenueCents: number }[];
    topProducts: { name: string; quantity: number; revenueCents: number }[];
}

function localDate(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function csvCell(value: string | number): string {
    return `"${String(value).replaceAll('"', '""')}"`;
}

export function CafeReportsPanel() {
    const [from, setFrom] = useState(() => {
        const date = new Date();
        date.setDate(date.getDate() - 29);
        return localDate(date);
    });
    const [to, setTo] = useState(() => localDate(new Date()));
    const [report, setReport] = useState<ReportData | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    const refresh = useCallback(async () => {
        setLoading(true);
        try {
            const query = new URLSearchParams({ from, to });
            setReport(
                await apiRequest<ReportData>(
                    `/api/manage/reports?${query.toString()}`,
                ),
            );
            setError("");
        } catch (reason) {
            setError(errorMessage(reason));
        } finally {
            setLoading(false);
        }
    }, [from, to]);

    useEffect(() => {
        void refresh();
    }, [refresh]);

    function exportReport() {
        if (!report) return;
        const rows = [
            ["Tarih", "Ödenen sipariş", "Tahsilat (kuruş)"],
            ...report.daily.map((item) => [
                item.day,
                item.paidOrders,
                item.revenueCents,
            ]),
        ];
        const contents = `\uFEFF${rows.map((row) => row.map(csvCell).join(",")).join("\r\n")}`;
        const url = URL.createObjectURL(
            new Blob([contents], { type: "text/csv;charset=utf-8" }),
        );
        const anchor = document.createElement("a");
        anchor.href = url;
        anchor.download = `rapor-${report.from}-${report.to}.csv`;
        anchor.click();
        URL.revokeObjectURL(url);
    }

    return (
        <section className="mt-5 space-y-4">
            {(error || report) && (
                <header className="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <p className="eyebrow">Satış özeti</p>
                        <h1 className="mt-1 font-display text-2xl font-semibold">
                            Raporlar ve dışa aktarma
                        </h1>
                    </div>
                    <button
                        className="btn-secondary"
                        disabled={!report || loading}
                        onClick={exportReport}
                        type="button"
                    >
                        <Download className="size-4" /> CSV indir
                    </button>
                </header>
            )}
            {error && (
                <div aria-live="polite" className="rounded-2xl bg-danger-soft p-3 text-sm font-semibold text-danger">
                    {error}
                </div>
            )}
            <form
                className="card flex flex-wrap items-end gap-3 p-4"
                onSubmit={(event: FormEvent<HTMLFormElement>) => {
                    event.preventDefault();
                    void refresh();
                }}
            >
                <label>
                    <span className="label">Başlangıç</span>
                    <input
                        className="input"
                        onChange={(event) => setFrom(event.target.value)}
                        required
                        type="date"
                        value={from}
                    />
                </label>
                <label>
                    <span className="label">Bitiş</span>
                    <input
                        className="input"
                        onChange={(event) => setTo(event.target.value)}
                        required
                        type="date"
                        value={to}
                    />
                </label>
                <button className="btn-primary" disabled={loading} type="submit">
                    {loading ? <LoaderCircle className="size-4 animate-spin" /> : null}
                    Raporu göster
                </button>
            </form>
            {loading && !report ? (
                <LoadingCard />
            ) : report ? (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        <ReportMetric label="Tahsilat" value={formatCurrency(report.summary.revenueCents)} />
                        <ReportMetric label="Ödenen sipariş" value={String(report.summary.paidOrders)} />
                        <ReportMetric label="Ortalama sipariş" value={formatCurrency(report.summary.averageOrderCents)} />
                        <ReportMetric label="İptal edilen" value={String(report.summary.cancelledOrders)} />
                    </div>
                    <div className="grid gap-4 xl:grid-cols-2">
                        <section className="card p-4">
                            <h2 className="font-display text-xl font-semibold">Günlük tahsilat</h2>
                            {report.daily.length ? (
                                <div className="mt-3 overflow-x-auto">
                                    <table className="w-full text-left text-sm">
                                        <thead><tr className="border-b border-line text-muted"><th className="py-2">Tarih</th><th className="py-2">Sipariş</th><th className="py-2 text-right">Tahsilat</th></tr></thead>
                                        <tbody>{report.daily.map((item) => <tr className="border-b border-line/70" key={item.day}><td className="py-2">{new Intl.DateTimeFormat("tr-TR", { dateStyle: "medium", timeZone: "UTC" }).format(new Date(`${item.day}T12:00:00Z`))}</td><td className="py-2">{item.paidOrders}</td><td className="py-2 text-right font-semibold">{formatCurrency(item.revenueCents)}</td></tr>)}</tbody>
                                    </table>
                                </div>
                            ) : <p className="mt-3 text-sm text-muted">Bu tarih aralığında ödenen sipariş yok.</p>}
                        </section>
                        <section className="card p-4">
                            <h2 className="font-display text-xl font-semibold">En çok satılan ürünler</h2>
                            {report.topProducts.length ? <div className="mt-3 space-y-2">{report.topProducts.map((item) => <div className="flex items-center justify-between gap-3 border-b border-line/70 py-2 text-sm" key={item.name}><span className="font-medium">{item.name} <span className="text-muted">× {item.quantity}</span></span><span className="font-semibold">{formatCurrency(item.revenueCents)}</span></div>)}</div> : <p className="mt-3 text-sm text-muted">Bu tarih aralığında ürün satışı yok.</p>}
                        </section>
                    </div>
                </>
            ) : !error ? (
                <LoadingCard />
            ) : null}
        </section>
    );
}

function ReportMetric({ label, value }: { label: string; value: string }) {
    return (
        <article className="card p-4">
            <p className="eyebrow">{label}</p>
            <p className="mt-1 font-display text-xl font-semibold">{value}</p>
        </article>
    );
}
