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
        bg: "rgba(255, 255, 255, 0.08)",
        borderColor: "rgba(255, 255, 255, 0.15)",
        color: "white",
        _placeholder: { color: "rgba(255, 255, 255, 0.4)" },
        _hover: { borderColor: "rgba(255, 255, 255, 0.3)" },
        _focus: {
            borderColor: "rgba(139, 92, 246, 0.6)",
            boxShadow: "0 0 0 1px rgba(139, 92, 246, 0.3)",
            bg: "rgba(255, 255, 255, 0.1)",
        },
        borderRadius: "xl",
        h: "12",
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
                        borderRadius="xl"
                        bg="rgba(16, 185, 129, 0.15)"
                        border="1px solid rgba(16, 185, 129, 0.3)"
                    >
                        <Text color="rgba(134, 239, 172, 1)" fontSize="sm" fontWeight="medium">
                            ✓ {flash.success}
                        </Text>
                    </Box>
                )}

                <form onSubmit={handleSubmit}>
                    <Stack gap={4}>
                        <Field
                            label={<Text color="rgba(255,255,255,0.85)" fontWeight="medium">Email</Text>}
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
                            bg="linear-gradient(135deg, #8B5CF6, #EC4899)"
                            color="white"
                            borderRadius="xl"
                            fontWeight="bold"
                            _hover={{
                                bg: "linear-gradient(135deg, #7C3AED, #DB2777)",
                                transform: "translateY(-2px)",
                                boxShadow: "0 8px 25px rgba(139, 92, 246, 0.4)",
                            }}
                            transition="all 0.3s ease"
                        >
                            Отправить ссылку
                        </Button>
                    </Stack>
                </form>

                <Box mt={6} textAlign="center">
                    <Text color="rgba(255, 255, 255, 0.6)" fontSize="sm">
                        Вспомнили пароль?{' '}
                        <Link href="/login">
                            <Text
                                as="span"
                                color="rgba(139, 92, 246, 0.9)"
                                fontWeight="semibold"
                                _hover={{ color: "rgba(167, 139, 250, 1)" }}
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
