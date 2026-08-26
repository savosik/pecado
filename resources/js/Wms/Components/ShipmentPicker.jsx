import { useState } from 'react';
import { Badge, Box, HStack, IconButton, Text, VStack } from '@chakra-ui/react';
import {
    LuArchive,
    LuArchiveRestore,
    LuChevronDown,
    LuChevronRight,
    LuCircleAlert,
    LuMapPin,
    LuPackage,
    LuRotateCcw,
    LuTriangleAlert,
} from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';
import { formatMoney, formatWeight } from './deliveryFormat';

/**
 * Выбор реализаций для отправки: группы-аккордеоны, внутри — строки с подсказками.
 *
 * Плоский список номеров кладовщику бесполезен: по «РЕА-1234» не понять, на какой
 * стадии заказы, собран ли груз, куда он едет и не пытались ли его уже отправить.
 * Разрез группировки задаёт родитель — по клиенту, адресу, дате или способу доставки.
 *
 * Ограничение «одна отправка — один клиент» работает построчно, а не по группе:
 * в разрезе по адресу или дате в одной группе законно оказываются разные клиенты.
 */
export function ShipmentPicker({ clients, selectedIds, selectedUserId, onToggle, onSelectGroup, onToggleHidden }) {
    // Состояние храним только для тех групп, которые кладовщик трогал руками.
    // Остальные подчиняются умолчанию, и оно зависит от длины списка: две группы
    // разумно показать сразу, два десятка — это стена, которую надо разворачивать.
    const [overrides, setOverrides] = useState({});
    const defaultOpen = clients.length <= 3;

    // Сворачивается любая группа, включая ту, где уже что-то выбрано: запрет
    // читался бы как поломка. Чтобы выбор не терялся из виду, счётчик выбранного
    // висит в шапке группы.
    const isOpen = (group) => overrides[group.key] ?? defaultOpen;

    const toggleGroup = (group) => {
        setOverrides((prev) => ({ ...prev, [group.key]: !(prev[group.key] ?? defaultOpen) }));
    };

    return (
        <VStack align="stretch" gap={3}>
            {clients.map((group) => {
                const groupIds = group.shipments.map((item) => item.id);
                const selectedCount = groupIds.filter((id) => selectedIds.includes(id)).length;
                const allSelected = selectedCount === groupIds.length;
                // Группу целиком можно взять только если она вся одного клиента
                // и он не конфликтует с уже выбранным.
                const groupClientIds = [...new Set(group.shipments.map((item) => item.user_id))];
                const groupSelectable = groupClientIds.length === 1
                    && (selectedUserId === null || groupClientIds[0] === selectedUserId);

                const open = isOpen(group);

                return (
                    <Box key={group.key} borderWidth="1px" borderColor={selectedCount > 0 ? 'colorPalette.solid' : 'border'} borderRadius="md">
                        <HStack
                            justify="space-between"
                            px={3}
                            py={2}
                            bg="bg.subtle"
                            cursor="pointer"
                            onClick={() => toggleGroup(group)}
                            flexWrap="wrap"
                            gap={2}
                        >
                            <HStack gap={2} minW={0} flex="1">
                                <Box color="fg.muted">{open ? <LuChevronDown size={16} /> : <LuChevronRight size={16} />}</Box>
                                <VStack align="start" gap={0} minW={0}>
                                    <HStack gap={2} flexWrap="wrap">
                                        <Text fontSize="sm" fontWeight="bold" lineClamp={1}>{group.title}</Text>
                                        <Badge size="sm" variant="subtle">{group.shipments_count}</Badge>
                                        {selectedCount > 0 && (
                                            <Badge size="sm" colorPalette="green">выбрано: {selectedCount}</Badge>
                                        )}
                                    </HStack>
                                    {group.subtitle && (
                                        <Text fontSize="xs" color="fg.muted" lineClamp={1}>{group.subtitle}</Text>
                                    )}
                                </VStack>
                            </HStack>

                            <HStack gap={4} fontSize="xs" color="fg.muted" flexShrink={0}>
                                <Text>{formatWeight(group.total_weight)}</Text>
                                <Text>{formatMoney(group.total_amount)}</Text>
                                {groupSelectable && group.shipments_count > 1 && (
                                    <Text
                                        color="colorPalette.fg"
                                        onClick={(event) => {
                                            event.stopPropagation();
                                            onSelectGroup(group, !allSelected);
                                        }}
                                    >
                                        {allSelected ? 'снять все' : 'выбрать все'}
                                    </Text>
                                )}
                            </HStack>
                        </HStack>

                        {open && (
                            <VStack align="stretch" gap={0}>
                                {group.shipments.map((shipment) => (
                                    <ShipmentRow
                                        key={shipment.id}
                                        shipment={shipment}
                                        checked={selectedIds.includes(shipment.id)}
                                        blocked={selectedUserId !== null && shipment.user_id !== selectedUserId}
                                        onToggle={() => onToggle(shipment)}
                                        onToggleHidden={onToggleHidden}
                                    />
                                ))}
                            </VStack>
                        )}
                    </Box>
                );
            })}
        </VStack>
    );
}

