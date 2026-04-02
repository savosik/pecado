import { useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import {
    Box, Flex, Text, Heading, VStack, Button, Input,
    SimpleGrid, Textarea,
} from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { Link } from '@inertiajs/react';
import { Toaster } from '@/components/ui/toaster';
import { PhoneInput } from '@/components/common/PhoneInput';

const countries = [
    { value: 'RU', label: 'Россия' },
    { value: 'BY', label: 'Беларусь' },
    { value: 'KZ', label: 'Казахстан' },
];




const businessTypes = [
    { value: 'sex_shop', label: 'Секс-шоп', icon: '🏪' },
    { value: 'online_store', label: 'Интернет-магазин', icon: '🛒' },
    { value: 'marketplace', label: 'Маркетплейс', icon: '🌐' },
    { value: 'showroom', label: 'Шоурум', icon: '✨' },
    { value: 'wholesale', label: 'Оптовый закупщик', icon: '📊' },
    { value: 'other', label: 'Другое', icon: '📦' },
];

const yearsOptions = [
    { value: 'less_1', label: 'Менее 1 года' },
    { value: '1_3', label: '1–3 года' },
    { value: '3_5', label: '3–5 лет' },
    { value: '5_plus', label: 'Более 5 лет' },
];

const volumeOptions = [
    { value: 'under_50k', label: 'До 50 000 ₽' },
    { value: '50k_200k', label: '50 – 200 тыс. ₽' },
    { value: '200k_500k', label: '200 – 500 тыс. ₽' },
    { value: '500k_1m', label: '500 тыс. – 1 млн ₽' },
    { value: 'over_1m', label: 'Более 1 млн ₽' },
];



const storeCountOptions = [
    { value: '1', label: '1' },
    { value: '2_5', label: '2–5' },
    { value: '6_10', label: '6–10' },
    { value: '10_plus', label: 'Более 10' },
];

const howFoundOptions = [
    { value: 'search', label: 'Поиск в интернете' },
    { value: 'social', label: 'Социальные сети' },
    { value: 'recommendation', label: 'Рекомендация' },
    { value: 'exhibition', label: 'Выставка / мероприятие' },
    { value: 'ad', label: 'Реклама' },
    { value: 'other', label: 'Другое' },
];

// Chip-style selectable button
function SelectCard({ selected, onClick, children, icon, size = 'md' }) {
    return (
        <Box
            as="div"
            role="button"
            tabIndex={0}
            onClick={onClick}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onClick(); } }}
            px={size === 'lg' ? 6 : 4}
            py={size === 'lg' ? 5 : 3}
            borderRadius="xl"
            border="2px solid"
            borderColor={selected ? '#9e1b32' : 'gray.200'}
            bg={selected ? 'rgba(158, 27, 50, 0.05)' : 'white'}
            cursor="pointer"
            transition="all 0.2s ease"
            _hover={{
                borderColor: selected ? '#9e1b32' : 'gray.300',
                transform: 'translateY(-1px)',
                boxShadow: 'sm',
            }}
            textAlign="center"
            display="flex"
            flexDirection={size === 'lg' ? 'column' : 'row'}
            alignItems="center"
            gap={size === 'lg' ? 2 : 2}
            userSelect="none"
        >
            {icon && <Text fontSize={size === 'lg' ? '2xl' : 'lg'}>{icon}</Text>}
            <Text
                fontSize="sm"
                fontWeight={selected ? 'bold' : 'medium'}
                color={selected ? '#9e1b32' : 'gray.700'}
            >
                {children}
            </Text>
        </Box>
    );
}

function ProgressBar({ step, total }) {
    const pct = ((step) / total) * 100;
    return (
        <Box w="full" mb={8}>
            <Flex justify="space-between" mb={2}>
                <Text fontSize="xs" color="gray.500" fontWeight="medium">
                    Шаг {step} из {total}
                </Text>
                <Text fontSize="xs" color="gray.500">
                    {Math.round(pct)}%
                </Text>
            </Flex>
            <Box w="full" h="2" bg="gray.100" borderRadius="full" overflow="hidden">
                <Box
                    h="full"
                    bg="#9e1b32"
                    borderRadius="full"
                    w={`${pct}%`}
                    transition="width 0.4s ease"
                />
            </Box>
        </Box>
    );
}

