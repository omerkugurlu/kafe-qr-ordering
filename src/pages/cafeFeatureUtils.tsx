import { ApiClientError } from "../lib/api.js";

export interface BranchOption {
    id: number;
    name: string;
}

export function errorMessage(error: unknown): string {
    return error instanceof ApiClientError
        ? error.message
        : "İşlem tamamlanamadı. Bilgileri kontrol edip tekrar deneyin.";
}

export function dateTimeInput(value: string | null): string {
    if (!value) return "";
    const date = new Date(value);
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset());
    return date.toISOString().slice(0, 16);
}

export function dateTimePayload(value: string): string | null {
    return value ? new Date(value).toISOString() : null;
}

export function LoadingCard() {
    return (
        <div className="card flex items-center gap-3 p-5 text-sm text-muted">
            <span className="size-4 animate-spin rounded-full border-2 border-forest/20 border-t-forest" />
            Yükleniyor…
        </div>
    );
}
