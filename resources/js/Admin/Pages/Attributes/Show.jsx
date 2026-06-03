import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

const TYPE_LABELS = {
    string: 'Строка',
    number: 'Число',
    boolean: 'Логический',
    select: 'Список',
};

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

function BoolRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Badge colorPalette={value ? 'green' : 'gray'} variant="subtle">
                {value ? 'Да' : 'Нет'}
            </Badge>
        </Box>
    );
}

export default function Show() {
    const { attribute } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Атрибут: ${attribute.name}`} />
            <PageHeader
                title={attribute.name}
                backUrl={route('admin.attributes.index')}
                backLabel="К списку атрибутов"
                actions={
                    can('attributes.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.attributes.edit', attribute.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Основная информация</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="ID" value={attribute.id?.toString()} />
                            <InfoRow label="Название" value={attribute.name} />
                            <InfoRow label="Slug" value={attribute.slug} />
                            <InfoRow label="Тип" value={TYPE_LABELS[attribute.type] || attribute.type} />
                            <InfoRow label="Единица измерения" value={attribute.unit} />
                            <InfoRow label="Порядок сортировки" value={attribute.sort_order?.toString()} />
                            <InfoRow label="Группа атрибутов" value={attribute.attribute_group?.name} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Настройки</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 4 }} gap={4}>
                            <BoolRow label="Активен" value={attribute.is_active} />
                            <BoolRow label="Фильтруемый" value={attribute.is_filterable} />
                            <BoolRow label="Показывать на сайте" value={attribute.show_on_site} />
                            <BoolRow label="Показывать в экспорте" value={attribute.show_in_export} />
                            <BoolRow label="Вариантообразующий" value={attribute.is_variant_forming} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {attribute.categories?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Категории</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {attribute.categories.map((c) => (
                                    <Badge key={c.id} variant="outline">{c.name}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}

                {attribute.type === 'select' && attribute.values?.length > 0 && (
                    <Card.Root>
                        <Card.Header>
                            <Text fontWeight="semibold" fontSize="lg">Значения справочника ({attribute.values.length})</Text>
                        </Card.Header>
                        <Card.Body>
                            <HStack gap={2} flexWrap="wrap">
                                {attribute.values.map((v) => (
                                    <Badge key={v.id} variant="subtle">{v.value}</Badge>
                                ))}
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
