export class ApiClientError extends Error {
    constructor(
        message: string,
        readonly status: number,
        readonly code?: string,
    ) {
        super(message);
    }
}

export async function apiRequest<T>(
    path: string,
    options: RequestInit = {},
    csrfToken?: string | null,
): Promise<T> {
    const headers = new Headers(options.headers);
    headers.set("accept", "application/json");
    if (options.body !== undefined && !headers.has("content-type")) {
        headers.set("content-type", "application/json");
    }
    if (csrfToken && options.method && options.method.toUpperCase() !== "GET") {
        headers.set("x-csrf-token", csrfToken);
    }

    const response = await fetch(path, {
        ...options,
        headers,
        credentials: "same-origin",
    });
    const data = (await response.json().catch(() => ({}))) as T & {
        error?: string;
        code?: string;
    };
    if (!response.ok) {
        throw new ApiClientError(
            data.error ?? "İşlem tamamlanamadı.",
            response.status,
            data.code,
        );
    }
    return data;
}

export function apiPost<T>(
    path: string,
    body: unknown,
    csrfToken?: string | null,
): Promise<T> {
    return apiRequest<T>(
        path,
        { method: "POST", body: JSON.stringify(body) },
        csrfToken,
    );
}

export function apiPatch<T>(
    path: string,
    body: unknown,
    csrfToken?: string | null,
): Promise<T> {
    return apiRequest<T>(
        path,
        { method: "PATCH", body: JSON.stringify(body) },
        csrfToken,
    );
}

export function apiUpload<T>(
    path: string,
    file: File,
    csrfToken?: string | null,
): Promise<T> {
    return apiRequest<T>(
        path,
        {
            method: "POST",
            headers: {
                "content-type": file.type || "application/octet-stream",
            },
            body: file,
        },
        csrfToken,
    );
}
