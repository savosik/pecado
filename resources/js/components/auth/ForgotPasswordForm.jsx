import { useForm, usePage } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { useAuthDialog } from '@/contexts/AuthDialogContext';

export default function ForgotPasswordForm() {
    const { openLogin } = useAuthDialog();
    const { flash } = usePage().props;
    const { data, setData, post, processing, errors } = useForm({
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

            <Text color="fg.muted" fontSize="sm" mb={4}>
                Введите email и мы отправим ссылку для сброса пароля
            </Text>

            <form onSubmit={handleSubmit}>
                <Stack gap={4}>
                    <Field
                        label={<Text color="fg" fontSize="sm" fontWeight="medium">Email</Text>}
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
                        _hover={{ bg: "#7a1527" }}
                        transition="all 0.2s ease"
                    >
                        Отправить ссылку
                    </Button>
                </Stack>
            </form>

            <Box mt={5} textAlign="center">
                <Text color="fg.muted" fontSize="sm">
                    Вспомнили пароль?{' '}
                    <Text
                        as="span"
                        color="#9e1b32"
                        fontWeight="semibold"
                        _hover={{ color: "#7a1527", textDecoration: "underline" }}
                        transition="color 0.2s"
                        cursor="pointer"
                        onClick={openLogin}
                    >
                        Войти
                    </Text>
                </Text>
            </Box>
        </>
    );
}
