import type { Context } from "@netlify/functions";
import { handleAuthRequest } from "./auth.js";
import { handleCustomerRequest } from "./customer.js";
import {
    ApiError,
    errorResponse,
    jsonResponse,
    type ApiContext,
    type ApiEnvironment,
} from "./http.js";
import { handleStaffRequest } from "./staff.js";
import { handleManagementRequest } from "./management.js";
import { handleMediaRequest } from "./media.js";

export async function handleApiRequest(
    request: Request,
    context?: ApiContext | Context,
    overrides: Partial<ApiEnvironment> = {},
): Promise<Response> {
    try {
        const environment: ApiEnvironment = {
            ...overrides,
            context: { ip: context?.ip ?? overrides.context?.ip },
        };
        const path = new URL(request.url).pathname;
        if (!path.startsWith("/api/"))
            throw new ApiError(404, "API adresi bulunamadı.", "not_found");

        const mediaResponse = await handleMediaRequest(
            request,
            path,
            environment,
        );
        if (mediaResponse) return addSecurityHeaders(mediaResponse);

        const authResponse = await handleAuthRequest(
            request,
            path,
            environment,
        );
        if (authResponse) return addSecurityHeaders(authResponse);
        const customerResponse = await handleCustomerRequest(
            request,
            path,
            environment,
        );
        if (customerResponse) return addSecurityHeaders(customerResponse);
        const staffResponse = await handleStaffRequest(
            request,
            path,
            environment,
        );
        if (staffResponse) return addSecurityHeaders(staffResponse);
        const managementResponse = await handleManagementRequest(
            request,
            path,
            environment,
        );
        if (managementResponse) return addSecurityHeaders(managementResponse);
        return jsonResponse(
            { error: "API adresi bulunamadı.", code: "not_found" },
            404,
        );
    } catch (error) {
        return addSecurityHeaders(errorResponse(error));
    }
}

function addSecurityHeaders(response: Response): Response {
    const headers = new Headers(response.headers);
    headers.set("x-content-type-options", "nosniff");
    headers.set("x-frame-options", "DENY");
    headers.set("referrer-policy", "strict-origin-when-cross-origin");
    headers.set(
        "permissions-policy",
        "camera=(), microphone=(), geolocation=()",
    );
    headers.set(
        "cache-control",
        "private, no-store, max-age=0, must-revalidate",
    );
    return new Response(response.body, {
        status: response.status,
        statusText: response.statusText,
        headers,
    });
}
