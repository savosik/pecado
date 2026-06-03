import { Head, usePage, router } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import { Box, Badge, Card, HStack, SimpleGrid, Text, VStack } from '@chakra-ui/react';
import { LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';

function InfoRow({ label, value }) {
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm" fontWeight="500">{value || '—'}</Text>
        </Box>
    );
}

export default function Show() {
    const { questionnaire } = usePage().props;
    const { can } = usePermission();

    const businessTypes = Array.isArray(questionnaire.business_type)
        ? questionnaire.business_type.join(', ')
        : questionnaire.business_type;

    const productCats = Array.isArray(questionnaire.product_categories)
        ? questionnaire.product_categories.join(', ')
        : questionnaire.product_categories;

    return (
        <>
            <Head title={`Анкета #${questionnaire.id}`} />
            <PageHeader
                title={questionnaire.user ? `Анкета: ${questionnaire.user.name}` : `Анкета #${questionnaire.id}`}
                backUrl={route('admin.user-questionnaires.index')}
                backLabel="К списку анкет"
                actions={
                    can('user-questionnaires.edit') && (
                        <Button size="sm" onClick={() => router.visit(route('admin.user-questionnaires.edit', questionnaire.id))}>
                            <LuPencil /> Редактировать
                        </Button>
                    )
                }
            />
            <VStack gap={4} align="stretch">
                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Пользователь</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="Имя" value={questionnaire.user?.name} />
                            <InfoRow label="Email" value={questionnaire.user?.email} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Заполнена</Text>
                                <Badge colorPalette={questionnaire.completed_at ? 'green' : 'gray'} variant="subtle">
                                    {questionnaire.completed_at ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Дата заполнения" value={questionnaire.completed_at} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Header>
                        <Text fontWeight="semibold" fontSize="lg">Сведения о бизнесе</Text>
                    </Card.Header>
                    <Card.Body>
                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={4}>
                            <InfoRow label="Тип бизнеса" value={businessTypes} />
                            <InfoRow label="Название компании" value={questionnaire.business_name} />
                            <InfoRow label="Сайт" value={questionnaire.website_url} />
                            <InfoRow label="Лет в бизнесе" value={questionnaire.years_in_business} />
                            <InfoRow label="Объём заказов в месяц" value={questionnaire.monthly_order_volume} />
                            <Box>
                                <Text fontSize="xs" color="gray.500" mb="0.5">Есть физический магазин</Text>
                                <Badge colorPalette={questionnaire.has_physical_store ? 'green' : 'gray'} variant="subtle">
                                    {questionnaire.has_physical_store ? 'Да' : 'Нет'}
                                </Badge>
                            </Box>
                            <InfoRow label="Кол-во магазинов" value={questionnaire.store_count} />
                            <InfoRow label="Категории товаров" value={productCats} />
                            <InfoRow label="Откуда узнали" value={questionnaire.how_found_us} />
                        </SimpleGrid>
                    </Card.Body>
                </Card.Root>

                {questionnaire.additional_info && (
                    <Card.Root>
                        <Card.Header><Text fontWeight="semibold" fontSize="lg">Дополнительная информация</Text></Card.Header>
                        <Card.Body>
                            <Text fontSize="sm">{questionnaire.additional_info}</Text>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </>
    );
}

Show.layout = (page) => <AdminLayout>{page}</AdminLayout>;
