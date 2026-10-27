import { resolve } from "node:path";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

/**
 * Builds the legacy Laravel Blade assets without changing the React/Netlify
 * build configured in vite.config.ts.
 */
export default defineConfig({
    base: "/build/",
    plugins: [tailwindcss()],
    build: {
        manifest: "manifest.json",
        outDir: "public/build",
        emptyOutDir: true,
        rollupOptions: {
            input: [
                resolve(process.cwd(), "resources/css/app.css"),
                resolve(process.cwd(), "resources/js/app.js"),
            ],
        },
    },
});
