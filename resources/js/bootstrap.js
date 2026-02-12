import axios from 'axios';
import { toastError } from '@/utils/toast';

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

window.axios.interceptors.response.use(
    (response) => response,
    (error) => {
        const status = error?.response?.status;

        if (status === 401) {
            window.location.href = '/login';
        } else if (status === 403) {
            toastError('Доступ запрещён', 'У вас нет прав для выполнения этого действия');
        } else if (status >= 500) {
            toastError('Ошибка сервера', 'Что-то пошло не так. Попробуйте позже');
        }

        return Promise.reject(error);
    }
);
