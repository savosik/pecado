import { Head, useForm, Link, usePage } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import AuthLayout from './AuthLayout';

export default function ForgotPassword({ errors }) {
    const { flash } = usePage().props;
    const { data, setData, post, processing } = useForm({
        email: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/forgot-password');
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
            <Head title="Восстановление пароля" />

            <AuthLayout
                title="Восстановление пароля"
                subtitle="Введите email и мы отправим ссылку для сброса пароля"
            >
                {flash?.success && (
                    <Box
                        mb={4}
                        p={4}
                        borderRadius="lg"
                        bg="green.50"
                        border="1px solid"
                        borderColor="green.200"
                    >
                        <Text color="green.700" fontSize="sm" fontWeight="medium">
                            ✓ {flash.success}
                        </Text>
                    </Box>
                )}

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
                            Отправить ссылку
                        </Button>
                    </Stack>
                </form>

                <Box mt={6} textAlign="center">
                    <Text color="gray.500" fontSize="sm">
                        Вспомнили пароль?{' '}
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
