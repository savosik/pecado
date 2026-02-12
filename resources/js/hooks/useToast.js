/**
 * Хук для Chakra UI Toaster — удобный API: { success, error, info }.
 *
 * Использование:
 *   const toast = useToast();
 *   toast.success('Сохранено');
 *   toast.error('Ошибка', 'Не удалось сохранить');
 *   toast.info('Подсказка', 'Нажмите Ctrl+S для сохранения');
 */

import { useCallback } from 'react';
import { toaster } from '@/components/ui/toaster';

export default function useToast() {
    /**
     * @param {string} title
     * @param {string} [description]
     */
    const success = useCallback((title, description) => {
        toaster.create({ title, description, type: 'success' });
    }, []);

    /**
     * @param {string} title
     * @param {string} [description]
     */
    const error = useCallback((title, description) => {
        toaster.create({ title, description, type: 'error' });
    }, []);

    /**
     * @param {string} title
     * @param {string} [description]
     */
    const info = useCallback((title, description) => {
        toaster.create({ title, description, type: 'info' });
    }, []);

    return { success, error, info };
}
