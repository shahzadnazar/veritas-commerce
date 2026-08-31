import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';
import { resolve } from 'node:path';

export default defineConfig({
    plugins: [
        laravel({
            // One entry point per Inertia area. A storefront visitor never
            // downloads seller or admin code.
            input: [
                'resources/css/app.css',
                'resources/js/storefront/main.tsx',
                'resources/js/seller/main.tsx',
                'resources/js/admin/main.tsx',
            ],
            ssr: 'resources/js/storefront/ssr.tsx',
            refresh: true,
        }),
        react(),
        tailwindcss(),
    ],
    resolve: {
        alias: {
            '@': resolve(import.meta.dirname, 'resources/js'),
            '@ds': resolve(import.meta.dirname, 'resources/js/design-system'),
        },
    },
});
