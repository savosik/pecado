import { useRef } from 'react';
import { useForm } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, FormField, FormActions } from '@/Admin/Components';
import { Card, Input, Stack, Flex, Box, Badge, HStack, Text, NativeSelectRoot, NativeSelectField } from '@chakra-ui/react';
import { Switch } from '@/components/ui/switch';
import { toaster } from '@/components/ui/toaster';

export default function Edit({ menuItem }) {
    const { data, setData, put, processing, errors, transform } = useForm({
        title: menuItem.title || '',
        url: menuItem.url || '',
        icon: menuItem.icon || '',
        badge_text: menuItem.badge_text || '',
        badge_color: menuItem.badge_color || '#e53e3e',
        location: menuItem.location || 'header',
        footer_group: menuItem.footer_group || '',
        sort_order: menuItem.sort_order ?? 0,
        is_published: menuItem.is_published ?? true,
        open_in_new_tab: menuItem.open_in_new_tab ?? false,
    });

    const closeAfterSaveRef = useRef(false);

    transform((data) => ({
        ...data,
        icon: data.icon || null,
        badge_text: data.badge_text || null,
        badge_color: data.badge_text ? (data.badge_color || null) : null,
        footer_group: (data.location === 'footer' || data.location === 'both') ? (data.footer_group || null) : null,
        _close: closeAfterSaveRef.current ? 1 : 0,
    }));

    const handleSubmit = (e, shouldClose = false) => {
        e.preventDefault();
        closeAfterSaveRef.current = shouldClose;
        put(route('admin.menu-items.update', menuItem.id), {
            onSuccess: () => {
                toaster.create({
                    title: 'Пункт меню успешно обновлён',
                    type: 'success',
                });
            },
            onError: () => {
                toaster.create({
                    title: 'Ошибка при обновлении пункта меню',
                    description: 'Проверьте правильность заполнения полей',
                    type: 'error',
                });
            },
        });
    };

    const handleSaveAndClose = (e) => {
        handleSubmit(e, true);
    };

    const showFooterGroup = data.location === 'footer' || data.location === 'both';

    return (
        <>
            <PageHeader title={`Редактировать: ${menuItem.title}`} />

            <Card.Root>
                <Card.Body>
                    <form onSubmit={handleSubmit}>
                        <Stack gap={6}>
                            <Flex gap={6} direction={{ base: 'column', md: 'row' }}>
                                <FormField label="Название" error={errors.title} required flex="1">
                                    <Input
                                        value={data.title}
                                        onChange={(e) => setData('title', e.target.value)}
                                        placeholder="Например: Акции"
                                    />
                                </FormField>

                                <FormField label="URL" error={errors.url} required flex="1">
                                    <Input
                                        value={data.url}
                                        onChange={(e) => setData('url', e.target.value)}
                                        placeholder="/promotions"
                                    />
                                </FormField>
                            </Flex>

                            <Flex gap={6} direction={{ base: 'column', md: 'row' }}>
                                <FormField label="Иконка" error={errors.icon} flex="1" helperText="Имя иконки из react-icons/lu, например: LuNewspaper">
                                    <Input
                                        value={data.icon}
                                        onChange={(e) => setData('icon', e.target.value)}
                                        placeholder="LuNewspaper"
                                    />
                                </FormField>

                                <FormField label="Расположение" error={errors.location} required flex="1">
                                    <NativeSelectRoot>
                                        <NativeSelectField
                                            value={data.location}
                                            onChange={(e) => setData('location', e.target.value)}
                                        >
                                            <option value="header">Хедер</option>
                                            <option value="footer">Футер</option>
                                            <option value="both">Хедер и Футер</option>
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </FormField>
                            </Flex>

                            {showFooterGroup && (
                                <FormField label="Группа в футере" error={errors.footer_group} helperText="Колонка, в которой будет отображаться пункт в футере">
                                    <NativeSelectRoot>
                                        <NativeSelectField
                                            value={data.footer_group}
                                            onChange={(e) => setData('footer_group', e.target.value)}
                                        >
                                            <option value="">Не выбрана</option>
                                            <option value="company">О компании</option>
                                            <option value="buyers">Покупателям</option>
                                            <option value="legal">Правовая информация</option>
                                        </NativeSelectField>
                                    </NativeSelectRoot>
                                </FormField>
                            )}

                            <Box
                                p={4}
                                borderWidth="1px"
                                borderColor="border.muted"
                                borderRadius="lg"
                            >
                                <Text fontSize="sm" fontWeight="600" mb={4} color="fg.muted">Бейдж (необязательно)</Text>
                                <Flex gap={6} direction={{ base: 'column', md: 'row' }} align="flex-end">
                                    <FormField label="Текст бейджа" error={errors.badge_text} flex="1">
                                        <Input
                                            value={data.badge_text}
                                            onChange={(e) => setData('badge_text', e.target.value)}
                                            placeholder="Новинка"
                                        />
                                    </FormField>

                                    <FormField label="Цвет бейджа" error={errors.badge_color} flex="1">
                                        <HStack gap={3}>
                                            <Input
                                                type="color"
                                                value={data.badge_color}
                                                onChange={(e) => setData('badge_color', e.target.value)}
                                                w="50px"
                                                h="40px"
                                                p="1"
                                                cursor="pointer"
                                            />
                                            <Input
                                                value={data.badge_color}
                                                onChange={(e) => setData('badge_color', e.target.value)}
                                                placeholder="#e53e3e"
                                                maxW="120px"
                                            />
                                        </HStack>
                                    </FormField>

                                    {data.badge_text && (
                                        <Box pb={2}>
                                            <Text fontSize="xs" color="fg.muted" mb={1}>Превью:</Text>
                                            <Badge
                                                variant="solid"
                                                bg={data.badge_color || '#e53e3e'}
                                                color="white"
                                                borderRadius="full"
                                                px={2}
                                                fontSize="xs"
                                            >
                                                {data.badge_text}
                                            </Badge>
                                        </Box>
                                    )}
                                </Flex>
                            </Box>

                            <Flex gap={6} align="center">
                                <FormField label="Порядок сортировки" error={errors.sort_order}>
                                    <Input
                                        type="number"
                                        min={0}
                                        value={data.sort_order}
                                        onChange={(e) => setData('sort_order', parseInt(e.target.value) || 0)}
                                        w="120px"
                                    />
                                </FormField>

                                <FormField label="Открывать в новой вкладке" error={errors.open_in_new_tab}>
                                    <Switch
                                        checked={data.open_in_new_tab}
                                        onCheckedChange={(e) => setData('open_in_new_tab', e.checked)}
                                        colorPalette="blue"
                                        size="lg"
                                        mt="1"
                                    />
                                </FormField>

                                <FormField label="Опубликован" error={errors.is_published}>
                                    <Switch
                                        checked={data.is_published}
                                        onCheckedChange={(e) => setData('is_published', e.checked)}
                                        colorPalette="green"
                                        size="lg"
                                        mt="1"
                                    />
                                </FormField>
                            </Flex>

                            <FormActions
                                onSaveAndClose={handleSaveAndClose}
                                submitLabel="Сохранить"
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

Edit.layout = (page) => <AdminLayout>{page}</AdminLayout>;
