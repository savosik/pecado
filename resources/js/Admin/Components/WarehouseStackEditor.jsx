import React from "react";
import { Box, Flex, HStack, IconButton, Stack, Text } from "@chakra-ui/react";
import { LuArrowDown, LuArrowUp } from "react-icons/lu";
import { Switch } from "@/components/ui/switch";

/**
 * WarehouseStackEditor — настройка стопки складов региона.
 *
 * Переключатель режима + упорядоченный список выбранных primary-складов
 * с кнопками «вверх/вниз». Порядок массива warehouseIds = позиция в стопке
 * (первый — верхний, он замещает остатки и цены нижних).
 *
 * @param {boolean} enabled - Режим стопки включён
 * @param {Function} onToggle - Callback переключателя (boolean)
 * @param {number[]} warehouseIds - Выбранные primary-склады в порядке стопки
 * @param {Array} warehouses - Справочник складов [{id, name}]
 * @param {Function} onReorder - Callback нового порядка (number[])
 */
export const WarehouseStackEditor = ({ enabled, onToggle, warehouseIds, warehouses, onReorder }) => {
    const nameById = new Map(warehouses.map((w) => [w.id, w.name]));

    const move = (index, delta) => {
        const target = index + delta;
        if (target < 0 || target >= warehouseIds.length) return;
        const next = [...warehouseIds];
        [next[index], next[target]] = [next[target], next[index]];
        onReorder(next);
    };

    return (
        <Stack gap={3}>
            <Switch checked={enabled} onCheckedChange={(e) => onToggle(e.checked)}>
                Режим стопки складов
            </Switch>

            {enabled && (
                <Box>
                    <Text fontSize="sm" color="fg.muted" mb={2}>
                        Верхний склад замещает собой остатки и цены нижних по товарам,
                        которые на нём в наличии. Чего нет наверху — берётся со склада ниже.
                    </Text>

                    {warehouseIds.length === 0 ? (
                        <Text fontSize="sm" color="fg.muted">
                            Выберите склады наличия — они появятся здесь в порядке стопки.
                        </Text>
                    ) : (
                        <Stack gap={1}>
                            {warehouseIds.map((id, index) => (
                                <Flex
                                    key={id}
                                    align="center"
                                    justify="space-between"
                                    borderWidth="1px"
                                    borderRadius="md"
                                    px={3}
                                    py={1.5}
                                >
                                    <HStack gap={3}>
                                        <Text fontWeight="semibold" color="fg.muted" minW="6">
                                            {index + 1}
                                        </Text>
                                        <Text>{nameById.get(id) ?? `Склад #${id}`}</Text>
                                    </HStack>
                                    <HStack gap={1}>
                                        <IconButton
                                            aria-label="Поднять склад выше"
                                            size="xs"
                                            variant="ghost"
                                            disabled={index === 0}
                                            onClick={() => move(index, -1)}
                                        >
                                            <LuArrowUp />
                                        </IconButton>
                                        <IconButton
                                            aria-label="Опустить склад ниже"
                                            size="xs"
                                            variant="ghost"
                                            disabled={index === warehouseIds.length - 1}
                                            onClick={() => move(index, 1)}
                                        >
                                            <LuArrowDown />
                                        </IconButton>
                                    </HStack>
                                </Flex>
                            ))}
                        </Stack>
                    )}
                </Box>
            )}
        </Stack>
    );
};
