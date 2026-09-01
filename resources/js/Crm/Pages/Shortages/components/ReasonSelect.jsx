import { Badge, Text } from '@chakra-ui/react';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Причина недобора прямо в строке журнала.
 *
 * Выпадающий список, а не кнопки: причин девять и будет больше — ряд кнопок
 * в строке таблицы столько не вмещает, а разбор недобора должен занимать
 * один клик, без перехода в карточку.
 *
 * Отключённые причины в списке не показываются, но уже проставленная остаётся
 * видимой: РОП убирает причину из оборота, а не переписывает историю разметки.
 */
export default function ReasonSelect({
    value = null,
    reasons = [],
    categories = [],
    canEdit = false,
    onChange,
    size = 'xs',
    placeholder = 'Выберите причину',
}) {
    const current = reasons.find((reason) => reason.value === value) ?? null;

    if (!canEdit) {
        return current ? (
            <Badge colorPalette={current.color} variant="subtle">{current.label}</Badge>
        ) : (
            <Text color="fg.muted" fontSize="sm">не размечено</Text>
        );
    }

    const available = reasons.filter((reason) => reason.is_active || reason.value === value);

    return (
        <NativeSelectRoot size={size} minW="200px">
            <NativeSelectField
                value={value ? String(value) : ''}
                onChange={(event) => onChange?.(event.target.value ? Number(event.target.value) : null)}
                aria-label="Причина недобора"
            >
                <option value="">{placeholder}</option>

                {categories.map((category) => {
                    const inCategory = available.filter((reason) => reason.category === category.value);

                    if (inCategory.length === 0) {
                        return null;
                    }

                    return (
                        <optgroup key={category.value} label={category.label}>
                            {inCategory.map((reason) => (
                                <option key={reason.value} value={String(reason.value)}>
                                    {reason.label}{reason.is_active ? '' : ' (отключена)'}
                                </option>
                            ))}
                        </optgroup>
                    );
                })}
            </NativeSelectField>
        </NativeSelectRoot>
    );
}
