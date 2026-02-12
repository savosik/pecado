import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Provider } from '@/components/ui/provider';
import ErrorBoundary from '@/components/common/ErrorBoundary';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        const adminPages = import.meta.glob('./Admin/**/*.jsx');

        // Пытаемся найти страницу в соответствующей директории
        if (name.startsWith('Admin/')) {
            return resolvePageComponent(`./${name}.jsx`, adminPages);
        }
        return resolvePageComponent(`./Pages/${name}.jsx`, pages);
    },
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <Provider>
                <ErrorBoundary>
                    <App {...props} />
                </ErrorBoundary>
            </Provider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
