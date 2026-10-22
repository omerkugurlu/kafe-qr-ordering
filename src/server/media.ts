import { mkdir, readFile, writeFile } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { getStore } from "@netlify/blobs";
import { currentUser, requireCsrf, requireRole } from "./auth.js";
import { ApiError, checkSameOrigin, type ApiEnvironment } from "./http.js";

const MEDIA_ROOT = resolve(process.cwd(), ".local-media");
const KEY_PATTERN = /^cafe\/\d+\/[a-f0-9]{32}\.(?:jpg|png|webp)$/;
const MAX_BYTES = 4 * 1024 * 1024;

function copyBuffer(bytes: Uint8Array): ArrayBuffer {
    const copy = new Uint8Array(new ArrayBuffer(bytes.byteLength));
    copy.set(bytes);
    return copy.buffer;
}

function detectImage(
    bytes: Uint8Array,
): { extension: "jpg" | "png" | "webp"; contentType: string } | null {
    if (
        bytes.length >= 3 &&
        bytes[0] === 0xff &&
        bytes[1] === 0xd8 &&
        bytes[2] === 0xff
    )
        return { extension: "jpg", contentType: "image/jpeg" };
    if (
        bytes.length >= 8 &&
        bytes[0] === 0x89 &&
        bytes[1] === 0x50 &&
        bytes[2] === 0x4e &&
        bytes[3] === 0x47 &&
        bytes[4] === 0x0d &&
        bytes[5] === 0x0a &&
        bytes[6] === 0x1a &&
        bytes[7] === 0x0a
    )
        return { extension: "png", contentType: "image/png" };
    if (
        bytes.length >= 12 &&
        String.fromCharCode(...bytes.slice(0, 4)) === "RIFF" &&
        String.fromCharCode(...bytes.slice(8, 12)) === "WEBP"
    )
        return { extension: "webp", contentType: "image/webp" };
    return null;
}

function blobStore() {
    return getStore({
        name: "skykite-menu-media",
        consistency: "strong",
        region: "eu-central-1",
    });
}

async function saveMedia(key: string, bytes: Uint8Array, contentType: string) {
    if (process.env.NETLIFY === "true" || process.env.NETLIFY === "dev") {
        await blobStore().set(
            key,
            new Blob([copyBuffer(bytes)], { type: contentType }),
            {
                metadata: { contentType },
            },
        );
        return;
    }
    const file = resolve(MEDIA_ROOT, key);
    await mkdir(dirname(file), { recursive: true });
    await writeFile(file, bytes);
}

async function readMedia(
    key: string,
): Promise<{ bytes: ArrayBuffer; contentType: string } | null> {
    if (!KEY_PATTERN.test(key)) return null;
    if (process.env.NETLIFY === "true" || process.env.NETLIFY === "dev") {
        const entry = await blobStore().getWithMetadata(key, {
            type: "arrayBuffer",
            consistency: "strong",
        });
        if (!entry?.data) return null;
        return {
            bytes: entry.data,
            contentType: String(
                entry.metadata.contentType ?? "application/octet-stream",
            ),
        };
    }
    try {
        const bytes = await readFile(resolve(MEDIA_ROOT, key));
        const detected = detectImage(bytes);
        return detected
            ? { bytes: copyBuffer(bytes), contentType: detected.contentType }
            : null;
    } catch {
        return null;
    }
}

export async function handleMediaRequest(
    request: Request,
    path: string,
    environment: ApiEnvironment,
): Promise<Response | null> {
    const readMatch = /^\/api\/media\/(.+)$/.exec(path);
    if (request.method === "GET" && readMatch) {
        const key = decodeURIComponent(readMatch[1] ?? "");
        const media = await readMedia(key);
        if (!media)
            throw new ApiError(404, "Görsel bulunamadı.", "media_not_found");
        return new Response(media.bytes, {
            headers: {
                "content-type": media.contentType,
                "cache-control": "public, max-age=31536000, immutable",
                "x-content-type-options": "nosniff",
            },
        });
    }
    if (request.method !== "POST" || path !== "/api/manage/media") return null;
    const user = await currentUser(request, environment);
    if (!user)
        throw new ApiError(
            401,
            "Bu işlem için giriş yapın.",
            "authentication_required",
        );
    requireRole(user, ["platform_admin", "cafe_owner", "cafe_manager"]);
    checkSameOrigin(request);
    requireCsrf(request, user);
    const length = Number(request.headers.get("content-length") ?? 0);
    if (length > MAX_BYTES)
        throw new ApiError(
            413,
            "Görsel en fazla 4 MB olabilir.",
            "media_too_large",
        );
    const bytes = new Uint8Array(await request.arrayBuffer());
    if (bytes.length === 0 || bytes.length > MAX_BYTES)
        throw new ApiError(
            413,
            "Görsel boş veya 4 MB sınırını aşıyor.",
            "media_too_large",
        );
    const detected = detectImage(bytes);
    if (!detected)
        throw new ApiError(
            415,
            "Yalnızca geçerli JPG, PNG veya WebP görseli yükleyin.",
            "unsupported_media_type",
        );
    const cafeId = user.role === "platform_admin" ? 0 : user.cafeId;
    if (!cafeId)
        throw new ApiError(
            403,
            "Bu hesap bir kafeye bağlı değil.",
            "forbidden",
        );
    const key = `cafe/${cafeId}/${crypto.randomUUID().replaceAll("-", "")}.${detected.extension}`;
    await saveMedia(key, bytes, detected.contentType);
    return Response.json(
        {
            url: `/api/media/${key}`,
            contentType: detected.contentType,
            size: bytes.length,
        },
        { status: 201, headers: { "cache-control": "no-store" } },
    );
}
