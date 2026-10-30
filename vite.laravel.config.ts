import { resolve } from 'node:path';
import tailwindcss from '@tailwindcss/vite';
import { defineConfig } from 'vite';

const requestedBuildDirectory = process.env.LARAVEL_VITE_BUILD_DIRECTORY;
const buildDirectory =
    requestedBuildDirectory &&
    /^build-laravel-e2e-[a-z0-9-]+$/.test(requestedBuildDirectory)
        ? requestedBuildDirectory
        : 'build';

/**
 * Builds the legacy Laravel Blade assets without changing the React/Netlify
 * build configured in vite.config.ts.
 */
export default defineConfig({
    base: `/${buildDirectory}/`,
    publicDir: false,
    plugins: [tailwindcss()],
    build: {
        manifest: 'manifest.json',
        outDir: `public/${buildDirectory}`,
        emptyOutDir: true,
        rollupOptions: {
            input: [
                resolve(process.cwd(), 'resources/css/app.css'),
                resolve(process.cwd(), 'resources/js/app.js'),
            ],
        },
    },
});
