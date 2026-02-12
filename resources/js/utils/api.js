/**
 * Обёртки над window.axios с единообразной обработкой ошибок.
 *
 * Использование:
 *   import { apiGet, apiPost, apiPut, apiDelete } from '@/utils/api';
 *   const data = await apiGet('/api/favorites/ids');
 *   await apiPost('/api/cart/items', { product_id: 1, quantity: 2 });
 */

/**
 * Обработка ошибок axios — извлекает ошибки валидации и пробрасывает.
 * Обработка 401/403/500 выполняется глобальным interceptor в bootstrap.js.
 *
 * @param {Error} error
 * @throws {Error}
 */
function handleApiError(error) {
    const status = error?.response?.status;
    const data = error?.response?.data;

    if (status === 422) {
        // Ошибки валидации — пробрасываем без toast (форма покажет их сама)
        const err = new Error('Ошибка валидации');
        err.status = 422;
        err.errors = data?.errors || {};
        throw err;
    }

    // Остальные ошибки (401, 403, 500+) обрабатываются глобальным interceptor
    throw error;
}

/**
 * @param {string} url
 * @param {object} [params] — query параметры
 * @returns {Promise<any>}
 */
export async function apiGet(url, params) {
    try {
        const response = await window.axios.get(url, { params });
        return response.data;
    } catch (error) {
        handleApiError(error);
    }
}

/**
 * @param {string} url
 * @param {object} [data]
 * @returns {Promise<any>}
 */
export async function apiPost(url, data) {
    try {
        const response = await window.axios.post(url, data);
        return response.data;
    } catch (error) {
        handleApiError(error);
    }
}

/**
 * @param {string} url
 * @param {object} [data]
 * @returns {Promise<any>}
 */
export async function apiPut(url, data) {
    try {
        const response = await window.axios.put(url, data);
        return response.data;
    } catch (error) {
        handleApiError(error);
    }
}

/**
 * @param {string} url
 * @returns {Promise<any>}
 */
export async function apiDelete(url) {
    try {
        const response = await window.axios.delete(url);
        return response.data;
    } catch (error) {
        handleApiError(error);
    }
}
