import { Head, useForm, Link } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack, SimpleGrid } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import AuthLayout from './AuthLayout';
import SocialAuthButtons from './SocialAuthButtons';

const countries = [
    { value: 'RU', label: 'Россия' },
    { value: 'BY', label: 'Беларусь' },
    { value: 'KZ', label: 'Казахстан' },
];

export default function Register({ errors }) {
    const { data, setData, post, processing } = useForm({
        surname: '',
        name: '',
        patronymic: '',
        email: '',
        phone: '',
        country: '',
        city: '',
        password: '',
        password_confirmation: '',
        terms_accepted: false,
    });

    const handleSubmit = (e) => {
        e.preventDefault();
        post('/register');
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

    const labelEl = (text) => (
        <Text color="rgba(255,255,255,0.85)" fontWeight="medium">{text}</Text>
    );

    return (
        <>
            <Head title="Регистрация" />

            <AuthLayout title="Регистрация" subtitle="Создайте аккаунт в Pecado">
                <form onSubmit={handleSubmit}>
                    <Stack gap={4}>
                        {/* ФИО */}
                        <Field label={labelEl('Фамилия')} invalid={!!errors.surname} errorText={errors.surname} required>
                            <Input
                                value={data.surname}
                                onChange={(e) => setData('surname', e.target.value)}
                                placeholder="Иванов"
                                autoFocus
                                {...inputStyles}
                            />
                        </Field>

                        <SimpleGrid columns={2} gap={3}>
                            <Field label={labelEl('Имя')} invalid={!!errors.name} errorText={errors.name} required>
                                <Input
                                    value={data.name}
                                    onChange={(e) => setData('name', e.target.value)}
                                    placeholder="Иван"
                                    {...inputStyles}
                                />
                            </Field>

                            <Field label={labelEl('Отчество')} invalid={!!errors.patronymic} errorText={errors.patronymic} required>
                                <Input
                                    value={data.patronymic}
                                    onChange={(e) => setData('patronymic', e.target.value)}
                                    placeholder="Иванович"
                                    {...inputStyles}
                                />
                            </Field>
                        </SimpleGrid>

                        {/* Контакты */}
                        <Field label={labelEl('Email')} invalid={!!errors.email} errorText={errors.email} required>
                            <Input
                                type="email"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                                placeholder="your@email.com"
                                {...inputStyles}
                            />
                        </Field>

                        <Field label={labelEl('Телефон')} invalid={!!errors.phone} errorText={errors.phone} required>
                            <Input
                                type="tel"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                                placeholder="+7 (999) 123-45-67"
                                {...inputStyles}
                            />
                        </Field>

                        {/* Местоположение */}
                        <SimpleGrid columns={2} gap={3}>
                            <Field label={labelEl('Страна')} invalid={!!errors.country} errorText={errors.country} required>
                                <Box
                                    as="select"
                                    value={data.country}
                                    onChange={(e) => setData('country', e.target.value)}
                                    {...inputStyles}
                                    w="full"
                                    px={4}
                                    css={{
                                        '& option': { background: '#1a1a2e', color: 'white' },
                                    }}
                                >
                                    <option value="">Выберите</option>
                                    {countries.map((c) => (
                                        <option key={c.value} value={c.value}>{c.label}</option>
                                    ))}
                                </Box>
                            </Field>

                            <Field label={labelEl('Город')} invalid={!!errors.city} errorText={errors.city} required>
                                <Input
                                    value={data.city}
                                    onChange={(e) => setData('city', e.target.value)}
                                    placeholder="Москва"
                                    {...inputStyles}
                                />
                            </Field>
                        </SimpleGrid>

                        {/* Пароль */}
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

                        {/* Согласие */}
                        <Field invalid={!!errors.terms_accepted} errorText={errors.terms_accepted} required>
                            <Checkbox
                                checked={data.terms_accepted}
                                onCheckedChange={(e) => setData('terms_accepted', e.checked)}
                                colorPalette="purple"
                            >
                                <Text color="rgba(255,255,255,0.7)" fontSize="sm">
                                    Я принимаю условия использования
                                </Text>
                            </Checkbox>
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
                            Зарегистрироваться
                        </Button>
                    </Stack>
                </form>

                <SocialAuthButtons label="Или зарегистрируйтесь через" />

                <Box mt={6} textAlign="center">
                    <Text color="rgba(255, 255, 255, 0.6)" fontSize="sm">
                        Уже есть аккаунт?{' '}
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
