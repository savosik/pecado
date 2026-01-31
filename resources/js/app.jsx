import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Provider } from '@/components/ui/provider';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => {
        // Support both ./Pages/ and ./Admin/ directories
        const pages = import.meta.glob('./Pages/**/*.jsx');
        const adminPages = import.meta.glob('./Admin/**/*.jsx');
        const allPages = { ...pages, ...adminPages };

        // Try to resolve the page
        return resolvePageComponent(`./Pages/${name}.jsx`, pages)
            .catch(() => resolvePageComponent(`./${name}.jsx`, adminPages));
    },
    setup({ el, App, props }) {
        const root = createRoot(el);
        root.render(
            <Provider>
                <App {...props} />
            </Provider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});
