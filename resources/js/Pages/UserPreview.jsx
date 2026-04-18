import { Head } from '@inertiajs/react';
import { Box, Card, Heading, Stack, SimpleGrid, Text, Badge, Separator, HStack } from '@chakra-ui/react';

const BUSINESS_TYPE_LABELS = {
    sex_shop: 'Секс-шоп',
    online_store: 'Интернет-магазин',
    marketplace: 'Маркетплейс',
    showroom: 'Шоурум',
    wholesale: 'Оптовый закупщик',
    other: 'Другое',
};

const YEARS_IN_BUSINESS_LABELS = {
    less_1: 'Менее 1 года',
    '1_3': '1–3 года',
    '3_5': '3–5 лет',
    '5_plus': 'Более 5 лет',
};

const MONTHLY_ORDER_LABELS = {
    under_50k: 'До 50 000 ₽',
    '50k_200k': '50 – 200 тыс. ₽',
    '200k_500k': '200 – 500 тыс. ₽',
    '500k_1m': '500 тыс. – 1 млн ₽',
    over_1m: 'Более 1 млн ₽',
};

const STORE_COUNT_LABELS = {
    '1': '1',
    '2_5': '2–5',
    '6_10': '6–10',
    '10_plus': 'Более 10',
};

const HOW_FOUND_LABELS = {
    search: 'Поиск в интернете',
    social: 'Социальные сети',
    recommendation: 'Рекомендация',
    exhibition: 'Выставка / мероприятие',
    ad: 'Реклама',
    other: 'Другое',
};

function label(map, value) {
    return map[value] ?? value;
}

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
                                <Field label="Подписка на рассылку" value={user.is_subscribed ? 'Да' : 'Нет'} />
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
                                <Field
                                    label="Тип бизнеса"
                                    value={questionnaire.business_type?.map(v => label(BUSINESS_TYPE_LABELS, v)).join(', ')}
                                />
                                <Field label="Название компании" value={questionnaire.business_name} />
                                <Field label="Сайт" value={questionnaire.website_url} />
                                <Field
                                    label="Лет в бизнесе"
                                    value={label(YEARS_IN_BUSINESS_LABELS, questionnaire.years_in_business)}
                                />
                                <Field
                                    label="Объём заказов в месяц"
                                    value={label(MONTHLY_ORDER_LABELS, questionnaire.monthly_order_volume)}
                                />
                                <Field
                                    label="Физический магазин"
                                    value={questionnaire.has_physical_store ? 'Да' : 'Нет'}
                                />
                                {questionnaire.has_physical_store && (
                                    <Field
                                        label="Кол-во магазинов"
                                        value={label(STORE_COUNT_LABELS, questionnaire.store_count)}
                                    />
                                )}
                                <Field
                                    label="Категории товаров"
                                    value={questionnaire.product_categories?.join(', ')}
                                />
                                <Field
                                    label="Как узнали о нас"
                                    value={label(HOW_FOUND_LABELS, questionnaire.how_found_us)}
                                />
                                <Field label="Дата заполнения" value={questionnaire.completed_at} />
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
