import { useState } from 'react';
import {
    Box, Flex, VStack, Text, Input, Button, Card,
    Field,
} from '@chakra-ui/react';
import PasswordInput from '@/components/ui/password-input';
import { Head, usePage, router } from '@inertiajs/react';
import CabinetLayout from './CabinetLayout';
import { LuLock, LuCheck } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

export default function ChangePassword() {
    const { errors: serverErrors } = usePage().props;

    const [form, setForm] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState(serverErrors || {});

    const handleChange = (field, value) => {
        setForm(prev => ({ ...prev, [field]: value }));
        setErrors(prev => ({ ...prev, [field]: undefined }));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        setProcessing(true);
        setErrors({});

        router.put('/cabinet/change-password', form, {
            preserveScroll: true,
            onSuccess: () => {
                setForm({
                    current_password: '',
                    password: '',
                    password_confirmation: '',
                });
                toaster.create({
                    title: 'Пароль успешно изменён',
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
        <CabinetLayout title="Смена пароля">
            <Head title="Смена пароля — Pecado" />

            <Card.Root bg={{ base: 'white', _dark: 'gray.800' }} borderRadius="xl" border="1px solid" borderColor={{ base: 'gray.100', _dark: 'gray.700' }} _dark={{ bg: 'gray.800', borderColor: 'gray.700' }}>
                <Card.Header p="5" pb="3">
                    <Text fontSize="md" fontWeight="700">Изменить пароль</Text>
                </Card.Header>
                <Card.Body p="5" pt="0">
                    <form onSubmit={handleSubmit}>
                        <VStack gap="4" align="stretch" maxW="500px">
                            <Field.Root invalid={!!errors.current_password}>
                                <Field.Label fontSize="sm" fontWeight="600">Текущий пароль *</Field.Label>
                                <PasswordInput
                                    value={form.current_password}
                                    onChange={(e) => handleChange('current_password', e.target.value)}
                                    placeholder="Введите текущий пароль"
                                    size="md"
                                    required
                                />
                                {errors.current_password && <Field.ErrorText>{errors.current_password}</Field.ErrorText>}
                            </Field.Root>

                            <Field.Root invalid={!!errors.password}>
                                <Field.Label fontSize="sm" fontWeight="600">Новый пароль *</Field.Label>
                                <PasswordInput
                                    value={form.password}
                                    onChange={(e) => handleChange('password', e.target.value)}
                                    placeholder="Минимум 8 символов"
                                    size="md"
                                    required
                                />
                                {errors.password && <Field.ErrorText>{errors.password}</Field.ErrorText>}
                            </Field.Root>

                            <Field.Root invalid={!!errors.password_confirmation}>
                                <Field.Label fontSize="sm" fontWeight="600">Подтвердите пароль *</Field.Label>
                                <PasswordInput
                                    value={form.password_confirmation}
                                    onChange={(e) => handleChange('password_confirmation', e.target.value)}
                                    placeholder="Повторите новый пароль"
                                    size="md"
                                    required
                                />
                                {errors.password_confirmation && <Field.ErrorText>{errors.password_confirmation}</Field.ErrorText>}
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
                                    <LuLock />
                                    Изменить пароль
                                </Button>
                            </Flex>
                        </VStack>
                    </form>
                </Card.Body>
            </Card.Root>
        </CabinetLayout>
    );
}
