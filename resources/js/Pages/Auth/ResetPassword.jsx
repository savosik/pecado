import { Head, useForm } from '@inertiajs/react';
import { Input, Button, Text, Stack } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import PasswordInput from '@/components/ui/password-input';
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
            <Head title="Сброс пароля" />

            <AuthLayout
                title="Новый пароль"
                subtitle="Придумайте новый надёжный пароль"
                image="/images/auth-reset.png"
            >
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
                                readOnly
                                {...inputStyles}
                                opacity={0.7}
                            />
                        </Field>

                        <Field
                            label={<Text color="gray.700" fontSize="sm" fontWeight="medium">Новый пароль</Text>}
                            invalid={!!errors.password}
                            errorText={errors.password}
                        >
                            <PasswordInput
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Минимум 8 символов"
                                autoFocus
                                {...inputStyles}
                            />
                        </Field>

                        <Field
                            label={<Text color="gray.700" fontSize="sm" fontWeight="medium">Подтвердите пароль</Text>}
                            invalid={!!errors.password_confirmation}
                            errorText={errors.password_confirmation}
                        >
                            <PasswordInput
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
                            Сменить пароль
                        </Button>
                    </Stack>
                </form>
            </AuthLayout>
        </>
    );
}
