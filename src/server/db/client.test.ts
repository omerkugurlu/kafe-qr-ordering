import { afterEach, describe, expect, it } from "vitest";
import { createDatabaseClient } from "./client.js";

const openClients: Array<{ close: () => void; closed: boolean }> = [];

afterEach(() => {
    for (const client of openClients.splice(0)) {
        if (!client.closed) client.close();
    }
});

describe("database driver selection", () => {
    it("uses the local SQLite driver for file URLs", async () => {
        const database = await createDatabaseClient({
            nodeEnv: "development",
            url: "file::memory:",
        });
        openClients.push(database);

        const result = await database.execute("SELECT 1 AS value");
        expect(result.rows[0]?.value).toBe(1);
    });

    it("creates the Turso serverless client for remote URLs", async () => {
        const database = await createDatabaseClient({
            nodeEnv: "production",
            url: "libsql://example.turso.io",
            authToken: "test-token-not-used-for-network-access",
        });
        openClients.push(database);

        expect(database.closed).toBe(false);
    });

    it("requires remote Turso credentials in production", async () => {
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
        await expect(
            createDatabaseClient({
                nodeEnv: "development",
                url: "http://example.test/database",
                authToken: "test-token",
            }),
        ).rejects.toThrow("libsql:// or https://");
    });
});
