import { Head, useForm } from '@inertiajs/react';
import { Box, Container, Heading, VStack, Input, Button, Text, Stack } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import { Toaster } from '@/components/ui/toaster';

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

    return (
        <>
            <Head title="Вход в систему" />
            <Toaster />

            <Box minH="100vh" bg="bg.subtle" display="flex" alignItems="center" justifyContent="center">
                <Container maxW="md" py={12}>
                    <Box
                        bg="bg.panel"
                        borderRadius="lg"
                        borderWidth="1px"
                        borderColor="border.emphasized"
                        p={8}
                        boxShadow="lg"
                    >
                        <Stack gap={6}>
                            {/* Header */}
                            <VStack gap={2} align="start">
                                <Heading size="xl">Вход в систему</Heading>
                                <Text color="fg.muted">
                                    Войдите в админ-панель Pecado
                                </Text>
                            </VStack>

                            {/* Form */}
                            <form onSubmit={handleSubmit}>
                                <Stack gap={4}>
                                    <Field
                                        label="Email"
                                        invalid={!!errors.email}
                                        errorText={errors.email}
                                    >
                                        <Input
                                            type="email"
                                            value={data.email}
                                            onChange={(e) => setData('email', e.target.value)}
                                            placeholder="admin@pecado.test"
                                            autoFocus
                                        />
                                    </Field>

                                    <Field
                                        label="Пароль"
                                        invalid={!!errors.password}
                                        errorText={errors.password}
                                    >
                                        <Input
                                            type="password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                            placeholder="••••••••"
                                        />
                                    </Field>

                                    <Checkbox
                                        checked={data.remember}
                                        onCheckedChange={(e) => setData('remember', e.checked)}
                                    >
                                        Запомнить меня
                                    </Checkbox>

                                    <Button
                                        type="submit"
                                        width="full"
                                        size="lg"
                                        loading={processing}
                                        colorPalette="blue"
                                    >
                                        Войти
                                    </Button>
                                </Stack>
                            </form>
                        </Stack>
                    </Box>

                    <Box mt={4} textAlign="center">
                        <Text fontSize="sm" color="fg.muted">
                            Демо учётные данные:<br />
                            <strong>admin@pecado.test</strong> / <strong>password</strong>
                        </Text>
                    </Box>
                </Container>
            </Box>
        </>
    );
}
