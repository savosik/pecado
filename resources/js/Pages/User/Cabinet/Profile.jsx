import { useState } from 'react';
import {
    Box, Flex, VStack, Text, Input, Button, Card,
    Field,
} from '@chakra-ui/react';
import { Head, usePage, router } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { PhoneInput } from '@/components/common/PhoneInput';
import { EmailSuggest } from '@/components/common/EmailSuggest';
import { Switch } from '@/components/ui/switch';
import { LuSave, LuClock3 } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

export default function Profile() {
    const { auth, errors: serverErrors, preorder: preorderTerms } = usePage().props;
    const user = auth?.user;
    const leadLabel = preorderTerms?.lead_label ?? '';

    const [form, setForm] = useState({
        name: user?.name || '',
        phone: user?.phone || '',
        email: user?.email || '',
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState(serverErrors || {});

    // Переключатель предзаказов — применяется сразу, без кнопки «Сохранить»:
    // это решение «предлагать или нет», а не поле анкеты.
    const [preordersEnabled, setPreordersEnabled] = useState(user?.preorders_enabled !== false);
    const [preordersSaving, setPreordersSaving] = useState(false);

    const handleChange = (field, value) => {
        setForm(prev => ({ ...prev, [field]: value }));
        setErrors(prev => ({ ...prev, [field]: undefined }));
    };

    const validate = () => {
        const errs = {};
        if (!form.name.trim()) errs.name = 'Имя обязательно для заполнения.';
        if (!form.email.trim()) errs.email = 'Email обязателен для заполнения.';
        else if (!isValidEmail(form.email)) errs.email = 'Введите корректный email.';
        return errs;
    };

    const handleSubmit = (e) => {
        e.preventDefault();

        const clientErrors = validate();
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }

        setProcessing(true);
        setErrors({});

        router.put('/cabinet/profile', form, {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Профиль обновлён',
                    type: 'success',
                });
            },
            onError: (errs) => {
                setErrors(errs);
            },
            onFinish: () => setProcessing(false),
        });
    };

    const togglePreorders = (enabled) => {
        const previous = preordersEnabled;
        setPreordersEnabled(enabled);
        setPreordersSaving(true);

        router.put('/cabinet/profile/preorders', { enabled }, {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({
                    title: enabled ? 'Предзаказы включены' : 'Предзаказы выключены',
                    description: enabled
                        ? 'Товар, которого нет на складе, снова можно заказать у поставщика.'
                        : 'В каталоге и корзине останется только то, что есть на складе.',
                    type: 'success',
                });
            },
            onError: () => {
                setPreordersEnabled(previous);
                toaster.create({
                    title: 'Не удалось сохранить',
                    description: 'Попробуйте ещё раз или напишите менеджеру.',
                    type: 'error',
                });
            },
            onFinish: () => setPreordersSaving(false),
        });
    };

    return (
        <CabinetLayout title="Мои данные">
            <Head title="Мои данные — Pecado" />

            <VStack gap="4" align="stretch">
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Header p="5" pb="3">
                        <Text fontSize="md" fontWeight="700">Личная информация</Text>
                    </Card.Header>
                    <Card.Body p="5" pt="0">
                        <form onSubmit={handleSubmit}>
                            <VStack gap="4" align="stretch" maxW="500px">
                                <Field.Root invalid={!!errors.name}>
                                    <Field.Label fontSize="sm" fontWeight="600">Имя / Название *</Field.Label>
                                    <Input
                                        value={form.name}
                                        onChange={(e) => handleChange('name', e.target.value)}
                                        placeholder="Иванов Иван Иванович или ООО Рога и Копыта"
                                        size="md"
                                    />
                                    {errors.name && <Field.ErrorText>{errors.name}</Field.ErrorText>}
                                </Field.Root>

                                <Field.Root invalid={!!errors.phone}>
                                    <Field.Label fontSize="sm" fontWeight="600">Телефон</Field.Label>
                                    <PhoneInput
                                        value={form.phone}
                                        onChange={(val) => handleChange('phone', val)}
                                        size="md"
                                    />
                                    {errors.phone && <Field.ErrorText>{errors.phone}</Field.ErrorText>}
                                </Field.Root>

                                <Field.Root invalid={!!errors.email}>
                                    <Field.Label fontSize="sm" fontWeight="600">Email *</Field.Label>
                                    <EmailSuggest
                                        value={form.email}
                                        onChange={(val) => handleChange('email', val)}
                                        invalid={!!errors.email}
                                        placeholder="user@example.com"
                                        size="md"
                                    />
                                    {errors.email && <Field.ErrorText>{errors.email}</Field.ErrorText>}
                                </Field.Root>

                                <Flex justify="flex-start" pt="2">
                                    <Button
                                        type="submit"
                                        bg="#9e1b32"
                                        color="white"
                                        _hover={{ bg: '#7a1527' }}
                                        size="md"
                                        loading={processing}
                                        loadingText="Сохранение..."
                                    >
                                        <LuSave />
                                        Сохранить
                                    </Button>
                                </Flex>
                            </VStack>
                        </form>
                    </Card.Body>
                </Card.Root>

                {/* Предзаказы: клиент решает сам, видеть ли товар без остатка как предзаказ.
                    Тем, кто оформляет их «на автомате», а потом просит удалить, проще выключить. */}
                <Card.Root bg="bg" borderRadius="xl" border="1px solid" borderColor="border.muted">
                    <Card.Header p="5" pb="3">
                        <Text fontSize="md" fontWeight="700">Предзаказы</Text>
                    </Card.Header>
                    <Card.Body p="5" pt="0">
                        <Flex
                            direction={{ base: 'column', md: 'row' }}
                            justify="space-between"
                            align={{ base: 'stretch', md: 'flex-start' }}
                            gap="4"
                            maxW="720px"
                        >
                            <Box flex="1" minW="0">
                                <Text fontSize="sm" fontWeight="600" color="fg">
                                    Предлагать предзаказ, если товара нет на складе
                                </Text>
                                <Text fontSize="sm" color="fg.muted" mt="1">
                                    Товар без остатка мы заказываем у поставщика
                                    {leadLabel ? ` — поставка ${leadLabel}` : ''}. Он оформляется отдельным
                                    заказом и не задерживает то, что есть на складе.
                                </Text>
                                <Flex align="center" gap="1.5" mt="2" fontSize="xs" color={preordersEnabled ? 'orange.600' : 'fg.muted'} _dark={{ color: preordersEnabled ? 'orange.400' : 'fg.muted' }}>
                                    <LuClock3 size={13} />
                                    <Text>
                                        {preordersEnabled
                                            ? 'Сейчас: в каталоге и корзине есть предзаказ — заказать можно больше, чем лежит на складе.'
                                            : 'Сейчас: только наличие — заказать можно ровно столько, сколько есть на складе.'}
                                    </Text>
                                </Flex>
                            </Box>
                            <Switch
                                size="lg"
                                colorPalette="pecado"
                                checked={preordersEnabled}
                                disabled={preordersSaving}
                                onCheckedChange={(e) => togglePreorders(!!e.checked)}
                                aria-label="Предлагать предзаказ, если товара нет на складе"
                            />
                        </Flex>
                    </Card.Body>
                </Card.Root>
            </VStack>
        </CabinetLayout>
    );
}
