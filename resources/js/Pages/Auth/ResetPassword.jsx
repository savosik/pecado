import { Head, useForm } from '@inertiajs/react';
import { Input, Button, Text, Stack } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import AuthLayout from './AuthLayout';

export default function ResetPassword({ token, email, errors }) {
    const { data, setData, post, processing } = useForm({
        token: token,
        email: email,
        password: '',
        password_confirmation: '',
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/reset-password');
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
            <Head title="Сброс пароля" />

            <AuthLayout
                title="Новый пароль"
                subtitle="Придумайте новый надёжный пароль"
            >
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
                                readOnly
                                {...inputStyles}
                                opacity={0.7}
                            />
                        </Field>

                        <Field
                            label={<Text color="rgba(255,255,255,0.85)" fontWeight="medium">Новый пароль</Text>}
                            invalid={!!errors.password}
                            errorText={errors.password}
                        >
                            <Input
                                type="password"
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Минимум 8 символов"
                                autoFocus
                                {...inputStyles}
                            />
                        </Field>

                        <Field
                            label={<Text color="rgba(255,255,255,0.85)" fontWeight="medium">Подтвердите пароль</Text>}
                            invalid={!!errors.password_confirmation}
                            errorText={errors.password_confirmation}
                        >
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
                            Сменить пароль
                        </Button>
                    </Stack>
                </form>
            </AuthLayout>
        </>
    );
}
