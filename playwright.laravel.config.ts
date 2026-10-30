import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/e2e/laravel',
    testMatch: '**/*.laravel.e2e.ts',
    fullyParallel: false,
    workers: 1,
    reporter: 'list',
    timeout: 90_000,
    expect: { timeout: 20_000 },
    use: {
        ...devices['Desktop Chrome'],
        baseURL: process.env.LARAVEL_E2E_BASE_URL ?? 'http://127.0.0.1:4817',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'retain-on-failure',
    },
});
