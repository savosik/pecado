import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import jsconfigPaths from 'vite-jsconfig-paths';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.jsx'],
            refresh: true,
        }),
        tailwindcss(),
        react(),
        jsconfigPaths(),
    ],
    server: {
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: true,  // Enable CORS for Docker cross-container requests

        // Vite 7 отклоняет запросы с незнакомым заголовком Host (защита от
        // DNS-rebinding) — отдаёт 403. HMR-сокет настроен на VITE_HMR_HOST,
        // то есть на loc.pecado.ru, и без этого списка горячая перезагрузка
        // молча не работала: страница грузилась, но правки не долетали.
        allowedHosts: ['loc.pecado.ru', 'localhost', '127.0.0.1'],
        hmr: {
            host: process.env.VITE_HMR_HOST || 'localhost',
            clientPort: parseInt(process.env.VITE_HMR_CLIENT_PORT || '5174', 10),
        },
        watch: {
            // Polling на Linux не нужен: bind-mount пробрасывает inotify нативно.
            // Включённый usePolling заставлял Vite непрерывно обходить дерево
            // проекта — замерено 2026-08-09: контейнер pecado-node держал ~180 %
            // CPU круглосуточно (6 суток процессорного времени за 3 дня аптайма)
            // и был главным источником нагрева машины, а не сборки и не тесты.
            //
            // На macOS/Windows inotify через bind-mount не работает — там polling
            // включается переменной VITE_USE_POLLING=true.
            usePolling: process.env.VITE_USE_POLLING === 'true',
            ignored: [
                '**/storage/**',
                '**/vendor/**',
                '**/node_modules/**',
                '**/.git/**',
                '**/public/build/**',
            ],
        },
    },
});
