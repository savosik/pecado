import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, SimpleGrid, Textarea, Box, Text } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';

const businessTypes = [
    { value: 'sex_shop', label: 'Секс-шоп' },
    { value: 'online_store', label: 'Интернет-магазин' },
    { value: 'marketplace', label: 'Маркетплейс' },
    { value: 'showroom', label: 'Шоурум' },
    { value: 'wholesale', label: 'Оптовый закупщик' },
    { value: 'other', label: 'Другое' },
];

const yearsOptions = [
    { value: 'less_1', label: 'Менее 1 года' },
    { value: '1_3', label: '1–3 года' },
    { value: '3_5', label: '3–5 лет' },
    { value: '5_plus', label: 'Более 5 лет' },
];

const volumeOptions = [
    { value: 'under_50k', label: 'До 50 000 ₽' },
    { value: '50k_200k', label: '50 – 200 тыс. ₽' },
    { value: '200k_500k', label: '200 – 500 тыс. ₽' },
    { value: '500k_1m', label: '500 тыс. – 1 млн ₽' },
    { value: 'over_1m', label: 'Более 1 млн ₽' },
];

const storeCountOptions = [
    { value: '1', label: '1' },
    { value: '2_5', label: '2–5' },
    { value: '6_10', label: '6–10' },
    { value: '10_plus', label: 'Более 10' },
];

const howFoundOptions = [
    { value: 'search', label: 'Поиск в интернете' },
    { value: 'social', label: 'Социальные сети' },
    { value: 'recommendation', label: 'Рекомендация' },
    { value: 'exhibition', label: 'Выставка / мероприятие' },
    { value: 'ad', label: 'Реклама' },
    { value: 'other', label: 'Другое' },
];

const selectStyles = {
    w: 'full',
    h: '10',
    px: 3,
    borderRadius: 'md',
    border: '1px solid',
    borderColor: 'gray.200',
    fontSize: 'sm',
    bg: 'white',
    _hover: { borderColor: 'gray.300' },
    _focus: { borderColor: 'blue.500', outline: 'none' },
};

