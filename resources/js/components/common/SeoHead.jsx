import { Head } from '@inertiajs/react';

/**
 * SEO-компонент.
 *
 * Полная SEO-мета (description, keywords, canonical, robots, Open Graph,
 * Twitter Card, JSON-LD) рендерится сервером в resources/views/app.blade.php
 * из seo-пропа — она видна в view-source и краулерам без SSR React.
 *
 * Здесь управляем только <title>, чтобы он обновлялся при клиентской
 * SPA-навигации (Inertia заменяет <title inertia> без дублирования).
 *
 * @param {{ seo?: { title?: string } }} props
 */
export default function SeoHead({ seo }) {
    if (!seo?.title) return null;

    return (
        <Head>
            <title>{seo.title}</title>
        </Head>
    );
}
