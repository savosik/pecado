import { Head, useForm, Link } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack, SimpleGrid } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import AuthLayout from './AuthLayout';
import SocialAuthButtons from './SocialAuthButtons';

export default function Register({ errors }) {
    const { data, setData, post, processing } = useForm({
        email: '',
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/register');
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

    const labelEl = (text) => (
        <Text color="gray.700" fontSize="sm" fontWeight="medium">{text}</Text>
    );

    return (
        <>
            <Head title="Регистрация" />

            <AuthLayout title="Регистрация" subtitle="Создайте аккаунт в Pecado">
                <form onSubmit={handleSubmit}>
                    <Stack gap={4}>
                        <Field label={labelEl('Email')} invalid={!!errors.email} errorText={errors.email} required>
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="your@email.com"
                                autoFocus
                                {...inputStyles}
                            />
                        </Field>

                        <Field label={labelEl('Пароль')} invalid={!!errors.password} errorText={errors.password} required>
                            <Input
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Минимум 8 символов"
                                {...inputStyles}
                            />
                        </Field>

                        <Field label={labelEl('Подтвердите пароль')} invalid={!!errors.password_confirmation} errorText={errors.password_confirmation} required>
                            <Input
                                type="password"
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="Повторите пароль"
                                {...inputStyles}
                            />
                        </Field>

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
                            Зарегистрироваться
                        </Button>
                    </Stack>
                </form>

                <SocialAuthButtons label="Или зарегистрируйтесь через" />

                <Box mt={6} textAlign="center">
                    <Text color="gray.500" fontSize="sm">
                        Уже есть аккаунт?{' '}
                        <Link href="/login">
                            <Text
                                as="span"
                                color="#9e1b32"
                                fontWeight="semibold"
                                _hover={{ color: "#7a1527", textDecoration: "underline" }}
                                transition="color 0.2s"
                            >
                                Войти
                            </Text>
                        </Link>
                    </Text>
                </Box>
            </AuthLayout>
        </>
    );
}
