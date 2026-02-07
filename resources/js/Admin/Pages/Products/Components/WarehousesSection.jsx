import { useState } from 'react';
import { Box, Card, Stack, Input, Button, Table, IconButton } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { SelectRelation } from '@/Admin/Components';
import { LuPlus, LuTrash2 } from 'react-icons/lu';

export function WarehousesSection({ warehouses = [], availableWarehouses = [], onChange, error }) {
    const [selectedWarehouseId, setSelectedWarehouseId] = useState(null);
    const [quantity, setQuantity] = useState(0);

    const warehouseOptions = availableWarehouses.map(w => ({ value: w.id, label: w.name }));

    const handleAdd = () => {
        if (!selectedWarehouseId || quantity < 0) return;

        // Проверить, не добавлен ли уже этот склад
        if (warehouses.find(w => w.id === selectedWarehouseId)) {
            return;
        }

        const warehouse = availableWarehouses.find(w => w.id === selectedWarehouseId);
        if (!warehouse) return;

        onChange([...warehouses, {
            id: selectedWarehouseId,
            name: warehouse.name,
            quantity: parseInt(quantity, 10),
        }]);

        // Сбросить форму
        setSelectedWarehouseId(null);
        setQuantity(0);
    };

    const handleRemove = (warehouseId) => {
        onChange(warehouses.filter(w => w.id !== warehouseId));
    };

    const handleQuantityChange = (warehouseId, newQuantity) => {
        onChange(warehouses.map(w =>
            w.id === warehouseId ? { ...w, quantity: parseInt(newQuantity, 10) || 0 } : w
        ));
    };

    return (
        <Card.Root>
            <Card.Header>
                <Box fontSize="lg" fontWeight="semibold">
                    Склады и остатки
                </Box>
            </Card.Header>
            <Card.Body>
                <Stack gap={4}>
                    {/* Таблица с текущими складами */}
                    {warehouses.length > 0 && (
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Склад</Table.ColumnHeader>
                                        <Table.ColumnHeader>Количество</Table.ColumnHeader>
                                        <Table.ColumnHeader width="80px">Действия</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {warehouses.map((warehouse) => (
                                        <Table.Row key={warehouse.id}>
                                            <Table.Cell>{warehouse.name}</Table.Cell>
                                            <Table.Cell>
                                                <Input
                                                    type="number"
                                                    value={warehouse.quantity}
                                                    onChange={(e) => handleQuantityChange(warehouse.id, e.target.value)}
                                                    min="0"
                                                    width="120px"
                                                />
                                            </Table.Cell>
                                            <Table.Cell>
                                                <IconButton
                                                    aria-label="Удалить склад"
                                                    size="sm"
                                                    colorPalette="red"
                                                    variant="ghost"
                                                    onClick={() => handleRemove(warehouse.id)}
                                                >
                                                    <LuTrash2 />
                                                </IconButton>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}

                    {/* Форма добавления нового склада */}
                    <Box borderTopWidth="1px" pt={4}>
                        <Box fontSize="md" fontWeight="semibold" mb={3}>
                            Добавить склад
                        </Box>
                        <Stack direction={{ base: 'column', md: 'row' }} gap={3}>
                            <Box flex="2">
                                <SelectRelation
                                    value={selectedWarehouseId}
                                    onChange={setSelectedWarehouseId}
                                    options={warehouseOptions}
                                    placeholder="Выберите склад"
                                />
                            </Box>
                            <Box flex="1">
                                <Field label="Количество">
                                    <Input
                                        type="number"
                                        value={quantity}
                                        onChange={(e) => setQuantity(e.target.value)}
                                        min="0"
                                        placeholder="0"
                                    />
                                </Field>
                            </Box>
                            <Box>
                                <Button
                                    onClick={handleAdd}
                                    colorPalette="blue"
                                    disabled={!selectedWarehouseId || quantity < 0}
                                    mt={{ base: 0, md: '28px' }}
                                >
                                    <LuPlus /> Добавить
                                </Button>
                            </Box>
                        </Stack>
                    </Box>

                    {error && (
                        <Box color="red.500" fontSize="sm">
                            {error}
                        </Box>
                    )}
                </Stack>
            </Card.Body>
        </Card.Root>
    );
}
