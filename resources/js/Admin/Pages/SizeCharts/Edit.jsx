import { useForm } from '@inertiajs/react';
import { AdminLayout } from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, SelectRelation } from '@/Admin/Components';
import {
    Box, Card, Input, Stack, SimpleGrid, Button,
    HStack, Text, IconButton, Table,
    Heading
} from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';
import { LuPlus, LuTrash2 } from 'react-icons/lu';
import { useMemo } from 'react';

const MEASUREMENT_COLUMNS = [
    { key: 'bust', label: 'Грудь' },
    { key: 'underbust', label: 'Под грудью' },
    { key: 'waist', label: 'Талия' },
    { key: 'hips', label: 'Бедра' },
    { key: 'hip_girth', label: 'Обхват бедра' },
    { key: 'shoulder', label: 'Плечо' },
];

export default function Edit({ sizeChart, brands }) {
    const { data, setData, put, processing, errors } = useForm({
        name: sizeChart.name || '',
        uuid: sizeChart.uuid || '',
        brand_ids: sizeChart.brand_ids || [],
        values: sizeChart.values || [{ size: '' }],
    });

    const activeColumns = useMemo(() => {
        const active = new Set();
        // Защита от потенциально неправильного формата данных
        const valuesRows = Array.isArray(data.values)
            ? data.values
            : (typeof data.values === 'string' ? JSON.parse(data.values) : []);

        valuesRows.forEach(row => {
            if (row && typeof row === 'object') {
                Object.keys(row).forEach(key => {
                    if (MEASUREMENT_COLUMNS.some(c => c.key === key)) {
                        active.add(key);
                    }
                });
            }
        });
        return active;
    }, [data.values]);

    const brandOptions = useMemo(() =>
        brands.map(b => ({ value: b.id, label: b.name })),
        [brands]);

    const handleSubmit = (e) => {
        e.preventDefault();
        put(route('admin.size-charts.update', sizeChart.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Сетка обновлена',
                    type: 'success',
                });
            },
        });
    };

    const addRow = () => {
        const newRow = { size: '' };
        activeColumns.forEach(col => {
            newRow[col] = '';
        });
        setData('values', [...data.values, newRow]);
    };

    const removeRow = (index) => {
        const newValues = [...data.values];
        newValues.splice(index, 1);
        setData('values', newValues);
    };

    const updateCell = (index, key, value) => {
        const newValues = [...data.values];
        newValues[index] = { ...newValues[index], [key]: value };
        setData('values', newValues);
    };

    const toggleColumn = (colKey, isChecked) => {
        const currentValues = Array.isArray(data.values) ? data.values : [];
        const newValues = currentValues.map(row => {
            const newRow = { ...row };
            if (isChecked) {
                if (!(colKey in newRow)) {
                    newRow[colKey] = '';
                }
            } else {
                delete newRow[colKey];
            }
            return newRow;
        });
        setData('values', newValues);
    };

    return (
        <AdminLayout>
            <Box p={6}>
                <PageHeader title="Редактировать размерную сетку" description={`Изменение сетки: ${sizeChart.name}`} />

                <form onSubmit={handleSubmit}>
                    <Stack gap={6}>
                        <Card.Root>
                            <Card.Header>
                                <Heading size="md">Основная информация</Heading>
                            </Card.Header>
                            <Card.Body>
                                <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                    <FormField label="Название *" error={errors.name}>
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Например: Женское белье"
                                        />
                                    </FormField>

                                    <FormField label="Внешний ID (UUID)" error={errors.uuid}>
                                        <Input
                                            value={data.uuid}
                                            onChange={(e) => setData('uuid', e.target.value)}
                                            placeholder="Введите внешний ID, если есть"
                                        />
                                    </FormField>

                                    <Box gridColumn={{ base: '1', md: 'span 2' }}>
                                        <SelectRelation
                                            label="Бренды"
                                            value={data.brand_ids}
                                            onChange={(val) => setData('brand_ids', val)}
                                            options={brandOptions}
                                            multiple
                                            placeholder="Выберите бренды"
                                            error={errors.brand_ids}
                                        />
                                    </Box>
                                </SimpleGrid>
                            </Card.Body>
                        </Card.Root>

                        <Card.Root>
                            <Card.Header>
                                <HStack justify="space-between">
                                    <Heading size="md">Таблица размеров</Heading>
                                    <Button size="sm" variant="outline" onClick={addRow}>
                                        <LuPlus /> Добавить размер
                                    </Button>
                                </HStack>
                            </Card.Header>
                            <Card.Body>
                                <Box mb={6} p={4} borderRadius="md" borderWidth="1px" bg="bg.subtle">
                                    <Text fontWeight="medium" mb={3} fontSize="sm">Видимые измерения:</Text>
                                    <HStack gap={6} flexWrap="wrap">
                                        {MEASUREMENT_COLUMNS.map(col => (
                                            <Checkbox
                                                key={col.key}
                                                checked={activeColumns.has(col.key)}
                                                onCheckedChange={(e) => toggleColumn(col.key, !!e.checked)}
                                            >
                                                {col.label}
                                            </Checkbox>
                                        ))}
                                    </HStack>
                                </Box>

                                <Box overflowX="auto" border="1px solid" borderColor="border.muted" borderRadius="md">
                                    <Table.Root size="sm">
                                        <Table.Header>
                                            <Table.Row bg="bg.muted">
                                                <Table.ColumnHeader width="120px">Размер *</Table.ColumnHeader>
                                                {MEASUREMENT_COLUMNS.filter(c => activeColumns.has(c.key)).map(col => (
                                                    <Table.ColumnHeader key={col.key}>{col.label}</Table.ColumnHeader>
                                                ))}
                                                <Table.ColumnHeader width="50px"></Table.ColumnHeader>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {data.values.map((row, index) => (
                                                <Table.Row key={index}>
                                                    <Table.Cell>
                                                        <Input
                                                            size="xs"
                                                            variant="subtle"
                                                            value={row.size}
                                                            onChange={(e) => updateCell(index, 'size', e.target.value)}
                                                            placeholder="Напр. S"
                                                        />
                                                    </Table.Cell>
                                                    {MEASUREMENT_COLUMNS.filter(c => activeColumns.has(c.key)).map(col => (
                                                        <Table.Cell key={col.key}>
                                                            <Input
                                                                size="xs"
                                                                variant="subtle"
                                                                value={row[col.key] || ''}
                                                                onChange={(e) => updateCell(index, col.key, e.target.value)}
                                                                placeholder="84-88"
                                                            />
                                                        </Table.Cell>
                                                    ))}
                                                    <Table.Cell>
                                                        <IconButton
                                                            size="xs"
                                                            variant="ghost"
                                                            colorPalette="red"
                                                            onClick={() => removeRow(index)}
                                                            aria-label="Удалить строку"
                                                        >
                                                            <LuTrash2 />
                                                        </IconButton>
                                                    </Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                                {errors.values && (
                                    <Text color="red.500" fontSize="xs" mt={2}>{errors.values}</Text>
                                )}
                            </Card.Body>
                            <Card.Footer>
                                <FormActions
                                    isLoading={processing}
                                    onCancel={() => window.history.back()}
                                    submitLabel="Сохранить изменения"
                                />
                            </Card.Footer>
                        </Card.Root>
                    </Stack>
                </form>
            </Box>
        </AdminLayout>
    );
}