export default function Onboarding({ questionnaire, rootCategories = [], user = {} }) {
    const hasCategories = rootCategories.length > 0;

    const steps = [
        'personal',    // Личные данные
        'business',    // О бизнесе
        'experience',  // Опыт и объёмы
        ...(hasCategories ? ['categories'] : []),
        'final',       // Последний шаг
    ];
    const totalSteps = steps.length;
    const [stepIndex, setStepIndex] = useState(0);
    const currentStep = steps[stepIndex];

    const { data, setData, post, processing, errors } = useForm({
        // Личные данные
        name: user?.name || '',
        phone: user?.phone || '',
        country: user?.country || '',
        city: user?.city || '',
        // Анкета
        business_type: questionnaire?.business_type || [],
        business_name: questionnaire?.business_name || '',
        website_url: questionnaire?.website_url || '',
        years_in_business: questionnaire?.years_in_business || '',
        monthly_order_volume: questionnaire?.monthly_order_volume || '',
        has_physical_store: questionnaire?.has_physical_store || false,
        store_count: questionnaire?.store_count || '',
        product_categories: questionnaire?.product_categories || [],
        how_found_us: questionnaire?.how_found_us || '',
        additional_info: questionnaire?.additional_info || '',
    });

    const toggleArrayItem = (field, value) => {
        const arr = data[field] || [];
        if (arr.includes(value)) {
            setData(field, arr.filter((v) => v !== value));
        } else {
            setData(field, [...arr, value]);
        }
    };

    const [personalErrors, setPersonalErrors] = useState({});

    const next = (e) => {
        e.preventDefault();
        e.stopPropagation();

        // Валидация личных данных
        if (currentStep === 'personal') {
            const errs = {};
            if (!data.name?.trim()) errs.name = 'Имя обязательно для заполнения';
            if (!data.country) errs.country = 'Выберите страну';
            if (!data.city?.trim()) errs.city = 'Город обязателен для заполнения';
            if (!data.phone || data.phone.length < 8) errs.phone = 'Введите номер телефона';
            if (Object.keys(errs).length > 0) {
                setPersonalErrors(errs);
                return;
            }
            setPersonalErrors({});
        }

        setStepIndex(Math.min(stepIndex + 1, totalSteps - 1));
    };

    const prev = (e) => {
        e.preventDefault();
        e.stopPropagation();
        setStepIndex(Math.max(stepIndex - 1, 0));
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        if (currentStep !== 'final') return; // Защита от случайной отправки
        post('/onboarding');
    };

    const handleSkip = () => {
        router.post('/onboarding/skip');
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

    const stepMeta = {
        personal: { title: 'Расскажите о себе', subtitle: 'Заполните основную информацию' },
        business: { title: 'О вашем бизнесе', subtitle: 'Расскажите о вашей компании' },
        experience: { title: 'Опыт и объёмы', subtitle: 'Поможем подобрать лучшие условия' },
        categories: { title: 'Что вас интересует', subtitle: 'Выберите интересующие категории' },
        final: { title: 'Последний шаг', subtitle: 'Ещё пара вопросов и всё готово!' },
    };

    return (
        <>
            <Head title="Анкета — Pecado" />
            <Toaster />

            <Flex minH="100vh">
                {/* Left — Form */}
                <Flex
                    flex="1"
                    direction="column"
                    bg="white"
                    px={{ base: 6, sm: 10, lg: 16 }}
                    py={8}
                    overflowY="auto"
                >
                    <Box maxW="520px" w="100%" mx="auto">
                        {/* Logo + Skip */}
                        <Flex justify="space-between" align="center" mb={6}>
                            <Link href="/">
                                <Box
                                    as="img"
                                    src="/images/logo.png"
                                    alt="Pecado"
                                    h="36px"
                                    objectFit="contain"
                                />
                            </Link>
                            {currentStep !== 'personal' && (
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    color="gray.400"
                                    fontWeight="normal"
                                    _hover={{ color: 'gray.600' }}
                                    onClick={handleSkip}
                                    type="button"
                                >
                                    Пропустить
                                </Button>
                            )}
                            {currentStep === 'personal' && <Box />}
                        </Flex>

                        <ProgressBar step={stepIndex + 1} total={totalSteps} />

                        <VStack gap={1} align="start" mb={6}>
                            <Heading
                                size="xl"
                                color="gray.900"
                                fontWeight="bold"
                                letterSpacing="-0.02em"
                            >
                                {stepMeta[currentStep]?.title}
                            </Heading>
                            <Text color="gray.500" fontSize="md">
                                {stepMeta[currentStep]?.subtitle}
                            </Text>
                        </VStack>

                        <form onSubmit={handleSubmit}>
                            {/* Личные данные */}
                            {currentStep === 'personal' && (
                                <VStack gap={4} align="stretch">
                                    <Field
                                        label={<Text fontSize="sm" fontWeight="medium" color="gray.700">Имя / Название</Text>}
                                        invalid={!!personalErrors.name}
                                        errorText={personalErrors.name}
                                        required
                                    >
                                        <Input
                                            value={data.name}
                                            onChange={(e) => setData('name', e.target.value)}
                                            placeholder="Иванов Иван Иванович или ООО Рога и Копыта"
                                            {...inputStyles}
                                        />
                                    </Field>

                                    <SimpleGrid columns={2} gap={3}>
                                        <Field
                                            label={<Text fontSize="sm" fontWeight="medium" color="gray.700">Страна</Text>}
                                            invalid={!!personalErrors.country}
                                            errorText={personalErrors.country}
                                            required
                                        >
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

                                        <Field
                                            label={<Text fontSize="sm" fontWeight="medium" color="gray.700">Город</Text>}
                                            invalid={!!personalErrors.city}
                                            errorText={personalErrors.city}
                                            required
                                        >
                                            <Input
                                                value={data.city}
                                                onChange={(e) => setData('city', e.target.value)}
                                                placeholder="Москва"
                                                {...inputStyles}
                                            />
                                        </Field>
                                    </SimpleGrid>

                                    <Field
                                        label={<Text fontSize="sm" fontWeight="medium" color="gray.700">Телефон</Text>}
                                        invalid={!!personalErrors.phone}
                                        errorText={personalErrors.phone}
                                        required
                                    >
                                        <PhoneInput
                                            value={data.phone}
                                            onChange={(val) => setData('phone', val)}
                                            placeholder="+7 (999) 123-45-67"
                                        />
                                    </Field>
                                </VStack>
                            )}

                            {/* О бизнесе */}
                            {currentStep === 'business' && (
                                <VStack gap={5} align="stretch">
                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={3}>
                                            Тип бизнеса (можно выбрать несколько)
                                        </Text>
                                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={3}>
                                            {businessTypes.map((t) => (
                                                <SelectCard
                                                    key={t.value}
                                                    selected={(data.business_type || []).includes(t.value)}
                                                    onClick={() => toggleArrayItem('business_type', t.value)}
                                                    icon={t.icon}
                                                    size="lg"
                                                >
                                                    {t.label}
                                                </SelectCard>
                                            ))}
                                        </SimpleGrid>
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={2}>
                                            Название компании
                                        </Text>
                                        <Input
                                            value={data.business_name}
                                            onChange={(e) => setData('business_name', e.target.value)}
                                            placeholder="Ваш магазин"
                                            {...inputStyles}
                                        />
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={2}>
                                            Сайт или соц. сети
                                        </Text>
                                        <Input
                                            value={data.website_url}
                                            onChange={(e) => setData('website_url', e.target.value)}
                                            placeholder="https://..."
                                            {...inputStyles}
                                        />
                                    </Box>
                                </VStack>
                            )}

                            {/* Опыт и объёмы */}
                            {currentStep === 'experience' && (
                                <VStack gap={5} align="stretch">
                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={3}>
                                            Опыт работы в отрасли
                                        </Text>
                                        <SimpleGrid columns={2} gap={3}>
                                            {yearsOptions.map((o) => (
                                                <SelectCard
                                                    key={o.value}
                                                    selected={data.years_in_business === o.value}
                                                    onClick={() => setData('years_in_business', o.value)}
                                                >
                                                    {o.label}
                                                </SelectCard>
                                            ))}
                                        </SimpleGrid>
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={3}>
                                            Ожидаемый объём закупок в месяц
                                        </Text>
                                        <SimpleGrid columns={1} gap={2}>
                                            {volumeOptions.map((o) => (
                                                <SelectCard
                                                    key={o.value}
                                                    selected={data.monthly_order_volume === o.value}
                                                    onClick={() => setData('monthly_order_volume', o.value)}
                                                >
                                                    {o.label}
                                                </SelectCard>
                                            ))}
                                        </SimpleGrid>
                                    </Box>

                                    <Box>
                                        <Checkbox
                                            checked={data.has_physical_store}
                                            onCheckedChange={(e) => setData('has_physical_store', e.checked)}
                                            colorPalette="red"
                                            size="sm"
                                        >
                                            <Text fontSize="sm" color="gray.700">
                                                Есть физическая точка продаж
                                            </Text>
                                        </Checkbox>
                                    </Box>

                                    {data.has_physical_store && (
                                        <Box>
                                            <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={3}>
                                                Сколько торговых точек?
                                            </Text>
                                            <SimpleGrid columns={4} gap={2}>
                                                {storeCountOptions.map((o) => (
                                                    <SelectCard
                                                        key={o.value}
                                                        selected={data.store_count === o.value}
                                                        onClick={() => setData('store_count', o.value)}
                                                    >
                                                        {o.label}
                                                    </SelectCard>
                                                ))}
                                            </SimpleGrid>
                                        </Box>
                                    )}
                                </VStack>
                            )}

                            {/* Категории (только если есть в БД) */}
                            {currentStep === 'categories' && (
                                <VStack gap={5} align="stretch">
                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={3}>
                                            Интересующие категории товаров
                                        </Text>
                                        <SimpleGrid columns={{ base: 2, md: 3 }} gap={3}>
                                            {rootCategories.map((cat) => (
                                                <SelectCard
                                                    key={cat.id}
                                                    selected={(data.product_categories || []).includes(cat.name)}
                                                    onClick={() => toggleArrayItem('product_categories', cat.name)}
                                                >
                                                    {cat.name}
                                                </SelectCard>
                                            ))}
                                        </SimpleGrid>
                                    </Box>
                                </VStack>
                            )}

                            {/* Финал */}
                            {currentStep === 'final' && (
                                <VStack gap={5} align="stretch">
                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={3}>
                                            Как вы узнали о Pecado?
                                        </Text>
                                        <SimpleGrid columns={2} gap={3}>
                                            {howFoundOptions.map((o) => (
                                                <SelectCard
                                                    key={o.value}
                                                    selected={data.how_found_us === o.value}
                                                    onClick={() => setData('how_found_us', o.value)}
                                                >
                                                    {o.label}
                                                </SelectCard>
                                            ))}
                                        </SimpleGrid>
                                    </Box>

                                    <Box>
                                        <Text fontSize="sm" fontWeight="medium" color="gray.700" mb={2}>
                                            Дополнительная информация
                                        </Text>
                                        <Textarea
                                            value={data.additional_info}
                                            onChange={(e) => setData('additional_info', e.target.value)}
                                            placeholder="Расскажите подробнее о ваших потребностях, пожеланиях или задайте вопрос..."
                                            rows={4}
                                            bg="white"
                                            borderColor="gray.300"
                                            color="gray.900"
                                            borderRadius="lg"
                                            fontSize="sm"
                                            _placeholder={{ color: "gray.400" }}
                                            _hover={{ borderColor: "gray.400" }}
                                            _focus={{
                                                borderColor: "#9e1b32",
                                                boxShadow: "0 0 0 1px rgba(158, 27, 50, 0.15)",
                                            }}
                                        />
                                    </Box>
                                </VStack>
                            )}

                            {/* Навигация */}
                            <Flex mt={8} gap={3} justify="space-between">
                                {stepIndex > 0 ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={prev}
                                        borderColor="gray.300"
                                        color="gray.600"
                                        borderRadius="lg"
                                        _hover={{ bg: 'gray.50' }}
                                        size="lg"
                                        fontSize="sm"
                                        key="btn-prev"
                                    >
                                        Назад
                                    </Button>
                                ) : <Box key="btn-spacer" />}

                                {stepIndex < totalSteps - 1 ? (
                                    <Button
                                        key={`btn-next-${stepIndex}`}
                                        type="button"
                                        onClick={next}
                                        bg="#9e1b32"
                                        color="white"
                                        borderRadius="lg"
                                        fontWeight="semibold"
                                        fontSize="sm"
                                        size="lg"
                                        px={8}
                                        _hover={{ bg: '#7a1527' }}
                                        transition="all 0.2s ease"
                                    >
                                        Далее
                                    </Button>
                                ) : (
                                    <Button
                                        key="btn-finish"
                                        type="submit"
                                        bg="#9e1b32"
                                        color="white"
                                        borderRadius="lg"
                                        fontWeight="semibold"
                                        fontSize="sm"
                                        size="lg"
                                        px={8}
                                        loading={processing}
                                        _hover={{ bg: '#7a1527' }}
                                        transition="all 0.2s ease"
                                    >
                                        Завершить ✓
                                    </Button>
                                )}
                            </Flex>
                        </form>
                    </Box>
                </Flex>

                {/* Right — Image (hidden on mobile) */}
                <Box
                    display={{ base: 'none', lg: 'block' }}
                    flex="1"
                    position="relative"
                    overflow="hidden"
                >
                    <Box
                        as="img"
                        src="/images/auth-hero-v2.png"
                        alt=""
                        position="absolute"
                        inset="0"
                        w="100%"
                        h="100%"
                        objectFit="cover"
                    />
                    <Box
                        position="absolute"
                        inset="0"
                        bg="linear-gradient(180deg, rgba(158,27,50,0.08) 0%, rgba(158,27,50,0.15) 100%)"
                    />
                </Box>
            </Flex>
        </>
    );
}
