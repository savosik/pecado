import { Head, useForm, Link } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack, SimpleGrid } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import PasswordInput from '@/components/ui/password-input';
import { EmailSuggest } from '@/components/common/EmailSuggest';
import PolicyDialog from '@/components/common/PolicyDialog';
import AuthLayout from './AuthLayout';
// import SocialAuthButtons from './SocialAuthButtons';

export default function Register({ errors }) {
    const { data, setData, post, processing } = useForm({
        email: '',
        password: '',
        password_confirmation: '',
        terms_accepted: false,
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
        <Text color="fg" fontSize="sm" fontWeight="medium">{text}</Text>
    );

    return (
        <>
            <Head title="Регистрация" />

            <AuthLayout title="Регистрация" subtitle="Создайте аккаунт в Pecado" image="/images/auth-register.png">
                <form onSubmit={handleSubmit}>
                    <Stack gap={4}>
                        <Field label={labelEl('Email')} invalid={!!errors.email} errorText={errors.email} required>
                            <EmailSuggest
                                value={data.email}
                                onChange={(val) => setData('email', val)}
                                invalid={!!errors.email}
                                autoFocus
                                {...inputStyles}
                            />
                        </Field>

                        <Field label={labelEl('Пароль')} invalid={!!errors.password} errorText={errors.password} required>
                            <PasswordInput
                                value={data.password}
                                onChange={(e) => setData('password', e.target.value)}
                                placeholder="Минимум 8 символов"
                                {...inputStyles}
                            />
                        </Field>

                        <Field label={labelEl('Подтвердите пароль')} invalid={!!errors.password_confirmation} errorText={errors.password_confirmation} required>
                            <PasswordInput
                                value={data.password_confirmation}
                                onChange={(e) => setData('password_confirmation', e.target.value)}
                                placeholder="Повторите пароль"
                                {...inputStyles}
                            />
                        </Field>

                        <Field invalid={!!errors.terms_accepted} errorText={errors.terms_accepted}>
                            <Checkbox
                                checked={data.terms_accepted}
                                onCheckedChange={(e) => setData('terms_accepted', !!e.checked)}
                                colorPalette="red"
                            >
                                <Text fontSize="sm" color="fg" lineHeight="short">
                                    Я даю согласие на обработку{' '}
                                    <PolicyDialog slug="privacy" triggerLabel="персональных данных" />
                                </Text>
                            </Checkbox>
                        </Field>

                        <Button
                            type="submit"
                            width="full"
                            size="lg"
                            loading={processing}
                            disabled={!data.terms_accepted}
                            bg="#9e1b32"
                            color="white"
                            borderRadius="lg"
                            fontWeight="semibold"
                            fontSize="sm"
                            _hover={{
                                bg: "#7a1527",
                            }}
                            _disabled={{
                                bg: "gray.300",
                                color: "gray.500",
                                cursor: "not-allowed",
                                _hover: { bg: "gray.300" },
                            }}
                            transition="all 0.2s ease"
                        >
                            Зарегистрироваться
                        </Button>
                    </Stack>
                </form>

                {/* <SocialAuthButtons label="Или зарегистрируйтесь через" /> */}

                <Box mt={6} textAlign="center">
                    <Text color="fg.muted" fontSize="sm">
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
