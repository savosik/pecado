import { router, useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions, EntitySelector } from '@/Admin/Components';
import { Box, Card, Stack, Input, SimpleGrid, Button, Text, IconButton, Flex } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { toaster } from '@/components/ui/toaster';
import { FiTrash2, FiPlus } from 'react-icons/fi';

export default function Create() {
    const { data, setData, post, processing, errors } = useForm({
        name: '',
        user_id: '',
        user_name: '', // for display only
        is_active: true,
        starts_at: '',
        ends_at: '',
        discounts: [],
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post(route('admin.agreements.store'), {
            onSuccess: () => {
                toaster.create({
                    title: 'Успех',
                    description: 'Индивидуальное соглашение создано',
                    type: 'success',
                });
            }
        });
    };

    const handleAddUser = (user) => {
        if (user) {
            setData({
                ...data,
                user_id: user.id,
                user_name: user.name + (user.email ? ` (${user.email})` : '')
            });
        }
    };

    const handleRemoveUser = () => {
        setData({
            ...data,
            user_id: '',
            user_name: ''
        });
    };

    const addDiscount = () => {
        setData('discounts', [
            ...data.discounts, 
            { name: '', percentage: '', product_segment_id: null, product_segment_name: '' }
        ]);
    };

    const updateDiscount = (index, field, value) => {
        const newDiscounts = [...data.discounts];
        newDiscounts[index][field] = value;
        setData('discounts', newDiscounts);
    };

    const removeDiscount = (index) => {
        setData('discounts', data.discounts.filter((_, i) => i !== index));
    };

    const handleAddProductSegment = (index, segment) => {
        if (segment) {
            const newDiscounts = [...data.discounts];
            newDiscounts[index].product_segment_id = segment.id;
            newDiscounts[index].product_segment_name = segment.name;
            setData('discounts', newDiscounts);
        }
    };

    const handleRemoveProductSegment = (index) => {
        const newDiscounts = [...data.discounts];
        newDiscounts[index].product_segment_id = null;
        newDiscounts[index].product_segment_name = '';
        setData('discounts', newDiscounts);
    };

    return (
        <form onSubmit={handleSubmit}>
            <PageHeader
                title="Создать индивидуальное соглашение"
                description="Добавление нового персонального соглашения для партнера"
                backUrl={route('admin.agreements.index')}
            />

            <Box maxW="5xl" mb={8}>
                <Card.Root mb={6}>
                    <Card.Body>
                        <Stack gap={6}>
                            <FormField label="Название соглашения" error={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Например: Спец. условия для ООО Вектор"
                                />
                            </FormField>

                            <FormField label="Партнёр" error={errors.user_id} required>
                                {!data.user_id ? (
                                    <EntitySelector
                                        searchUrl="admin.discounts.search-users"
                                        placeholder="Введите имя или email партнёра..."
                                        onChange={handleAddUser}
                                    />
                                ) : (
                                    <Box p={3} borderWidth="1px" borderRadius="md" display="flex" justifyContent="space-between" alignItems="center">
                                        <Text fontWeight="medium">{data.user_name}</Text>
                                        <Button size="sm" colorScheme="red" variant="ghost" onClick={handleRemoveUser}>
                                            Удалить
                                        </Button>
                                    </Box>
                                )}
                            </FormField>

                            <FormField label="Статус" error={errors.is_active}>
                                <Checkbox
                                    checked={data.is_active}
                                    onCheckedChange={(e) => setData('is_active', e.checked)}
                                >
                                    Активно
                                </Checkbox>
                            </FormField>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={4}>
                                <FormField label="Дата начала" error={errors.starts_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.starts_at}
                                        onChange={(e) => setData('starts_at', e.target.value)}
                                    />
                                </FormField>
                                <FormField label="Дата окончания" error={errors.ends_at}>
                                    <Input
                                        type="datetime-local"
                                        value={data.ends_at}
                                        onChange={(e) => setData('ends_at', e.target.value)}
                                    />
                                </FormField>
                            </SimpleGrid>
                        </Stack>
                    </Card.Body>
                </Card.Root>

                <Card.Root mb={6}>
                    <Card.Header>
                        <Flex justify="space-between" align="center">
                            <Text fontSize="lg" fontWeight="bold">Скидки по соглашению</Text>
                            <Button size="sm" onClick={addDiscount} colorScheme="blue">
                                <FiPlus style={{ marginRight: '8px' }} /> Добавить скидку
                            </Button>
                        </Flex>
                    </Card.Header>
                    <Card.Body>
                        {data.discounts.length === 0 ? (
                            <Text color="gray.500" fontSize="sm">Нет добавленных скидок. Нажмите «Добавить скидку».</Text>
                        ) : (
                            <Stack gap={4}>
                                {data.discounts.map((discount, index) => (
                                    <Box key={index} p={4} borderWidth="1px" borderRadius="md" position="relative">
                                        <IconButton 
                                            aria-label="Удалить скидку"
                                            icon={<FiTrash2 />} 
                                            size="sm" 
                                            colorScheme="red" 
                                            variant="ghost" 
                                            position="absolute" 
                                            top={2} 
                                            right={2}
                                            onClick={() => removeDiscount(index)}
                                        />
                                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={4} pr={10}>
                                            <FormField label="Название скидки" error={errors[`discounts.${index}.name`]} required>
                                                <Input
                                                    value={discount.name}
                                                    onChange={(e) => updateDiscount(index, 'name', e.target.value)}
                                                    placeholder="Например: Скидка на обувь"
                                                />
                                            </FormField>
                                            <FormField label="Процент скидки (%)" error={errors[`discounts.${index}.percentage`]} required>
                                                <Input
                                                    type="number"
                                                    min="0"
                                                    max="100"
                                                    step="0.01"
                                                    value={discount.percentage}
                                                    onChange={(e) => updateDiscount(index, 'percentage', e.target.value)}
                                                    placeholder="10.00"
                                                />
                                            </FormField>
                                            <FormField label="Сегмент товаров (необязательно)" error={errors[`discounts.${index}.product_segment_id`]}>
                                                {!discount.product_segment_id ? (
                                                    <EntitySelector
                                                        searchUrl="admin.discounts.search-product-segments"
                                                        placeholder="Весь каталог..."
                                                        onChange={(segment) => handleAddProductSegment(index, segment)}
                                                    />
                                                ) : (
                                                    <Flex justify="space-between" align="center" p={2} borderWidth="1px" borderRadius="md">
                                                        <Text fontSize="sm" isTruncated>{discount.product_segment_name}</Text>
                                                        <Button size="xs" colorScheme="red" variant="ghost" onClick={() => handleRemoveProductSegment(index)}>
                                                            Сбросить
                                                        </Button>
                                                    </Flex>
                                                )}
                                            </FormField>
                                        </SimpleGrid>
                                    </Box>
                                ))}
                            </Stack>
                        )}
                    </Card.Body>
                </Card.Root>

                <FormActions
                    onCancel={() => router.visit(route('admin.agreements.index'))}
                    isProcessing={processing}
                    submitLabel="Сохранить соглашение"
                />
            </Box>
        </form>
    );
}

Create.layout = (page) => <AdminLayout>{page}</AdminLayout>;
