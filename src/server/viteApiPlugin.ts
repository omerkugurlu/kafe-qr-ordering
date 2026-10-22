import type { IncomingMessage } from "node:http";
import type { Plugin } from "vite";
import { handleApiRequest } from "./api.js";

export function viteApiPlugin(): Plugin {
    return {
        name: "skykite-local-api",
        configureServer(server) {
            server.middlewares.use(async (request, response, next) => {
                if (!request.url?.startsWith("/api/")) return next();
                try {
                    const body = await readBody(request, 5 * 1024 * 1024);
                    const headers = new Headers();
                    for (const [key, value] of Object.entries(
                        request.headers,
                    )) {
                        if (value)
                            headers.set(
                                key,
                                Array.isArray(value) ? value.join(", ") : value,
                            );
                    }
                    const url = `http://${request.headers.host ?? "127.0.0.1"}${request.url}`;
                    const method = request.method ?? "GET";
                    const apiRequest = new Request(url, {
                        method,
                        headers,
                        ...(method === "GET" || method === "HEAD"
                            ? {}
                            : { body: new Uint8Array(body) }),
                    });
                    const result = await handleApiRequest(apiRequest, {
                        ip: request.socket.remoteAddress,
                    });
                    response.statusCode = result.status;
                    result.headers.forEach((value, key) =>
                        response.setHeader(key, value),
                    );
                    if (result.body)
                        response.end(Buffer.from(await result.arrayBuffer()));
                    else response.end();
                } catch (error) {
                    next(error);
                }
            });
        },
    };
}

async function readBody(
    request: IncomingMessage,
    limit: number,
): Promise<Buffer> {
    const chunks: Buffer[] = [];
    let size = 0;
    for await (const chunk of request) {
        const buffer = Buffer.isBuffer(chunk) ? chunk : Buffer.from(chunk);
        size += buffer.length;
        if (size > limit) throw new Error("Request body is too large.");
        chunks.push(buffer);
    }
    return Buffer.concat(chunks);
}
