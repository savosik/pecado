import { useState } from 'react';
import axios from 'axios';
import { toaster } from '@/components/ui/toaster';

/**
 * Хук «автозаполнение реквизитов компании по данным DaData».
 *
 * Принимает onApply(fields) — функция-аппликатор полей в форму. Она получает
 * частичный объект {field: value, ...} и должна записать значения в свой стейт:
 *  - useState-формы: (fields) => setForm(prev => ({...prev, ...fields}))
 *  - Inertia useForm: (fields) => Object.entries(fields).forEach(([k,v]) => setData(k, v))
 *
 * Возвращает:
 *  - applyParty(party) — раскладывает suggestion DaData по полям формы
 *  - lookupByInn(inn, kpp?) — дёргает /api/dadata/findById/party и применяет ответ
 *  - lookingUp — индикатор загрузки для UI кнопки «Найти по ИНН»
 *
 * Маппинг полей DaData → форма:
 *   data.name.short_with_opf → name (короткое название с организационно-правовой формой)
 *   data.name.full_with_opf  → legal_name (полное юридическое название)
 *   data.inn                 → tax_id
 *   data.ogrn                → registration_number (ОГРН)
 *   data.kpp                 → tax_code (КПП)
 *   data.okpo                → okpo_code
 *   data.address.unrestricted_value → legal_address
 *   data.address.unrestricted_value → actual_address (если был пуст)
 */
export function useDadataPartyAutofill(onApply) {
    const [lookingUp, setLookingUp] = useState(false);

    const applyParty = (party) => {
        if (!party || !party.data) return;
        const data = party.data;
        const address = data.address?.unrestricted_value || data.address?.value || '';

        const fields = {};
        const shortName = data.name?.short_with_opf || party.value;
        const fullName = data.name?.full_with_opf || party.unrestricted_value;
        if (shortName) fields.name = shortName;
        if (fullName) fields.legal_name = fullName;
        if (data.inn) fields.tax_id = data.inn;
        if (data.ogrn) fields.registration_number = data.ogrn;
        if (data.kpp) fields.tax_code = data.kpp;
        if (data.okpo) fields.okpo_code = data.okpo;
        if (address) {
            fields.legal_address = address;
            fields.actual_address = address;
        }

        onApply?.(fields);

        toaster.create({ title: 'Реквизиты компании загружены', type: 'success' });
    };

    const lookupByInn = async (inn, kpp = null) => {
        const trimmed = (inn || '').trim();
        if (!/^\d{10}$|^\d{12}$/.test(trimmed)) {
            toaster.create({
                title: 'Введите корректный ИНН',
                description: 'ИНН должен содержать 10 или 12 цифр.',
                type: 'error',
            });
            return;
        }

        setLookingUp(true);
        try {
            const { data } = await axios.post('/api/dadata/findById/party', {
                inn: trimmed,
                ...(kpp ? { kpp } : {}),
            });

            if (!data?.party) {
                toaster.create({
                    title: 'Компания не найдена',
                    description: 'Заполните поля вручную.',
                    type: 'info',
                });
                return;
            }

            applyParty(data.party);
        } catch (err) {
            const status = err?.response?.status;
            if (status === 422) {
                toaster.create({ title: 'Некорректный формат ИНН', type: 'error' });
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
                    title: 'Не удалось загрузить реквизиты, попробуйте позже',
                    type: 'error',
                });
            }
        } finally {
            setLookingUp(false);
        }
    };

    return { applyParty, lookupByInn, lookingUp };
}
