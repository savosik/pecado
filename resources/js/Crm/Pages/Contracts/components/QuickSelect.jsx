import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { toastError } from '@/utils/toast';

/**
 * Выпадашка в строке реестра: меняет одно поле договора без открытия формы.
 *
 * Сохраняет через `contracts.quick` и перечитывает только список — так
 * бейджи, счётчики вкладок и подсветка «истекает» пересчитываются сервером,
 * а не дублируются на клиенте.
 *
 * @param {number} contractId
 * @param {string} field — status | payment_terms | form | category_id | responsible_manager_id
 * @param {string|number|null} value
 * @param {Array<{value: string|number, label: string}>} options
 * @param {string|null} placeholder — подпись пустого значения (null = поле обязательное)
 */
export default function QuickSelect({ contractId, field, value, options, placeholder = null, disabled = false, width = '150px' }) {
    const [saving, setSaving] = useState(false);

    const change = async (next) => {
        setSaving(true);
        try {
            await axios.patch(route('crm.contracts.quick', contractId), { [field]: next === '' ? null : next });
            router.reload({ only: ['contracts', 'categories', 'missingCount'], preserveScroll: true });
        } catch (e) {
            const errors = e?.response?.data?.errors || {};
            toastError('Не удалось сохранить', errors[field]?.[0] || e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <NativeSelectRoot size="xs" width={width} disabled={disabled || saving}>
            <NativeSelectField
                value={value ?? ''}
                onChange={(e) => change(e.target.value)}
                onClick={(e) => e.stopPropagation()}
            >
                {placeholder !== null && <option value="">{placeholder}</option>}
                {options.map((item) => (
                    <option key={item.value} value={item.value}>{item.label}</option>
                ))}
            </NativeSelectField>
        </NativeSelectRoot>
    );
}
