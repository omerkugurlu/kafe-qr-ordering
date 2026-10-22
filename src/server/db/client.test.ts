import { afterEach, describe, expect, it, vi } from "vitest";

const openClients: Array<{ close: () => void; closed: boolean }> = [];

afterEach(() => {
    for (const client of openClients.splice(0)) {
        if (!client.closed) client.close();
    }
    vi.doUnmock("@libsql/client");
    vi.resetModules();
});

async function loadCreateDatabaseClient() {
    return (await import("./client.js")).createDatabaseClient;
}

describe("database driver selection", () => {
    it("uses the local SQLite driver for file URLs", async () => {
        const createDatabaseClient = await loadCreateDatabaseClient();
        const database = await createDatabaseClient({
            nodeEnv: "development",
            url: "file::memory:",
        });
        openClients.push(database);

        const result = await database.execute("SELECT 1 AS value");
        expect(result.rows[0]?.value).toBe(1);
    });

    it("creates the Turso serverless client for remote URLs", async () => {
        const createDatabaseClient = await loadCreateDatabaseClient();
        const database = await createDatabaseClient({
            nodeEnv: "production",
            url: "libsql://example.turso.io",
            authToken: "test-token-not-used-for-network-access",
        });
        openClients.push(database);

        expect(database.closed).toBe(false);
    });

    it("does not load the native local driver for remote Turso connections", async () => {
        vi.doMock("@libsql/client", () => {
            throw new Error("The native local SQLite driver was loaded.");
        });

        const createDatabaseClient = await loadCreateDatabaseClient();
        const database = await createDatabaseClient({
            nodeEnv: "production",
            url: "libsql://example.turso.io",
            authToken: "test-token-not-used-for-network-access",
        });
        openClients.push(database);

        expect(database.closed).toBe(false);
    });

    it("requires remote Turso credentials in production", async () => {
        const createDatabaseClient = await loadCreateDatabaseClient();
        await expect(
            createDatabaseClient({ nodeEnv: "production" }),
        ).rejects.toThrow("TURSO_DATABASE_URL is required");

        await expect(
            createDatabaseClient({
                nodeEnv: "production",
                url: "libsql://example.turso.io",
            }),
        ).rejects.toThrow("TURSO_AUTH_TOKEN is required");

        await expect(
            createDatabaseClient({
                nodeEnv: "production",
                url: "file:database/turso/local.db",
            }),
        ).rejects.toThrow("remote Turso database");
    });

    it("rejects non-Turso remote database schemes", async () => {
        const createDatabaseClient = await loadCreateDatabaseClient();
        await expect(
            createDatabaseClient({
                nodeEnv: "development",
                url: "http://example.test/database",
                authToken: "test-token",
            }),
        ).rejects.toThrow("libsql:// or https://");
    });
});
