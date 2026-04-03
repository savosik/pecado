import { useState } from 'react';
import {
    Dialog, Portal, Button, VStack, Input, Text, Box, Icon,
    Field,
} from '@chakra-ui/react';
import PasswordInput from '@/components/ui/password-input';
import { usePage, router } from '@inertiajs/react';
import { LuShieldAlert, LuLock } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

/**
 * Глобальный диалог принудительной смены пароля.
 *
 * Отображается поверх любой страницы, если у пользователя
 * must_change_password === true (аккаунт создан из 1С с временным паролем).
 * Диалог нельзя закрыть — пользователь обязан сменить пароль.
 */
export default function ChangePasswordDialog() {
    const { auth } = usePage().props;
    const mustChange = auth?.user?.must_change_password;

    const [form, setForm] = useState({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState({});

    if (!mustChange) return null;

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
            preserveState: true,
            onSuccess: () => {
                toaster.create({
                    title: 'Пароль успешно изменён',
                    description: 'Добро пожаловать!',
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
        <Dialog.Root
            open={true}
            closeOnInteractOutside={false}
            closeOnEscape={false}
        >
            <Portal>
                <Dialog.Backdrop
                    bg="blackAlpha.700"
                    backdropFilter="blur(4px)"
                />
                <Dialog.Positioner>
                    <Dialog.Content
                        maxW="480px"
                        mx="4"
                        borderRadius="2xl"
                        boxShadow="2xl"
                        overflow="hidden"
                    >
                        {/* Шапка с градиентом */}
                        <Box
                            bg="linear-gradient(135deg, #9e1b32 0%, #c4324a 100%)"
                            px="6"
                            py="5"
                            color="white"
                        >
                            <Box display="flex" alignItems="center" gap="3" mb="2">
                                <Icon boxSize="6" asChild>
                                    <LuShieldAlert />
                                </Icon>
                                <Text fontSize="lg" fontWeight="700">
                                    Смена пароля
                                </Text>
                            </Box>
                            <Text fontSize="sm" opacity="0.9" lineHeight="tall">
                                Ваш аккаунт создан с временным паролем. Для безопасности
                                вашей учётной записи, пожалуйста, установите новый пароль.
                            </Text>
                        </Box>

                        <Dialog.Body px="6" py="5">
                            <form onSubmit={handleSubmit} id="change-password-dialog-form">
                                <VStack gap="4" align="stretch">
                                    <Field.Root invalid={!!errors.current_password}>
                                        <Field.Label fontSize="sm" fontWeight="600">
                                            Текущий пароль
                                        </Field.Label>
                                        <PasswordInput
                                            value={form.current_password}
                                            onChange={(e) => handleChange('current_password', e.target.value)}
                                            placeholder="Введите текущий пароль"
                                            size="md"
                                            required
                                            autoFocus
                                        />
                                        {errors.current_password && (
                                            <Field.ErrorText>{errors.current_password}</Field.ErrorText>
                                        )}
                                    </Field.Root>

                                    <Field.Root invalid={!!errors.password}>
                                        <Field.Label fontSize="sm" fontWeight="600">
                                            Новый пароль
                                        </Field.Label>
                                        <PasswordInput
                                            value={form.password}
                                            onChange={(e) => handleChange('password', e.target.value)}
                                            placeholder="Минимум 8 символов"
                                            size="md"
                                            required
                                        />
                                        {errors.password && (
                                            <Field.ErrorText>{errors.password}</Field.ErrorText>
                                        )}
                                    </Field.Root>

                                    <Field.Root invalid={!!errors.password_confirmation}>
                                        <Field.Label fontSize="sm" fontWeight="600">
                                            Подтвердите пароль
                                        </Field.Label>
                                        <PasswordInput
                                            value={form.password_confirmation}
                                            onChange={(e) => handleChange('password_confirmation', e.target.value)}
                                            placeholder="Повторите новый пароль"
                                            size="md"
                                            required
                                        />
                                        {errors.password_confirmation && (
                                            <Field.ErrorText>{errors.password_confirmation}</Field.ErrorText>
                                        )}
                                    </Field.Root>
                                </VStack>
                            </form>
                        </Dialog.Body>

                        <Dialog.Footer px="6" pb="5" pt="0">
                            <Button
                                type="submit"
                                form="change-password-dialog-form"
                                bg="#9e1b32"
                                color="white"
                                _hover={{ bg: '#7a1527' }}
                                size="md"
                                width="100%"
                                loading={processing}
                                loadingText="Сохранение..."
                                borderRadius="lg"
                            >
                                <LuLock />
                                Установить новый пароль
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
