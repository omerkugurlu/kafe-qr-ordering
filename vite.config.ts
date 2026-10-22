import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";
import { viteApiPlugin } from "./src/server/viteApiPlugin.js";

export default defineConfig({
    plugins: [react(), tailwindcss(), viteApiPlugin()],
    server: {
        host: "127.0.0.1",
        strictPort: true,
    },
});
