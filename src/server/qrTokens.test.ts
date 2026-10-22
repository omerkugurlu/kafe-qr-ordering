import { afterEach, describe, expect, it, vi } from "vitest";
import { decryptQrToken, encryptQrToken } from "./qrTokens.js";

afterEach(() => vi.unstubAllEnvs());

describe("QR token encryption", () => {
    it("round-trips tokens with a configured key", () => {
        vi.stubEnv("NODE_ENV", "test");
        vi.stubEnv("TURSO_DATABASE_URL", "file::memory:");
        vi.stubEnv(
            "QR_TOKEN_ENCRYPTION_KEY",
            "test-only-key-with-enough-randomness-2026",
        );

        const encrypted = encryptQrToken("random-table-token");
        expect(encrypted).not.toContain("random-table-token");
        expect(decryptQrToken(encrypted)).toBe("random-table-token");
    });

    it("requires a stable key in production or with a remote database", () => {
        vi.stubEnv("QR_TOKEN_ENCRYPTION_KEY", "");
        vi.stubEnv("NODE_ENV", "production");
        vi.stubEnv("TURSO_DATABASE_URL", "file::memory:");
        expect(() => encryptQrToken("table-token")).toThrow(
            "QR_TOKEN_ENCRYPTION_KEY is required",
        );

        vi.stubEnv("NODE_ENV", "development");
        vi.stubEnv("TURSO_DATABASE_URL", "libsql://example.turso.io");
        expect(() => encryptQrToken("table-token")).toThrow(
            "QR_TOKEN_ENCRYPTION_KEY is required",
        );
    });
});
