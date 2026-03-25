import { Head, useForm, Link } from '@inertiajs/react';
import { Box, Input, Button, Text, Stack, SimpleGrid } from '@chakra-ui/react';
import { Field } from '@/components/ui/field';
import { Checkbox } from '@/components/ui/checkbox';
import AuthLayout from './AuthLayout';
import SocialAuthButtons from './SocialAuthButtons';
import { PhoneInput } from '@/components/common/PhoneInput';

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
                            <PhoneInput
                                value={data.phone}
                                onChange={(val) => setData('phone', val)}
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
                                    bg="white"
                                    color="gray.900"
                                    borderRadius="lg"
                                    h="11"
                                    fontSize="sm"
                                    border="1px solid"
                                    borderColor="gray.300"
                                    _hover={{ borderColor: "gray.400" }}
                                    _focus={{
                                        borderColor: "#9e1b32",
                                        boxShadow: "0 0 0 1px rgba(158, 27, 50, 0.15)",
                                        outline: "none",
                                    }}
                                    w="full"
                                    px={3}
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
                                colorPalette="red"
                                size="sm"
                            >
                                <Text color="gray.600" fontSize="sm">
                                    Я принимаю условия использования
                                </Text>
                            </Checkbox>
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
