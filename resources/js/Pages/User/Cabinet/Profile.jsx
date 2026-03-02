import { useState } from 'react';
import {
    Box, Flex, VStack, Text, Input, Button, Card,
    Field,
} from '@chakra-ui/react';
import { Head, usePage, router } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { PhoneInput } from '@/components/common/PhoneInput';
import { LuSave } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
}

export default function Profile() {
    const { auth, errors: serverErrors } = usePage().props;
    const user = auth?.user;

    const [form, setForm] = useState({
        name: user?.name || '',
        surname: user?.surname || '',
        patronymic: user?.patronymic || '',
        phone: user?.phone || '',
        email: user?.email || '',
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState(serverErrors || {});

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

    return (
        <CabinetLayout title="Мои данные">
            <Head title="Мои данные — Pecado" />

            <Card.Root borderRadius="xl" border="1px solid" borderColor="gray.100" _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Header p="5" pb="3">
                    <Text fontSize="md" fontWeight="700">Личная информация</Text>
                </Card.Header>
                <Card.Body p="5" pt="0">
                    <form onSubmit={handleSubmit}>
                        <VStack gap="4" align="stretch" maxW="500px">
                            <Field.Root invalid={!!errors.surname}>
                                <Field.Label fontSize="sm" fontWeight="600">Фамилия</Field.Label>
                                <Input
                                    value={form.surname}
                                    onChange={(e) => handleChange('surname', e.target.value)}
                                    placeholder="Иванов"
                                    size="md"
                                />
                                {errors.surname && <Field.ErrorText>{errors.surname}</Field.ErrorText>}
                            </Field.Root>

                            <Field.Root invalid={!!errors.name}>
                                <Field.Label fontSize="sm" fontWeight="600">Имя *</Field.Label>
                                <Input
                                    value={form.name}
                                    onChange={(e) => handleChange('name', e.target.value)}
                                    placeholder="Иван"
                                    size="md"
                                />
                                {errors.name && <Field.ErrorText>{errors.name}</Field.ErrorText>}
                            </Field.Root>

                            <Field.Root invalid={!!errors.patronymic}>
                                <Field.Label fontSize="sm" fontWeight="600">Отчество</Field.Label>
                                <Input
                                    value={form.patronymic}
                                    onChange={(e) => handleChange('patronymic', e.target.value)}
                                    placeholder="Иванович"
                                    size="md"
                                />
                                {errors.patronymic && <Field.ErrorText>{errors.patronymic}</Field.ErrorText>}
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
                                <Input
                                    value={form.email}
                                    onChange={(e) => handleChange('email', e.target.value)}
                                    placeholder="user@example.com"
                                    size="md"
                                    type="email"
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
        </CabinetLayout>
    );
}
