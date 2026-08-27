import { useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Box } from '@chakra-ui/react';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { toastError } from '@/utils/toast';

/**
 * Поле договора, редактируемое из строки реестра.
 *
 * В покое — бейдж или текст (`display`), как в обычном списке; выпадашка
 * появляется только по клику и прячется после выбора или потери фокуса —
 * таблица из 270 строк с открытыми селектами читалась как форма, а не реестр.
 *
 * Сохраняет через `contracts.quick` и перечитывает только список — так
 * бейджи, счётчики вкладок и подсветка «истекает» пересчитываются сервером.
 *
 * @param {number} contractId
 * @param {string} field — status | payment_terms | form | category_id | responsible_manager_id
 * @param {string|number|null} value
 * @param {Array<{value: string|number, label: string}>} options
 * @param {string|null} placeholder — подпись пустого значения (null = поле обязательное)
 * @param {import('react').ReactNode} display — что показывать в покое
 * @param {boolean} editable — без права на правку клик ничего не делает
 */
export default function QuickSelect({
    contractId,
    field,
    value,
    options,
    placeholder = null,
    display,
    editable = true,
    width = '150px',
}) {
    const [editing, setEditing] = useState(false);
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
            setEditing(false);
        }
    };

    if (!editable) {
        return display;
    }

    if (!editing) {
        return (
            <Box
                as="span"
                display="inline-flex"
                cursor="pointer"
                title="Нажмите, чтобы изменить"
                borderBottomWidth="1px"
                borderBottomStyle="dashed"
                borderColor="transparent"
                _hover={{ borderColor: 'border.emphasized' }}
                onClick={(e) => { e.stopPropagation(); setEditing(true); }}
            >
                {display}
            </Box>
        );
    }

    return (
        <NativeSelectRoot size="xs" width={width} disabled={saving}>
            <NativeSelectField
                autoFocus
                value={value ?? ''}
                onChange={(e) => change(e.target.value)}
                onBlur={() => { if (!saving) setEditing(false); }}
                onKeyDown={(e) => { if (e.key === 'Escape') setEditing(false); }}
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
