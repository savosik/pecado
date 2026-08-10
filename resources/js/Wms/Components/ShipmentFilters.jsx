import { Box, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuFilterX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { SearchInput } from '@/Admin/Components/SearchInput';
import MultiSelectFilter from '@/Crm/Components/MultiSelectFilter';

/**
 * Панель отбора реализаций: поиск, разрез группировки, сортировка и фильтры.
 *
 * Фильтры по статусам заказов и расходных ордеров уходят в SQL — иначе запрос
 * «покажи всё готовое к отгрузке» упирался бы в потолок выборки и находил только
 * то, что попало в первые строки. Адрес и способ доставки собираются из заказов
 * уже после выборки, поэтому и отсекаются там же.
 */
export function ShipmentFilters({ filters, options, onChange, onReset, meta }) {
    const set = (patch) => onChange({ ...filters, ...patch });

    const activeCount = [
        filters.client_ids?.length,
        filters.warehouse_ids?.length,
        filters.order_statuses?.length,
        filters.goods_issue_statuses?.length,
        filters.delivery_kinds?.length,
        filters.address ? 1 : 0,
        filters.date_from ? 1 : 0,
        filters.date_to ? 1 : 0,
        filters.amount_from ? 1 : 0,
        filters.amount_to ? 1 : 0,
        filters.only_without_goods_issue ? 1 : 0,
        filters.only_retry ? 1 : 0,
    ].reduce((sum, value) => sum + (value || 0), 0);

    return (
        <VStack align="stretch" gap={3}>
            <HStack gap={2} flexWrap="wrap" align="end">
                <Box flex="1" minW={{ base: '100%', md: '260px' }}>
                    <SearchInput
                        value={filters.search || ''}
                        onChange={(value) => set({ search: value })}
                        placeholder="Номер реализации, клиент, заказ..."
                    />
                </Box>

                <VStack align="stretch" gap={1} minW="180px">
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Группировать</Text>
                    <NativeSelectRoot size="sm">
                        <NativeSelectField
                            value={filters.group_by}
                            onChange={(event) => set({ group_by: event.target.value })}
                        >
                            {options.groupBy.map((item) => (
                                <option key={item.value} value={item.value}>{item.label}</option>
                            ))}
                        </NativeSelectField>
                    </NativeSelectRoot>
                </VStack>

                <VStack align="stretch" gap={1} minW="180px">
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Сортировка</Text>
                    <NativeSelectRoot size="sm">
                        <NativeSelectField
                            value={filters.row_sort}
                            onChange={(event) => set({ row_sort: event.target.value })}
                        >
                            {options.sorts.map((item) => (
                                <option key={item.value} value={item.value}>{item.label}</option>
                            ))}
                        </NativeSelectField>
                    </NativeSelectRoot>
                </VStack>

                {activeCount > 0 && (
                    <Button size="sm" variant="ghost" onClick={onReset}>
                        <LuFilterX /> Сбросить ({activeCount})
                    </Button>
                )}
            </HStack>

            <HStack gap={2} flexWrap="wrap" align="end">
                <MultiSelectFilter
                    label="Статус заказа"
                    options={options.orderStatuses}
                    selectedIds={filters.order_statuses || []}
                    onChange={(next) => set({ order_statuses: next })}
                    idKey="value"
                    labelKey="label"
                    allLabel="Любой статус"
                    minW="200px"
                />

                <MultiSelectFilter
                    label="Статус расходного ордера"
                    options={options.goodsIssueStatuses}
                    selectedIds={filters.goods_issue_statuses || []}
                    onChange={(next) => set({ goods_issue_statuses: next })}
                    idKey="value"
                    labelKey="label"
                    allLabel="Любой статус"
                    minW="220px"
                />

                <MultiSelectFilter
                    label="Способ доставки"
                    options={options.deliveryKinds}
                    selectedIds={filters.delivery_kinds || []}
                    onChange={(next) => set({ delivery_kinds: next })}
                    idKey="value"
                    labelKey="label"
                    allLabel="Любой способ"
                    minW="180px"
                />

                <MultiSelectFilter
                    label="Склад"
                    options={options.warehouses}
                    selectedIds={filters.warehouse_ids || []}
                    onChange={(next) => set({ warehouse_ids: next })}
                    idKey="value"
                    labelKey="label"
                    allLabel="Все склады"
                    minW="180px"
                    disabled={options.warehouses.length === 0}
                />
            </HStack>

            <HStack gap={2} flexWrap="wrap" align="end">
                <Field label="Адрес содержит" width="240px">
                    <Input
                        size="sm"
                        value={filters.address || ''}
                        onChange={(event) => set({ address: event.target.value })}
                        placeholder="Москва, Правды..."
                    />
                </Field>

                <VStack align="stretch" gap={1} minW="230px">
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Дата реализации</Text>
                    <HStack gap={1}>
                        <Input
                            size="sm"
                            type="date"
                            value={filters.date_from || ''}
                            onChange={(event) => set({ date_from: event.target.value })}
                        />
                        <Input
                            size="sm"
                            type="date"
                            value={filters.date_to || ''}
                            onChange={(event) => set({ date_to: event.target.value })}
                        />
                    </HStack>
                </VStack>

                <VStack align="stretch" gap={1} minW="200px">
                    <Text fontSize="xs" color="fg.muted" fontWeight="500">Сумма, ₽</Text>
                    <HStack gap={1}>
                        <Input
                            size="sm"
                            type="number"
                            value={filters.amount_from || ''}
                            onChange={(event) => set({ amount_from: event.target.value })}
                            placeholder="от"
                        />
                        <Input
                            size="sm"
                            type="number"
                            value={filters.amount_to || ''}
                            onChange={(event) => set({ amount_to: event.target.value })}
                            placeholder="до"
                        />
                    </HStack>
                </VStack>
            </HStack>

            <HStack gap={4} flexWrap="wrap">
                <Checkbox
                    size="sm"
                    checked={!!filters.only_without_goods_issue}
                    onCheckedChange={() => set({ only_without_goods_issue: !filters.only_without_goods_issue })}
                >
                    <Text fontSize="sm">Без расходного ордера</Text>
                </Checkbox>

                <Checkbox
                    size="sm"
                    checked={!!filters.only_retry}
                    onCheckedChange={() => set({ only_retry: !filters.only_retry })}
                >
                    <Text fontSize="sm">Отправлялись ранее (отменены)</Text>
                </Checkbox>

                <Checkbox
                    size="sm"
                    checked={!!filters.show_hidden}
                    onCheckedChange={() => set({ show_hidden: !filters.show_hidden })}
                >
                    <Text fontSize="sm">
                        Показать скрытые
                        {meta.hidden_count > 0 && ` (${meta.hidden_count})`}
                    </Text>
                </Checkbox>
            </HStack>

            {meta.capped && (
                <Text fontSize="xs" color="orange.500">
                    Под фильтр подходит {meta.matched} реализаций, загружено {meta.loaded}.
                    Отбор по адресу и способу доставки идёт по загруженным строкам — уточните
                    поиск или период, если нужного нет в списке.
                </Text>
            )}
        </VStack>
    );
}
