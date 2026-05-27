import './bootstrap';
import '../css/app.css';

import { createRoot } from 'react-dom/client';
import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { Provider } from '@/components/ui/provider';
import ErrorBoundary from '@/components/common/ErrorBoundary';
import ChangePasswordDialog from '@/components/common/ChangePasswordDialog';

const appName = import.meta.env.VITE_APP_NAME || 'Pecado';

/**
 * Обёртка-layout, которая добавляет глобальный диалог смены пароля.
 * Рендерится ВНУТРИ Inertia-контекста, поэтому usePage() работает корректно.
 */
function GlobalLayout({ children }) {
    return (
        <>
            {children}
            <ChangePasswordDialog />
        </>
    );
}

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: async (name) => {
        const pages = import.meta.glob('./Pages/**/*.jsx');
        const adminPages = import.meta.glob('./Admin/**/*.jsx');

        // Пытаемся найти страницу в соответствующей директории
        let page;
        if (name.startsWith('Admin/')) {
            page = await resolvePageComponent(`./${name}.jsx`, adminPages);
        } else {
            page = await resolvePageComponent(`./Pages/${name}.jsx`, pages);
        }

        // Оборачиваем каждую страницу в GlobalLayout (persistent layout),
        // чтобы ChangePasswordDialog был внутри Inertia-контекста
        page.default.layout = page.default.layout || ((p) => <GlobalLayout>{p}</GlobalLayout>);

        return page;
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

