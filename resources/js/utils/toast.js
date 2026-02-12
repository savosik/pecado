/**
 * Императивные тосты — обёртки над Chakra UI createToaster.
 *
 * Использование:
 *   import { toastSuccess, toastError, toastInfo } from '@/utils/toast';
 *   toastSuccess('Сохранено');
 *   toastError('Ошибка', 'Не удалось сохранить');
 */

import { toaster } from '@/components/ui/toaster';

/**
 * @param {string} title
 * @param {string} [description]
 */
export function toastSuccess(title, description) {
    toaster.create({ title, description, type: 'success' });
}

/**
 * @param {string} title
 * @param {string} [description]
 */
export function toastError(title, description) {
    toaster.create({ title, description, type: 'error' });
}

/**
 * @param {string} title
 * @param {string} [description]
 */
export function toastInfo(title, description) {
    toaster.create({ title, description, type: 'info' });
}
