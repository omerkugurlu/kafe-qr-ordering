import { mkdir } from "node:fs/promises";
import { dirname, isAbsolute, resolve } from "node:path";
import type { Client } from "@libsql/client";
import { createClient as createTursoClient } from "@tursodatabase/serverless/compat";
import { schemaStatements } from "./schema.js";

export interface DatabaseConfig {
    nodeEnv?: string;
    url?: string;
    authToken?: string;
}

async function createLocalDatabase(url: string): Promise<Client> {
    const { createClient } = await import("@libsql/client");
    return createClient({ url });
}

let clientPromise: Promise<Client> | undefined;
let initializationPromise: Promise<void> | undefined;
const initializedClients = new WeakSet<Client>();

export async function createDatabaseClient(
    config: DatabaseConfig = {
        nodeEnv: process.env.NODE_ENV,
        url: process.env.TURSO_DATABASE_URL,
        authToken: process.env.TURSO_AUTH_TOKEN,
    },
): Promise<Client> {
    const { nodeEnv, url, authToken } = config;

    if (url?.startsWith("file:")) {
        if (nodeEnv === "production") {
            throw new Error(
                "TURSO_DATABASE_URL must point to a remote Turso database in production.",
            );
        }

        const path = url.slice("file:".length);
        if (path === ":memory:") return createLocalDatabase(url);

        const localFile = isAbsolute(path)
            ? path
            : resolve(process.cwd(), path);
        await mkdir(dirname(localFile), { recursive: true });
        return createLocalDatabase(`file:${localFile}`);
    }

    if (url) {
        let protocol: string;
        try {
            protocol = new URL(url).protocol;
        } catch {
            throw new Error(
                "TURSO_DATABASE_URL must be a valid libsql:// or https:// URL.",
            );
        }
        if (protocol !== "libsql:" && protocol !== "https:") {
            throw new Error(
                "TURSO_DATABASE_URL must use libsql:// or https:// for a remote Turso database.",
            );
        }
        if (!authToken?.trim()) {
            throw new Error(
                "TURSO_AUTH_TOKEN is required for a remote Turso database.",
            );
        }

        // Turso's serverless compatibility layer implements the libSQL Client
        // API used by the app, while local development keeps the file driver.
        return createTursoClient({
            url,
            authToken: authToken.trim(),
        }) as unknown as Client;
    }

    if (nodeEnv === "production") {
        throw new Error("TURSO_DATABASE_URL is required in production.");
    }

    const localFile = resolve(process.cwd(), "database", "turso", "local.db");
    await mkdir(dirname(localFile), { recursive: true });
    return createLocalDatabase(`file:${localFile}`);
}

export async function getDatabase(): Promise<Client> {
    clientPromise ??= createDatabaseClient();
    return clientPromise;
}

export async function initializeDatabase(client?: Client): Promise<Client> {
    const database = client ?? (await getDatabase());

    if (client) {
        if (initializedClients.has(client)) return database;
        for (const statement of schemaStatements) {
            await database.execute(statement);
        }
        initializedClients.add(client);
        return database;
    }

    initializationPromise ??= (async () => {
        for (const statement of schemaStatements) {
            await database.execute(statement);
        }
    })();

    await initializationPromise;
    return database;
}
