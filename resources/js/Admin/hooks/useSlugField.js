import { useState, useCallback } from 'react';
import { generateSlug } from '@/Admin/utils/slugUtils';

/**
 * Хук для реактивного slug-поля.
 *
 * В режиме Create: slug автоматически генерируется из sourceField,
 * пока пользователь не изменит slug вручную.
 *
 * В режиме Edit: slug НЕ генерируется автоматически (чтобы не менять
 * существующий slug), но если пользователь очистит slug — генерация возобновится.
 *
 * @param {Object} options
 * @param {Object} options.data - данные формы (из useForm)
 * @param {Function} options.setData - функция обновления данных (из useForm)
 * @param {string} [options.sourceField='name'] - поле-источник для генерации slug
 * @param {boolean} [options.isEditing=false] - режим редактирования
 * @returns {{ handleSourceChange: Function, handleSlugChange: Function }}
 */
export function useSlugField({ data, setData, sourceField = 'name', isEditing = false }) {
    // В режиме Edit slug не генерируется пока пользователь не очистит его
    const [slugTouched, setSlugTouched] = useState(isEditing && !!data.slug);

    /**
     * Обработчик изменения исходного поля (name/title).
     * Обновляет slug, если он не был изменён вручную.
     */
    const handleSourceChange = useCallback((value) => {
        const updates = { [sourceField]: value };

        if (!slugTouched) {
            updates.slug = generateSlug(value);
        }

        setData(prev => ({ ...prev, ...updates }));
    }, [slugTouched, sourceField, setData]);

    /**
     * Обработчик изменения slug вручную.
     * Помечает slug как изменённый, чтобы авто-генерация больше не перезаписывала его.
     * Если slug очищен — возобновляем авто-генерацию.
     */
    const handleSlugChange = useCallback((value) => {
        if (value === '') {
            setSlugTouched(false);
        } else {
            setSlugTouched(true);
        }
        setData(sourceField === 'name' ? 'slug' : 'slug', value);
    }, [setData, sourceField]);

    return { handleSourceChange, handleSlugChange };
}