function ShipmentRow({ shipment, checked, blocked, onToggle, onToggleHidden }) {
    const goodsIssue = shipment.goods_issue;

    return (
        <HStack
            align="start"
            gap={3}
            px={3}
            py={2}
            borderTopWidth="1px"
            borderColor="border"
            bg={checked ? 'bg.emphasized' : undefined}
            opacity={blocked ? 0.45 : 1}
            cursor={blocked ? 'not-allowed' : 'pointer'}
            onClick={() => !blocked && onToggle()}
        >
            {/*
                Чекбокс намеренно не перехватывает клик: обработчик один — на строке.
                Два обработчика (свой у чекбокса и всплывший на строку) отрабатывали
                друг за другом и возвращали галку в исходное состояние.
            */}
            <Box pt={1} pointerEvents="none">
                <Checkbox size="sm" checked={checked} disabled={blocked} />
            </Box>

            <VStack align="stretch" gap={1} flex="1" minW={0}>
                <HStack gap={2} flexWrap="wrap">
                    <Text fontSize="sm" fontWeight="medium">{shipment.number}</Text>
                    <Text fontSize="xs" color="fg.muted">{shipment.date_label}</Text>

                    {/* Статусы заказов: главный сигнал «можно ли вообще везти». */}
                    {shipment.order_statuses.map((status) => (
                        <Badge key={status.value} size="sm" colorPalette={status.color} variant="subtle">
                            {status.label}
                            {status.count > 1 && ` ×${status.count}`}
                        </Badge>
                    ))}

                    {/* Состояние сборки: собран ли груз физически. */}
                    {goodsIssue ? (
                        <Tooltip content={`Расходный ордер ${goodsIssue.number}${goodsIssue.is_stale ? ' — висит в статусе дольше суток' : ''}`}>
                            <Badge size="sm" colorPalette={goodsIssue.status_color}>
                                <LuPackage size={11} /> {goodsIssue.status_label}
                                {goodsIssue.is_stale && <LuTriangleAlert size={11} />}
                            </Badge>
                        </Tooltip>
                    ) : (
                        <Tooltip content="1С не присылала расходный ордер по этим заказам — собран ли груз, по системе не видно">
                            <Badge size="sm" variant="outline" colorPalette="gray">без ордера</Badge>
                        </Tooltip>
                    )}

                    {shipment.previous_delivery && (
                        <Tooltip content={`Реализация уже была в отправке ${shipment.previous_delivery.number} — ${shipment.previous_delivery.status_label.toLowerCase()}`}>
                            <Badge size="sm" colorPalette={shipment.previous_delivery.status_color} variant="outline">
                                <LuRotateCcw size={11} /> {shipment.previous_delivery.number}
                            </Badge>
                        </Tooltip>
                    )}

                    {shipment.hidden && (
                        <Tooltip content={`Скрыл ${shipment.hidden.by || 'сотрудник'}${shipment.hidden.reason ? `: ${shipment.hidden.reason}` : ''}`}>
                            <Badge size="sm" colorPalette="gray" variant="outline">
                                <LuArchive size={11} /> скрыта
                            </Badge>
                        </Tooltip>
                    )}
                </HStack>

                {/* Куда везём — по данным заказа. */}
                {(shipment.delivery_method_label || shipment.delivery_address) && (
                    <HStack gap={1} align="start" fontSize="xs" color="fg.muted" minW={0}>
                        <Box pt="2px" flexShrink={0}><LuMapPin size={11} /></Box>
                        <Text lineClamp={1}>
                            {shipment.delivery_method_label && (
                                <Text as="span" fontWeight="medium">{shipment.delivery_method_label}</Text>
                            )}
                            {shipment.delivery_method_label && shipment.delivery_address && ' · '}
                            {shipment.delivery_address}
                        </Text>
                    </HStack>
                )}

                {shipment.orders.length > 0 && (
                    <Text fontSize="xs" color="fg.muted" lineClamp={1}>
                        Заказы: {shipment.orders.map((order) => order.number).filter(Boolean).join(', ') || '—'}
                        {shipment.delivery_kind === 'mixed' && ' · способы доставки различаются'}
                    </Text>
                )}
            </VStack>

            <VStack align="end" gap={0} flexShrink={0}>
                <Text fontSize="sm" fontVariantNumeric="tabular-nums">{formatMoney(shipment.amount)}</Text>
                <HStack gap={1}>
                    <Text fontSize="xs" color="fg.muted" fontVariantNumeric="tabular-nums">
                        {formatWeight(shipment.weight)}
                    </Text>
                    {shipment.weightless_items.length > 0 && (
                        <Tooltip content={`Вес по умолчанию у ${shipment.weightless_items.length} позиций: ${shipment.weightless_items.slice(0, 5).join(', ')}`}>
                            <Box color="orange.500" display="flex"><LuCircleAlert size={12} /></Box>
                        </Tooltip>
                    )}
                </HStack>
                <Text fontSize="xs" color="fg.muted">{shipment.items_count} поз.</Text>
            </VStack>

            {/* Скрытие — это «убрать в архив», а не просмотр: глаз здесь запутывал бы. */}
            <Tooltip content={shipment.hidden ? 'Вернуть в список' : 'Убрать из списка'}>
                <IconButton
                    size="xs"
                    variant="ghost"
                    color="fg.muted"
                    aria-label={shipment.hidden ? 'Вернуть в список' : 'Убрать из списка'}
                    onClick={(event) => {
                        event.stopPropagation();
                        onToggleHidden(shipment);
                    }}
                >
                    {shipment.hidden ? <LuArchiveRestore /> : <LuArchive />}
                </IconButton>
            </Tooltip>
        </HStack>
    );
}
