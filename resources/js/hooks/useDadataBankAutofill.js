import { useState } from 'react';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';

/**
 * Хук «автозаполнение реквизитов банка» по DaData.
 *
 * onApply(fields) — функция-аппликатор полей в форму банковского счёта:
 *  - useState-формы: (fields) => setForm(prev => ({...prev, ...fields}))
 *  - Inertia useForm: (fields) => Object.entries(fields).forEach(([k,v]) => setData(k, v))
 *
 * Возвращает:
 *  - applyBank(bank) — раскладывает suggestion DaData в bank_name / bank_bik / correspondent_account
 *  - lookupByBik(bik) — точное получение через /api/dadata/findById/bank
 *  - lookingUp — индикатор загрузки для кнопки «Найти по БИК»
 *
 * Маппинг:
 *   bank.value                       → bank_name
 *   bank.data.bic                    → bank_bik
 *   bank.data.correspondent_account  → correspondent_account
 */
export function useDadataBankAutofill(onApply) {
    const [lookingUp, setLookingUp] = useState(false);

    const applyBank = (bank) => {
        if (!bank || !bank.data) return;
        const data = bank.data;

        const fields = {};
        if (bank.value) fields.bank_name = bank.value;
        if (data.bic) fields.bank_bik = data.bic;
        if (data.correspondent_account) fields.correspondent_account = data.correspondent_account;

        onApply?.(fields);

        toaster.create({ title: 'Реквизиты банка загружены', type: 'success' });
    };

    const lookupByBik = async (bik) => {
        const trimmed = (bik || '').trim();
        if (!/^\d{9}$/.test(trimmed)) {
            toaster.create({
                title: 'Введите корректный БИК',
                description: 'БИК должен содержать 9 цифр.',
                type: 'error',
            });
            return;
        }

        setLookingUp(true);
        try {
            const { data } = await axios.post('/api/dadata/findById/bank', { bik: trimmed });

            if (!data?.bank) {
                toaster.create({
                    title: 'Банк не найден',
                    description: 'Заполните поля вручную.',
                    type: 'info',
                });
                return;
            }

            applyBank(data.bank);
        } catch (err) {
            const status = err?.response?.status;
            if (status === 422) {
                toaster.create({ title: 'Некорректный формат БИК', type: 'error' });
            } else if (status === 429) {
                toaster.create({
                    title: 'Слишком много запросов',
                    description: 'Подождите минуту и попробуйте снова.',
                    type: 'warning',
                });
            } else if (status === 503) {
                toaster.create({
                    title: 'Сервис подсказок временно недоступен',
                    type: 'error',
                });
            } else {
                toaster.create({
                    title: 'Не удалось загрузить реквизиты банка, попробуйте позже',
                    type: 'error',
                });
            }
        } finally {
            setLookingUp(false);
        }
    };

    return { applyBank, lookupByBik, lookingUp };
}
