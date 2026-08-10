import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Общая логика ленты комментариев: загрузка, догрузка страниц, правка и удаление.
 *
 * Сквозная лента партнёра и врезка в карточку сущности отличаются только источником
 * данных — поведение записей у них одинаковое, поэтому оно живёт здесь, а не дублируется
 * в двух компонентах.
 *
 * @param {string} url — эндпоинт списка
 * @param {object} params — query-параметры (entity_type/entity_id для врезки)
 */
export function useCommentFeed(url, params = {}) {
    const [entries, setEntries] = useState([]);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [total, setTotal] = useState(0);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    const paramsKey = JSON.stringify(params);

    const load = useCallback(async (targetPage = 1) => {
        setLoading(true);
        setFailed(false);
        try {
            const res = await axios.get(url, { params: { ...JSON.parse(paramsKey), page: targetPage } });
            const { data, current_page: currentPage, last_page, total: totalItems } = res.data;
            setEntries((prev) => (targetPage === 1 ? data : [...prev, ...data]));
            setPage(currentPage);
            setLastPage(last_page);
            setTotal(totalItems);
        } catch (e) {
            setFailed(true);
            // 403/404 уже показал глобальный перехватчик axios в bootstrap.js
            if (![403, 404].includes(e?.response?.status)) {
                toastError('Не удалось загрузить комментарии', 'Попробуйте обновить страницу.');
            }
        } finally {
            setLoading(false);
        }
    }, [url, paramsKey]);

    useEffect(() => { load(1); }, [load]);

    const create = useCallback(async (payload) => {
        setBusy(true);
        try {
            const res = await axios.post('/crm/comments', payload);
            // Закреплённые уходят вверх — проще перечитать первую страницу,
            // чем повторять на партнёре серверную сортировку.
            if (res.data.is_pinned) {
                await load(1);
            } else {
                setEntries((prev) => [res.data, ...prev]);
                setTotal((prev) => prev + 1);
            }
            return true;
        } catch (e) {
            const message = e?.response?.data?.message || 'Проверьте текст и попробуйте ещё раз.';
            toastError('Комментарий не сохранён', message);
            return false;
        } finally {
            setBusy(false);
        }
    }, [load]);

    const update = useCallback(async (id, payload) => {
        setBusy(true);
        try {
            const res = await axios.patch(`/crm/comments/${id}`, payload);
            if ('is_pinned' in payload) {
                await load(1);
            } else {
                setEntries((prev) => prev.map((item) => (item.id === id ? res.data : item)));
            }
        } catch (e) {
            const message = e?.response?.data?.message || 'Попробуйте ещё раз.';
            toastError('Изменение не сохранено', message);
        } finally {
            setBusy(false);
        }
    }, [load]);

    const remove = useCallback(async (id) => {
        setBusy(true);
        try {
            await axios.delete(`/crm/comments/${id}`);
            setEntries((prev) => prev.filter((item) => item.id !== id));
            setTotal((prev) => Math.max(prev - 1, 0));
            toastSuccess('Комментарий удалён');
        } catch (e) {
            const message = e?.response?.data?.message || 'Попробуйте ещё раз.';
            toastError('Не удалось удалить комментарий', message);
        } finally {
            setBusy(false);
        }
    }, []);

    /**
     * Поставить готовую запись в начало ленты.
     *
     * Нужна ленте-чату: задача, письмо и звонок создаются своими эндпоинтами,
     * и перечитывать всю страницу ради одной новой записи означало бы терять
     * позицию скролла на каждое действие.
     */
    const prepend = useCallback((entry) => {
        setEntries((prev) => [entry, ...prev]);
        setTotal((prev) => prev + 1);
    }, []);

    return {
        entries,
        total,
        loading,
        busy,
        failed,
        hasMore: page < lastPage,
        loadMore: () => load(page + 1),
        reload: () => load(1),
        create,
        update,
        remove,
        prepend,
    };
}
