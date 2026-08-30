import { useEffect, useRef, useState } from 'react';
import axios from 'axios';

/**
 * Опрос `/crm/salary/data` раз в `poll_seconds` — «на эту минуту» без вебсокетов.
 *
 * Интервал приходит с сервера и может измениться между опросами, поэтому каждый
 * следующий заводится после ответа, а не одним setInterval (как в PresenceBar).
 * Скрытая вкладка не опрашивает: незачем греть сервер ради экрана, который никто
 * не видит; при возвращении вкладки опрос делается сразу.
 */
export function useSalaryPolling(initial) {
    const [data, setData] = useState(initial);
    const [refreshing, setRefreshing] = useState(false);
    const timer = useRef(null);
    const alive = useRef(true);

    useEffect(() => setData(initial), [initial]);

    const month = data?.month;
    const managerId = data?.manager?.id ?? null;
    const canSeeAll = Boolean(data?.can_see_all);
    const pollSeconds = data?.poll_seconds ?? 60;

    useEffect(() => {
        alive.current = true;

        const schedule = (seconds) => {
            window.clearTimeout(timer.current);
            timer.current = window.setTimeout(poll, seconds * 1000);
        };

        const poll = async () => {
            if (!alive.current) return;

            if (document.hidden) {
                schedule(pollSeconds);
                return;
            }

            setRefreshing(true);
            try {
                const params = { month };
                if (canSeeAll && managerId) params.manager = managerId;

                const res = await axios.get('/crm/salary/data', { params });
                if (alive.current) setData(res.data);
            } catch {
                // сеть моргнула — следующий опрос покажет
            } finally {
                if (alive.current) setRefreshing(false);
            }

            schedule(pollSeconds);
        };

        const onVisible = () => {
            if (!document.hidden) poll();
        };

        document.addEventListener('visibilitychange', onVisible);
        schedule(pollSeconds);

        return () => {
            alive.current = false;
            window.clearTimeout(timer.current);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [month, managerId, canSeeAll, pollSeconds]);

    return { data, refreshing };
}
