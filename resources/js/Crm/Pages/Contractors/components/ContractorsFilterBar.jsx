import { Box, HStack } from '@chakra-ui/react';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Один селект отбора. Пустое значение означает «неважно» и уходит из запроса
 * как undefined — иначе в адресной строке копились бы пустые параметры.
 */
function FilterSelect({ value, onChange, placeholder, options, minW = '200px' }) {
    return (
        <Box minW={minW}>
            <NativeSelectRoot size="sm">
                <NativeSelectField
                    value={value ?? ''}
                    onChange={(event) => onChange(event.target.value || undefined)}
                >
                    <option value="">{placeholder}</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </NativeSelectField>
            </NativeSelectRoot>
        </Box>
    );
}

const DEBT_OPTIONS = [
    { value: 'overdue', label: 'Есть просрочка' },
    { value: 'debt', label: 'Есть долг или переплата' },
];

/**
 * Поиск и отборы списка контрагентов.
 *
 * Отбор по менеджеру и «без партнёра» показываются только тому, кто видит отдел
 * целиком: у менеджера в скоупе и так лишь юрлица его партнёров.
 */
export default function ContractorsFilterBar({
    filters,
    searchQuery,
    onSearch,
    onChange,
    onReset,
    managers = [],
    canSeeAll = false,
}) {
    const hasFilters = Boolean(
        filters.search || filters.debt || filters.manager_id || filters.client_id || filters.orphans
    );

    return (
        <HStack gap={3} align="center" wrap="wrap">
            <Box flex="1" minW="260px">
                <SearchInput
                    value={searchQuery}
                    onChange={onSearch}
                    placeholder="Наименование, юрнаименование, ИНН или ОГРН..."
                />
            </Box>

            <FilterSelect
                value={filters.debt}
                onChange={(value) => onChange({ debt: value })}
                placeholder="Долг — любой"
                options={DEBT_OPTIONS}
            />

            {canSeeAll && managers.length > 0 && (
                <FilterSelect
                    value={filters.manager_id ? String(filters.manager_id) : undefined}
                    onChange={(value) => onChange({ manager_id: value })}
                    placeholder="Менеджер — любой"
                    options={managers.map((manager) => ({
                        value: String(manager.id),
                        label: manager.name,
                    }))}
                />
            )}

            {canSeeAll && (
                <Checkbox
                    size="sm"
                    checked={Boolean(filters.orphans)}
                    onCheckedChange={(event) => onChange({ orphans: event.checked ? 1 : undefined })}
                >
                    Без партнёра
                </Checkbox>
            )}

            {hasFilters && (
                <Button size="sm" variant="outline" onClick={onReset}>
                    Сбросить
                </Button>
            )}
        </HStack>
    );
}
