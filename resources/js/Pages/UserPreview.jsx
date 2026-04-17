import { Head } from '@inertiajs/react';
import { Box, Card, Heading, Stack, SimpleGrid, Text, Badge, Separator } from '@chakra-ui/react';

function Field({ label, value }) {
    if (value === null || value === undefined || value === '') return null;
    return (
        <Box>
            <Text fontSize="xs" color="gray.500" mb="0.5">{label}</Text>
            <Text fontSize="sm">{String(value)}</Text>
        </Box>
    );
}

const STATUS_COLORS = {
    active: 'green',
    processing: 'orange',
    blocked: 'red',
};

export default function UserPreview({ user, questionnaire }) {
    return (
        <>
            <Head title={`Профиль — ${user.name || user.email}`} />

            <Box maxW="700px" mx="auto" px="4" py="10">
                <Heading size="lg" mb="6">Профиль пользователя</Heading>

                <Card.Root mb="6">
                    <Card.Header pb="2">
                        <Heading size="sm">Основные данные</Heading>
                    </Card.Header>
                    <Card.Body>
                        <Stack gap="4">
                            <HStack justify="space-between" align="flex-start">
                                <Field label="Имя" value={user.name} />
                                <Badge colorPalette={STATUS_COLORS[user.status] ?? 'gray'}>
                                    {user.status_label}
                                </Badge>
                            </HStack>

                            <SimpleGrid columns={2} gap="4">
                                <Field label="Email" value={user.email} />
                                <Field label="Телефон" value={user.phone} />
                                <Field label="Страна" value={user.country} />
                                <Field label="Город" value={user.city} />
                                <Field label="Регион" value={user.region} />
                                <Field label="Статус клиента" value={user.client_status} />
                                <Field label="ERP ID" value={user.erp_id} />
                                <Field label="Дата регистрации" value={user.created_at} />
                            </SimpleGrid>

                            {user.comment && (
                                <>
                                    <Separator />
                                    <Field label="Комментарий" value={user.comment} />
                                </>
                            )}
                        </Stack>
                    </Card.Body>
                </Card.Root>

                {questionnaire ? (
                    <Card.Root>
                        <Card.Header pb="2">
                            <Heading size="sm">Анкета</Heading>
                        </Card.Header>
                        <Card.Body>
                            <SimpleGrid columns={2} gap="4">
                                <Field label="Тип бизнеса" value={questionnaire.business_type?.join(', ')} />
                                <Field label="Название компании" value={questionnaire.business_name} />
                                <Field label="Сайт" value={questionnaire.website_url} />
                                <Field label="Лет в бизнесе" value={questionnaire.years_in_business} />
                                <Field label="Объём заказов в месяц" value={questionnaire.monthly_order_volume} />
                                <Field
                                    label="Физический магазин"
                                    value={questionnaire.has_physical_store ? 'Да' : 'Нет'}
                                />
                                {questionnaire.has_physical_store && (
                                    <Field label="Кол-во магазинов" value={questionnaire.store_count} />
                                )}
                                <Field
                                    label="Категории товаров"
                                    value={questionnaire.product_categories?.join(', ')}
                                />
                                <Field label="Как узнали о нас" value={questionnaire.how_found_us} />
                            </SimpleGrid>
                            {questionnaire.additional_info && (
                                <>
                                    <Separator mt="4" mb="4" />
                                    <Field label="Дополнительно" value={questionnaire.additional_info} />
                                </>
                            )}
                        </Card.Body>
                    </Card.Root>
                ) : (
                    <Card.Root>
                        <Card.Body>
                            <Text color="gray.500" fontSize="sm">Анкета не заполнена</Text>
                        </Card.Body>
                    </Card.Root>
                )}
            </Box>
        </>
    );
}
