import { resolve } from "node:path";
import { defineConfig } from "@playwright/test";

const databasePath = resolve(process.cwd(), ".local-e2e.db").replaceAll(
    "\\",
    "/",
);

export default defineConfig({
    testDir: "./tests/e2e",
    testMatch: "**/*.e2e.ts",
    fullyParallel: false,
    workers: 1,
    reporter: "list",
    timeout: 45_000,
    expect: { timeout: 10_000 },
    use: {
        baseURL: "http://127.0.0.1:4173",
        trace: "retain-on-failure",
        screenshot: "only-on-failure",
    },
    webServer: {
        command: "npm run dev -- --host 127.0.0.1 --port 4173",
        url: "http://127.0.0.1:4173",
        reuseExistingServer: false,
        timeout: 120_000,
        env: {
            NODE_ENV: "test",
            TURSO_DATABASE_URL: `file:${databasePath}`,
        },
    },
});
