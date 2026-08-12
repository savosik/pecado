import { useMemo, useState } from 'react';
import { createListCollection } from '@chakra-ui/react';
import {
    ComboboxContent,
    ComboboxControl,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemText,
    ComboboxRoot,
} from '@/components/ui/combobox';

/**
 * Выпадающий список с поиском.
 *
 * Для перечислений из двух-трёх значений это лишний слой — там остаётся обычный
 * select. Нужен там, где список растёт от данных: адреса клиента, перевозчики,
 * пункты выдачи.
 *
 * @param {Array<{value: string, label: string, hint?: string}>} options
 */
export function SearchableSelect({
    options,
    value,
    onChange,
    placeholder = 'Начните вводить для поиска',
    emptyText = 'Ничего не нашлось',
    disabled = false,
    size = 'sm',
}) {
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const needle = query.trim().toLowerCase();

        if (needle === '') return options;

        return options.filter((option) => (
            `${option.label} ${option.hint || ''}`.toLowerCase().includes(needle)
        ));
    }, [options, query]);

    const collection = useMemo(() => createListCollection({
        items: filtered,
        itemToString: (item) => item.label,
        itemToValue: (item) => item.value,
    }), [filtered]);

    return (
        <ComboboxRoot
            collection={collection}
            value={value ? [value] : []}
            inputValue={query}
            onInputValueChange={(details) => setQuery(details.inputValue)}
            onValueChange={(details) => onChange(details.value[0] || '')}
            openOnClick
            selectionBehavior="preserve"
            size={size}
            disabled={disabled}
            width="100%"
        >
            <ComboboxControl clearable>
                <ComboboxInput placeholder={placeholder} />
            </ComboboxControl>
            <ComboboxContent maxH="300px" overflowY="auto">
                <ComboboxEmpty>{emptyText}</ComboboxEmpty>
                {collection.items.map((item) => (
                    <ComboboxItem key={item.value} item={item}>
                        <ComboboxItemText>{item.label}</ComboboxItemText>
                    </ComboboxItem>
                ))}
            </ComboboxContent>
        </ComboboxRoot>
    );
}
