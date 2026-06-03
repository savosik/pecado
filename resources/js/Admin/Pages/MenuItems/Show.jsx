import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

const LOCATION_LABELS = { header: 'Шапка', footer: 'Подвал', both: 'Шапка и подвал' };
const FOOTER_GROUP_LABELS = { company: 'Компания', buyers: 'Покупателям', legal: 'Правовая информация' };

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { menuItem } = usePage().props;
    const { can } = usePermission();

    return (
        <>
            <Head title={`Пункт меню: ${menuItem.title}`} />
            <PageHeader
                title={menuItem.title}
                backUrl={route('admin.menu-items.index')}
                backLabel="К списку пунктов меню"
                actions={
                    can('menu-items.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.menu-items.edit', menuItem.id))}>
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
                            <InfoRow label="ID" value={menuItem.id?.toString()} />
                            <InfoRow label="Заголовок" value={menuItem.title} />
                            <InfoRow label="URL" value={menuItem.url} />
                            <InfoRow label="Расположение" value={LOCATION_LABELS[menuItem.location] || menuItem.location} />
                            <InfoRow label="Группа в подвале" value={FOOTER_GROUP_LABELS[menuItem.footer_group] || menuItem.footer_group} />
                            <InfoRow label="Иконка" value={menuItem.icon} />
                            <InfoRow label="Текст значка" value={menuItem.badge_text} />
                            <InfoRow label="Цвет значка" value={menuItem.badge_color} />
                            <InfoRow label="Порядок" value={menuItem.sort_order?.toString()} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Опубликован</Text>
                                <Badge colorPalette={menuItem.is_published ? 'green' : 'gray'} variant="subtle">
                                    {menuItem.is_published ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Открывать в новой вкладке</Text>
                                <Badge colorPalette={menuItem.open_in_new_tab ? 'blue' : 'gray'} variant="subtle">
                                    {menuItem.open_in_new_tab ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Создан" value={menuItem.created_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
