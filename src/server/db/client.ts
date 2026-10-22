import { mkdir } from "node:fs/promises";
import { dirname, resolve } from "node:path";
import { createClient, type Client } from "@libsql/client";
import { schemaStatements } from "./schema.js";

let clientPromise: Promise<Client> | undefined;
let initializationPromise: Promise<void> | undefined;
const initializedClients = new WeakSet<Client>();

async function createDatabaseClient(): Promise<Client> {
    const url = process.env.TURSO_DATABASE_URL;
    const authToken = process.env.TURSO_AUTH_TOKEN;

    if (url) {
        return createClient({ url, authToken });
    }

    if (process.env.NODE_ENV === "production") {
        throw new Error("TURSO_DATABASE_URL is required in production.");
    }

    const localFile = resolve(process.cwd(), "database", "turso", "local.db");
    await mkdir(dirname(localFile), { recursive: true });

    return createClient({ url: `file:${localFile}` });
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
