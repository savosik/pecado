import { useState } from 'react';
import { Box, Card, Stack, Input, Button, Table, IconButton } from '@chakra-ui/react';
import { FormField, SelectRelation } from '@/Admin/Components';
import { LuPlus, LuTrash2 } from 'react-icons/lu';

export function AttributesSection({ attributes = [], availableAttributes = [], onChange, error }) {
    const [selectedAttributeId, setSelectedAttributeId] = useState(null);
    const [attributeValue, setAttributeValue] = useState('');
    const [selectedValueId, setSelectedValueId] = useState(null);

    const attributeOptions = availableAttributes.map(attr => ({
        value: attr.id,
        label: attr.name
    }));

    // Получить текущий выбранный атрибут
    const selectedAttribute = availableAttributes.find(a => a.id === selectedAttributeId);
    const hasPredefinedValues = selectedAttribute && selectedAttribute.values && selectedAttribute.values.length > 0;

    // Опции для predefined values
    const valueOptions = hasPredefinedValues
        ? selectedAttribute.values.map(v => ({ value: v.id, label: v.value }))
        : [];

    const handleAdd = () => {
        if (!selectedAttributeId) return;

        const attribute = availableAttributes.find(a => a.id === selectedAttributeId);
        if (!attribute) return;

        // Создать новое значение атрибута
        const newAttrValue = {
            attribute_id: selectedAttributeId,
            attribute_name: attribute.name,
            attribute_value_id: selectedValueId || null,
            text_value: !hasPredefinedValues ? attributeValue : null,
            number_value: null,
            boolean_value: null,
        };

        // Если есть predefined value, получить его текст
        if (hasPredefinedValues && selectedValueId) {
            const valueObj = selectedAttribute.values.find(v => v.id === selectedValueId);
            newAttrValue.text_value = valueObj ? valueObj.value : null;
        }

        onChange([...attributes, newAttrValue]);

        // Сбросить форму
        setSelectedAttributeId(null);
        setAttributeValue('');
        setSelectedValueId(null);
    };

    const handleRemove = (index) => {
        onChange(attributes.filter((_, i) => i !== index));
    };

    // Получить display value для атрибута
    const getDisplayValue = (attr) => {
        return attr.text_value || attr.number_value || (attr.boolean_value ? 'Да' : 'Нет') || '-';
    };

    return (
        <Card.Root>
            <Card.Header>
                <Box fontSize="lg" fontWeight="semibold">
                    Атрибуты товара
                </Box>
            </Card.Header>
            <Card.Body>
                <Stack gap={4}>
                    {/* Таблица с текущими атрибутами */}
                    {attributes.length > 0 && (
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Атрибут</Table.ColumnHeader>
                                        <Table.ColumnHeader>Значение</Table.ColumnHeader>
                                        <Table.ColumnHeader width="80px">Действия</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {attributes.map((attr, index) => (
                                        <Table.Row key={index}>
                                            <Table.Cell fontWeight="medium">{attr.attribute_name}</Table.Cell>
                                            <Table.Cell>{getDisplayValue(attr)}</Table.Cell>
                                            <Table.Cell>
                                                <IconButton
                                                    aria-label="Удалить атрибут"
                                                    size="sm"
                                                    colorPalette="red"
                                                    variant="ghost"
                                                    onClick={() => handleRemove(index)}
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

                    {/* Форма добавления нового атрибута */}
                    <Box borderTopWidth="1px" pt={4}>
                        <Box fontSize="md" fontWeight="semibold" mb={3}>
                            Добавить атрибут
                        </Box>
                        <Stack gap={3}>
                            <SelectRelation
                                label="Атрибут"
                                value={selectedAttributeId}
                                onChange={(value) => {
                                    setSelectedAttributeId(value);
                                    setSelectedValueId(null);
                                    setAttributeValue('');
                                }}
                                options={attributeOptions}
                                placeholder="Выберите атрибут"
                            />

                            {selectedAttributeId && (
                                <>
                                    {hasPredefinedValues ? (
                                        <SelectRelation
                                            label="Значение"
                                            value={selectedValueId}
                                            onChange={setSelectedValueId}
                                            options={valueOptions}
                                            placeholder="Выберите значение"
                                        />
                                    ) : (
                                        <FormField label="Значение">
                                            <Input
                                                value={attributeValue}
                                                onChange={(e) => setAttributeValue(e.target.value)}
                                                placeholder="Введите значение атрибута"
                                            />
                                        </FormField>
                                    )}
                                </>
                            )}

                            <Box>
                                <Button
                                    onClick={handleAdd}
                                    colorPalette="blue"
                                    disabled={
                                        !selectedAttributeId ||
                                        (hasPredefinedValues ? !selectedValueId : !attributeValue)
                                    }
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
