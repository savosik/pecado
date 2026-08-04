import { Box, HStack } from '@chakra-ui/react';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Один селект отбора.
 *
 * Пустое значение всегда означает «неважно» и уходит из запроса как undefined —
 * иначе в адресной строке копились бы пустые параметры и портили сохранённый отбор.
 */
function FilterSelect({ value, onChange, placeholder, options, minW = '180px' }) {
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

/**
 * Строка поиска и отборов списка клиентов.
 *
 * @param {object} filters
 * @param {string} searchQuery
 * @param {Function} onSearch
 * @param {Function} onChange — применить один изменившийся параметр
 * @param {Array} lifecycleOptions
 * @param {Array} managers
 */
export default function ClientsFilterBar({
    filters,
    searchQuery,
    onSearch,
    onChange,
    lifecycleOptions = [],
    managers = [],
    canSeeAll = false,
    canSeeTasks = false,
    canSeePlans = false,
    uncoveredCount = null,
}) {
    return (
        <HStack gap={3} align="center" wrap="wrap">
            <Box flex="1" minW="260px">
                <SearchInput
                    value={searchQuery}
                    onChange={onSearch}
                    placeholder="Имя, email, телефон, текст задачи или комментария, номер документа..."
                />
            </Box>

            {lifecycleOptions.length > 0 && (
                <FilterSelect
                    value={filters.lifecycle}
                    onChange={(value) => onChange({ lifecycle: value })}
                    placeholder="Все стадии"
                    options={lifecycleOptions}
                />
            )}

            {canSeeTasks && (
                <FilterSelect
                    value={filters.task_state}
                    onChange={(value) => onChange({ task_state: value })}
                    placeholder="Задачи: неважно"
                    minW="200px"
                    options={[
                        { value: 'overdue', label: 'Есть просроченные' },
                        { value: 'today', label: 'Есть на сегодня' },
                        { value: 'week', label: 'Есть на неделю' },
                        { value: 'any', label: 'Есть активные' },
                        {
                            value: 'none',
                            label: uncoveredCount !== null ? `Без задач (${uncoveredCount})` : 'Без задач',
                        },
                    ]}
                />
            )}

            {canSeePlans && (
                <FilterSelect
                    value={filters.plan_state}
                    onChange={(value) => onChange({ plan_state: value })}
                    placeholder="План: неважно"
                    minW="190px"
                    options={[
                        { value: 'behind', label: 'Отстают от плана' },
                        { value: 'ahead', label: 'Выполнили план' },
                        { value: 'with_plan', label: 'План задан' },
                        { value: 'without_plan', label: 'Плана нет' },
                    ]}
                />
            )}

            <FilterSelect
                value={filters.inactive_days}
                onChange={(value) => onChange({ inactive_days: value })}
                placeholder="Покупки: неважно"
                minW="200px"
                options={[
                    { value: '30', label: 'Не покупает 30 дней' },
                    { value: '60', label: 'Не покупает 60 дней' },
                    { value: '90', label: 'Не покупает 90 дней' },
                ]}
            />

            {canSeeAll && (
                <FilterSelect
                    value={filters.manager_id}
                    onChange={(value) => onChange({ manager_id: value })}
                    placeholder="Все менеджеры"
                    minW="220px"
                    options={managers.map((manager) => ({
                        value: String(manager.id),
                        label: manager.name,
                    }))}
                />
            )}
        </HStack>
    );
}
