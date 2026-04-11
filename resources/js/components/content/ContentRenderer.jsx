import BlockRenderer from './BlockRenderer';
import { Prose } from '@/components/ui/prose';

/**
 * ContentRenderer — универсальный рендерер контента.
 *
 * Автоматически определяет формат:
 * - JSON с блоками → рендерит через BlockRenderer
 * - HTML-строка → рендерит через Prose (dangerouslySetInnerHTML)
 *
 * Используется на всех страницах показа (статьи, новости, товары и т.д.)
 */
export default function ContentRenderer({ content, proseSize = 'lg' }) {
    if (!content) return null;

    const blocks = tryParseBlocks(content);

    if (blocks) {
        return <BlockRenderer blocks={blocks} />;
    }

    // Fallback: HTML-контент (старые статьи, импорт из 1С и т.д.)
    return (
        <Prose size={proseSize} maxW="none" dangerouslySetInnerHTML={{ __html: content }} />
    );
}

/**
 * Пытается распарсить контент как JSON-блоки.
 * Возвращает массив блоков или null.
 */
function tryParseBlocks(content) {
    // Уже объект (если модель вернула через accessor)
    if (typeof content === 'object' && content !== null) {
        if (Array.isArray(content.blocks)) return content.blocks;
        if (Array.isArray(content)) return content;
        return null;
    }

    // Строка — пытаемся распарсить JSON
    if (typeof content === 'string') {
        const trimmed = content.trim();
        if (!trimmed.startsWith('{') && !trimmed.startsWith('[')) return null;

        try {
            const parsed = JSON.parse(trimmed);
            if (parsed && Array.isArray(parsed.blocks)) return parsed.blocks;
            if (Array.isArray(parsed)) return parsed;
        } catch {
            // не JSON — считаем HTML
        }
    }

    return null;
}