export default function Create({ selectedUser, users, rootCategories = [] }) {
    const { data, setData, post, processing, errors } = useForm({
        user_id: selectedUser?.id || '',
        business_type: [],
        business_name: '',
        website_url: '',
        years_in_business: '',
        monthly_order_volume: '',
        has_physical_store: false,
        store_count: '',
        product_categories: [],
        how_found_us: '',
        additional_info: '',
    });

    const closeAfterSaveRef = useRef(false);

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        post(route('admin.user-questionnaires.store'), {
            data: { ...data, _close: shouldClose ? 1 : 0 },
            onSuccess: () => {
                toaster.create({
                    title: 'Анкета успешно создана',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при создании анкеты',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const toggleCategory = (catName) => {
        const cats = data.product_categories || [];
        if (cats.includes(catName)) {
            setData('product_categories', cats.filter((c) => c !== catName));
        } else {
            setData('product_categories', [...cats, catName]);
        }
    };

    return (
        <>
            <PageHeader title="Создать анкету" />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            {/* Выбор пользователя */}
                            <FormField label="Пользователь" error={errors.user_id} required>
                                <Box as="select" value={data.user_id} onChange={(e) => setData('user_id', e.target.value)} {...selectStyles}>
                                    <option value="">Выберите пользователя</option>
                                    {users.map((u) => (
                                        <option key={u.id} value={u.id}>
                                            {u.email}{u.name ? ` — ${u.name}` : ''}
                                        </option>
                                    ))}
                                </Box>
                            </FormField>

                            {/* О бизнесе */}
                            <Text fontWeight="bold" fontSize="lg" color="gray.700">О бизнесе</Text>

                            <FormField label="Тип бизнеса (множественный выбор)" error={errors.business_type}>
                                <SimpleGrid columns={{ base: 2, md: 3 }} gap={2}>
                                    {businessTypes.map((t) => (
                                        <Checkbox
                                            key={t.value}
                                            checked={(data.business_type || []).includes(t.value)}
                                            onCheckedChange={() => {
                                                const arr = data.business_type || [];
                                                if (arr.includes(t.value)) {
                                                    setData('business_type', arr.filter((v) => v !== t.value));
                                                } else {
                                                    setData('business_type', [...arr, t.value]);
                                                }
                                            }}
                                            size="sm"
                                        >
                                            {t.label}
                                        </Checkbox>
                                    ))}
                                </SimpleGrid>
                            </FormField>

                            <FormField label="Название компании" error={errors.business_name}>
                                <Input value={data.business_name} onChange={(e) => setData('business_name', e.target.value)} placeholder="Название" />
                            </FormField>

                            <FormField label="Сайт / соц. сети" error={errors.website_url}>
                                <Input value={data.website_url} onChange={(e) => setData('website_url', e.target.value)} placeholder="https://..." />
                            </FormField>

                            {/* Опыт */}
                            <Text fontWeight="bold" fontSize="lg" color="gray.700" mt={2}>Опыт и объёмы</Text>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Опыт работы в отрасли" error={errors.years_in_business}>
                                    <Box as="select" value={data.years_in_business} onChange={(e) => setData('years_in_business', e.target.value)} {...selectStyles}>
                                        <option value="">Не указан</option>
                                        {yearsOptions.map((o) => (
                                            <option key={o.value} value={o.value}>{o.label}</option>
                                        ))}
                                    </Box>
                                </FormField>

                                <FormField label="Ожидаемый объём закупок в месяц" error={errors.monthly_order_volume}>
                                    <Box as="select" value={data.monthly_order_volume} onChange={(e) => setData('monthly_order_volume', e.target.value)} {...selectStyles}>
                                        <option value="">Не указан</option>
                                        {volumeOptions.map((o) => (
                                            <option key={o.value} value={o.value}>{o.label}</option>
                                        ))}
                                    </Box>
                                </FormField>
                            </SimpleGrid>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Физическая точка продаж">
                                    <Checkbox
                                        checked={data.has_physical_store}
                                        onCheckedChange={(e) => setData('has_physical_store', e.checked)}
                                    >
                                        Есть физическая точка продаж
                                    </Checkbox>
                                </FormField>

                                {data.has_physical_store && (
                                    <FormField label="Сколько торговых точек?" error={errors.store_count}>
                                        <Box as="select" value={data.store_count} onChange={(e) => setData('store_count', e.target.value)} {...selectStyles}>
                                            <option value="">Не указано</option>
                                            {storeCountOptions.map((o) => (
                                                <option key={o.value} value={o.value}>{o.label}</option>
                                            ))}
                                        </Box>
                                    </FormField>
                                )}
                            </SimpleGrid>

                            {/* Категории */}
                            {rootCategories.length > 0 && (
                                <>
                                    <Text fontWeight="bold" fontSize="lg" color="gray.700" mt={2}>Интересующие категории</Text>

                                    <FormField label="Категории товаров" error={errors.product_categories}>
                                        <SimpleGrid columns={{ base: 2, md: 3, lg: 4 }} gap={2}>
                                            {rootCategories.map((cat) => (
                                                <Checkbox
                                                    key={cat.id}
                                                    checked={(data.product_categories || []).includes(cat.name)}
                                                    onCheckedChange={() => toggleCategory(cat.name)}
                                                    size="sm"
                                                >
                                                    {cat.name}
                                                </Checkbox>
                                            ))}
                                        </SimpleGrid>
                                    </FormField>
                                </>
                            )}

                            {/* Дополнительно */}
                            <Text fontWeight="bold" fontSize="lg" color="gray.700" mt={2}>Дополнительно</Text>

                            <FormField label="Как узнали о Pecado?" error={errors.how_found_us}>
                                <Box as="select" value={data.how_found_us} onChange={(e) => setData('how_found_us', e.target.value)} {...selectStyles}>
                                    <option value="">Не указано</option>
                                    {howFoundOptions.map((o) => (
                                        <option key={o.value} value={o.value}>{o.label}</option>
                                    ))}
                                </Box>
                            </FormField>

                            <FormField label="Дополнительная информация" error={errors.additional_info}>
                                <Textarea
                                    value={data.additional_info}
                                    onChange={(e) => setData('additional_info', e.target.value)}
                                    placeholder="Любые комментарии..."
                                    rows={4}
                                />
                            </FormField>

                            <FormActions
                                onSaveAndClose={(e) => handleSubmit(e, true)}
                                submitLabel="Создать анкету"
                                onCancel={() => window.history.back()}
                                processing={processing}
                            />
                        </Stack>
                    </form>
                </Card.Body>
            </Card.Root>
        </>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
