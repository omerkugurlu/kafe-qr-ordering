import { randomBytes } from 'node:crypto';
import { spawn, spawnSync } from 'node:child_process';
import {
    closeSync,
    existsSync,
    mkdirSync,
    openSync,
    rmSync,
    unlinkSync,
} from 'node:fs';
import { createServer } from 'node:net';
import { basename, dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const phpBinary =
    process.env.LARAVEL_E2E_PHP ?? 'C:\\xampp\\htdocs\\tools\\php84\\php.exe';
const databaseName = `.local-laravel-e2e-${process.pid}-${randomBytes(6).toString('hex')}.sqlite`;
const databasePath = resolve(projectRoot, databaseName);
const configCacheName = `.local-laravel-e2e-config-${process.pid}-${randomBytes(6).toString('hex')}.php`;
const configCachePath = resolve(projectRoot, configCacheName);
const buildDirectory = `build-laravel-e2e-${process.pid}-${randomBytes(6).toString('hex')}`;
const buildPath = resolve(projectRoot, 'public', buildDirectory);
const base64AppKey = `base64:${randomBytes(32).toString('base64')}`;
const qrToken = randomBytes(32).toString('hex');
const waiterEmail = 'e2e-waiter@example.test';
const waiterPassword = `E2E-${randomBytes(12).toString('hex')}!`;

if (
    basename(databasePath) !== databaseName ||
    dirname(databasePath) !== projectRoot ||
    !/^\.local-laravel-e2e-[0-9]+-[a-f0-9]+\.sqlite$/.test(databaseName)
) {
    throw new Error(
        'Refusing to use a Laravel E2E database path outside the dedicated project test file.',
    );
}

if (
    dirname(buildPath) !== resolve(projectRoot, 'public') ||
    basename(buildPath) !== buildDirectory ||
    !/^build-laravel-e2e-[0-9]+-[a-f0-9]+$/.test(buildDirectory)
) {
    throw new Error(
        'Refusing to use a Laravel E2E build path outside its dedicated public directory.',
    );
}

if (
    dirname(configCachePath) !== projectRoot ||
    basename(configCachePath) !== configCacheName ||
    !/^\.local-laravel-e2e-config-[0-9]+-[a-f0-9]+\.php$/.test(
        configCacheName,
    ) ||
    existsSync(configCachePath)
) {
    throw new Error(
        'Refusing to use an existing or invalid Laravel E2E config-cache path.',
    );
}

if (!existsSync(phpBinary)) {
    throw new Error(
        `PHP 8.4 CLI was not found at ${phpBinary}. Set LARAVEL_E2E_PHP to the local PHP executable.`,
    );
}

const appEnvironment: NodeJS.ProcessEnv = {
    ...process.env,
    APP_ENV: 'testing',
    APP_CONFIG_CACHE: configCachePath,
    APP_DEBUG: 'true',
    APP_KEY: base64AppKey,
    APP_URL: 'http://127.0.0.1',
    ASSET_URL: '',
    DB_CONNECTION: 'sqlite',
    DB_DATABASE: databasePath,
    DB_URL: '',
    CACHE_STORE: 'array',
    SESSION_DRIVER: 'cookie',
    SESSION_ENCRYPT: 'true',
    SESSION_COOKIE: 'skykite_laravel_e2e',
    SESSION_DOMAIN: '',
    SESSION_SECURE_COOKIE: 'false',
    SESSION_LIFETIME: '60',
    QUEUE_CONNECTION: 'sync',
    MAIL_MAILER: 'array',
    LOG_CHANNEL: 'stderr',
    BCRYPT_ROUNDS: '4',
    LARAVEL_E2E_DB_PATH: databasePath,
    LARAVEL_VITE_BUILD_DIRECTORY: buildDirectory,
    LARAVEL_E2E_QR_TOKEN: qrToken,
    LARAVEL_E2E_WAITER_EMAIL: waiterEmail,
    LARAVEL_E2E_WAITER_PASSWORD: waiterPassword,
};

function run(command: string, args: string[], env: NodeJS.ProcessEnv): void {
    const result = spawnSync(command, args, {
        cwd: projectRoot,
        env,
        encoding: 'utf8',
        windowsHide: true,
        maxBuffer: 8 * 1024 * 1024,
    });

    if (result.stdout) process.stdout.write(result.stdout);
    if (result.stderr) process.stderr.write(result.stderr);
    if (result.error) throw result.error;
    if (result.status !== 0) {
        throw new Error(
            `${basename(command)} exited with status ${result.status ?? 'unknown'}.`,
        );
    }
}

async function unusedLocalPort(): Promise<number> {
    const listener = createServer();
    await new Promise<void>((resolveListen, reject) => {
        listener.once('error', reject);
        listener.listen(0, '127.0.0.1', resolveListen);
    });
    const address = listener.address();
    if (!address || typeof address === 'string') {
        throw new Error(
            'Could not reserve a local port for the Laravel E2E server.',
        );
    }
    await new Promise<void>((resolveClose, reject) => {
        listener.close((error) => (error ? reject(error) : resolveClose()));
    });

    return address.port;
}

async function waitUntilReady(
    url: string,
    server: ReturnType<typeof spawn>,
): Promise<void> {
    const deadline = Date.now() + 45_000;
    while (Date.now() < deadline) {
        if (server.exitCode !== null) {
            throw new Error(
                `Laravel test server exited early with status ${server.exitCode}.`,
            );
        }
        try {
            const response = await fetch(url, {
                signal: AbortSignal.timeout(2_000),
            });
            if (response.ok) return;
        } catch {
            // The local PHP server may need a few seconds to bind its port.
        }
        await new Promise((resolveWait) => setTimeout(resolveWait, 400));
    }
    throw new Error(
        'Laravel did not become ready on its isolated local test port.',
    );
}

function stopServer(server: ReturnType<typeof spawn>): void {
    if (!server.pid || server.exitCode !== null) return;
    if (process.platform === 'win32') {
        spawnSync('taskkill', ['/PID', String(server.pid), '/T', '/F'], {
            windowsHide: true,
            stdio: 'ignore',
        });
    } else {
        server.kill('SIGTERM');
    }
}

async function main(): Promise<void> {
    let server: ReturnType<typeof spawn> | undefined;
    let databaseCreated = false;
    let buildDirectoryCreated = false;

    try {
        const viteCli = resolve(
            projectRoot,
            'node_modules',
            'vite',
            'bin',
            'vite.js',
        );
        const playwrightCli = resolve(
            projectRoot,
            'node_modules',
            '@playwright',
            'test',
            'cli.js',
        );
        if (!existsSync(viteCli) || !existsSync(playwrightCli)) {
            throw new Error(
                "Install the project's existing npm dependencies before running Laravel Playwright tests.",
            );
        }

        mkdirSync(buildPath, { recursive: false });
        buildDirectoryCreated = true;
        run(
            process.execPath,
            [viteCli, 'build', '--config', 'vite.laravel.config.ts'],
            appEnvironment,
        );

        const databaseFile = openSync(databasePath, 'wx');
        databaseCreated = true;
        closeSync(databaseFile);
        run(phpBinary, ['artisan', 'migrate:fresh', '--force'], appEnvironment);
        run(
            phpBinary,
            ['artisan', 'db:seed', '--class=PlaywrightE2ESeeder', '--force'],
            appEnvironment,
        );

        const port = await unusedLocalPort();
        const baseUrl = `http://127.0.0.1:${port}`;
        const serverEnvironment = { ...appEnvironment, APP_URL: baseUrl };
        server = spawn(
            phpBinary,
            ['artisan', 'serve', '--host=127.0.0.1', `--port=${port}`],
            {
                cwd: projectRoot,
                env: serverEnvironment,
                stdio: 'inherit',
                windowsHide: true,
            },
        );

        await waitUntilReady(baseUrl, server);
        run(
            process.execPath,
            [playwrightCli, 'test', '--config=playwright.laravel.config.ts'],
            {
                ...serverEnvironment,
                LARAVEL_E2E_BASE_URL: baseUrl,
            },
        );
    } finally {
        if (server) stopServer(server);
        if (databaseCreated) {
            for (const path of [
                databasePath,
                `${databasePath}-wal`,
                `${databasePath}-shm`,
            ]) {
                if (existsSync(path) && dirname(resolve(path)) === projectRoot)
                    unlinkSync(path);
            }
        }
        if (
            buildDirectoryCreated &&
            dirname(resolve(buildPath)) === resolve(projectRoot, 'public')
        ) {
            rmSync(buildPath, { recursive: true, force: true });
        }
    }
}

main().catch((error: unknown) => {
    console.error(
        error instanceof Error
            ? error.message
            : 'Laravel Playwright E2E failed.',
    );
    process.exitCode = 1;
});
