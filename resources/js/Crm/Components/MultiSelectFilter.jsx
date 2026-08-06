import { useMemo, useState } from 'react';
import { Box, HStack, Input, Popover, Portal, Text, VStack } from '@chakra-ui/react';
import { LuChevronDown, LuSearch } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Мультивыбор со строкой поиска — общий контрол фильтров CRM.
 *
 * Справочники здесь длинные (сотни партнёров и контрагентов), поэтому обычный
 * select не годится: без поиска нужную строку в нём не найти, а выбрать больше
 * одного значения он не даёт вовсе.
 *
 * Сравнение id идёт по строкам: из URL значения приезжают строками, из пропсов
 * — числами, и без приведения галочка после перезагрузки страницы слетала бы.
 *
 * @param {Array} options — справочник [{ id, name }]
 * @param {Array} selectedIds — выбранные значения (числа или строки)
 * @param {Function} onChange — новый массив значений
 */
export default function MultiSelectFilter({
    label,
    options = [],
    selectedIds = [],
    onChange,
    idKey = 'id',
    labelKey = 'name',
    allLabel = 'Все',
    minW = '200px',
    disabled = false,
}) {
    const [query, setQuery] = useState('');
    const selectedSet = useMemo(() => new Set(selectedIds.map(String)), [selectedIds]);

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return options;
        return options.filter((o) => String(o[labelKey] ?? '').toLowerCase().includes(q));
    }, [options, query, labelKey]);

    const summary = selectedIds.length === 0
        ? allLabel
        : selectedIds.length === 1
            ? (options.find((o) => String(o[idKey]) === String(selectedIds[0]))?.[labelKey] ?? '1')
            : `${selectedIds.length} выбрано`;

    const toggle = (id) => {
        const sid = String(id);
        const next = selectedSet.has(sid)
            ? selectedIds.filter((x) => String(x) !== sid)
            : [...selectedIds, id];
        onChange(next);
    };

    return (
        <VStack align="stretch" gap={1} flex="1" minW={minW}>
            {label && <Text fontSize="xs" color="fg.muted" fontWeight="500">{label}</Text>}
            <Popover.Root positioning={{ sameWidth: true, placement: 'bottom-start' }}>
                <Popover.Trigger asChild>
                    <Button
                        variant="outline"
                        size="sm"
                        justifyContent="space-between"
                        fontWeight="500"
                        bg="bg"
                        disabled={disabled}
                    >
                        <Text lineClamp={1}>{summary}</Text>
                        <LuChevronDown />
                    </Button>
                </Popover.Trigger>
                <Portal>
                    <Popover.Positioner>
                        <Popover.Content>
                            <Popover.Body p={2}>
                                {options.length === 0 ? (
                                    <Text fontSize="sm" color="fg.muted" p={2}>Нет вариантов</Text>
                                ) : (
                                    <VStack align="stretch" gap={2}>
                                        <HStack gap={2} px={1}>
                                            <LuSearch size={14} />
                                            <Input
                                                size="xs"
                                                variant="flushed"
                                                placeholder="Поиск…"
                                                value={query}
                                                onChange={(e) => setQuery(e.target.value)}
                                            />
                                        </HStack>
                                        {selectedIds.length > 0 && (
                                            <Button size="xs" variant="ghost" onClick={() => onChange([])} justifyContent="flex-start">
                                                Сбросить выбор
                                            </Button>
                                        )}
                                        <Box maxH="300px" overflowY="auto">
                                            <VStack align="stretch" gap={1}>
                                                {filtered.length === 0 ? (
                                                    <Text fontSize="sm" color="fg.muted" p={2}>Ничего не найдено</Text>
                                                ) : filtered.map((opt) => (
                                                    <Checkbox
                                                        key={opt[idKey]}
                                                        checked={selectedSet.has(String(opt[idKey]))}
                                                        onCheckedChange={() => toggle(opt[idKey])}
                                                        size="sm"
                                                    >
                                                        <Text fontSize="sm" lineClamp={2}>{opt[labelKey]}</Text>
                                                    </Checkbox>
                                                ))}
                                            </VStack>
                                        </Box>
                                    </VStack>
                                )}
                            </Popover.Body>
                        </Popover.Content>
                    </Popover.Positioner>
                </Portal>
            </Popover.Root>
        </VStack>
    );
}
