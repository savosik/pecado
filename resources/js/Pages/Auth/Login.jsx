import { Head, useForm, Link } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import PasswordInput from '@/components/ui/password-input';
import AuthLayout from './AuthLayout';
import SocialAuthButtons from './SocialAuthButtons';

export default function Login({ errors }) {
    const { data, setData, post, processing } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/login');
    };

    const inputStyles = {
        bg: "white",
        borderColor: "gray.300",
        color: "gray.900",
        _placeholder: { color: "gray.400" },
        _hover: { borderColor: "gray.400" },
        _focus: {
            borderColor: "#9e1b32",
            boxShadow: "0 0 0 1px rgba(158, 27, 50, 0.15)",
        },
        borderRadius: "lg",
        h: "11",
        fontSize: "sm",
    };

    return (
        <>
            <Head title="Вход" />

            <AuthLayout title="Вход в аккаунт" subtitle="Добро пожаловать в Pecado">
                <form onSubmit={handleSubmit}>
                    <Stack gap={5}>
                        <Field
                            label={<Text color="gray.700" fontSize="sm" fontWeight="medium">Email</Text>}
                            invalid={!!errors.email}
                            errorText={errors.email}
                        >
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="your@email.com"
                                autoFocus
                                {...inputStyles}
                            />
                        </Field>

                        <Field
                            label={<Text color="gray.700" fontSize="sm" fontWeight="medium">Пароль</Text>}
                            invalid={!!errors.password}
                            errorText={errors.password}
                        >
                            <PasswordInput
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="••••••••"
                                {...inputStyles}
                            />
                        </Field>

                        <Box display="flex" justifyContent="space-between" alignItems="center">
                            <Checkbox
                                checked={data.remember}
                                onCheckedChange={(e) => setData('remember', e.checked)}
                                colorPalette="red"
                                size="sm"
                            >
                                <Text color="gray.600" fontSize="sm">
                                    Запомнить меня
                                </Text>
                            </Checkbox>

                            <Link href="/forgot-password">
                                <Text
                                    fontSize="sm"
                                    color="#9e1b32"
                                    fontWeight="semibold"
                                    _hover={{ color: "#7a1527" }}
                                    transition="color 0.2s"
                                    cursor="pointer"
                                >
                                    Забыли пароль?
                                </Text>
                            </Link>
                        </Box>

                        <Button
                            type="submit"
                            width="full"
                            size="lg"
                            loading={processing}
                            bg="#9e1b32"
                            color="white"
                            borderRadius="lg"
                            fontWeight="semibold"
                            fontSize="sm"
                            _hover={{
                                bg: "#7a1527",
                            }}
                            transition="all 0.2s ease"
                        >
                            Войти
                        </Button>
                    </Stack>
                </form>

                <SocialAuthButtons label="Или войдите через" />

                <Box mt={6} textAlign="center">
                    <Text color="gray.500" fontSize="sm">
                        Нет аккаунта?{' '}
                        <Link href="/register">
                            <Text
                                as="span"
                                color="#9e1b32"
                                fontWeight="semibold"
                                _hover={{ color: "#7a1527", textDecoration: "underline" }}
                                transition="color 0.2s"
                            >
                                Зарегистрироваться
                            </Text>
                        </Link>
                    </Text>
                </Box>
            </AuthLayout>
        </>
    );
}
