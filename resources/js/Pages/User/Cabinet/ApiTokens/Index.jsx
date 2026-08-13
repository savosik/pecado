import { useState } from 'react';
import {
    Accordion, Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, Heading, Code, SimpleGrid, Separator, Stack,
    Input,
} from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import {
    LuPlus, LuCopy, LuCheck, LuTrash2, LuRefreshCw,
    LuShieldCheck, LuCode, LuArrowRight, LuPackage,
    LuDollarSign, LuWarehouse, LuShoppingCart, LuKey,
    LuTriangleAlert, LuClock, LuGlobe, LuArrowRightLeft,
    LuTruck, LuFileText,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

/* ──────────────────────────────────────────────── */
/*  Компонент: карточка API-ключа                  */
/* ──────────────────────────────────────────────── */
function TokenCard({ token, onRegenerate, onDelete }) {
    const [copied, setCopied] = useState(false);
    const [regenerating, setRegenerating] = useState(false);

    const handleCopy = (text) => {
        navigator.clipboard.writeText(text);
        setCopied(true);
        toaster.create({ title: 'Скопировано', type: 'success', duration: 1500 });
        setTimeout(() => setCopied(false), 2000);
    };

    const handleRegenerate = async () => {
        if (!confirm('Перегенерировать хеш? Старые ссылки перестанут работать.')) return;
        setRegenerating(true);
        try {
            await onRegenerate(token.id);
        } finally {
            setRegenerating(false);
        }
    };

    const handleDelete = () => {
        if (!confirm('Удалить API-ключ? Все эндпоинты по этому ключу перестанут работать.')) return;
        onDelete(token.id);
    };

    return (
        <Card.Root
            bg="bg"
            borderRadius="xl"
            border="1px solid"
            borderColor="border.muted"

            overflow="hidden"
        >
            <Card.Body p="5">
                <VStack align="stretch" gap="3">
                    {/* Header */}
                    <HStack justify="space-between">
                        <HStack>
                            <Flex
                                align="center" justify="center" w="10" h="10" borderRadius="lg"
                                bg="purple.50" _dark={{ bg: 'purple.900/30' }}
                                flexShrink="0"
                            >
                                <LuKey size={20} color="var(--chakra-colors-purple-500)" />
                            </Flex>
                            <Box>
                                <Text fontWeight="700" fontSize="sm">{token.name}</Text>
                                <Text fontSize="2xs" color="gray.400">
                                    Создан: {new Date(token.created_at).toLocaleDateString('ru-RU')}
                                </Text>
                            </Box>
                        </HStack>
                        <Badge
                            colorPalette={token.is_active ? 'green' : 'gray'}
                            variant="subtle" size="sm"
                        >
                            {token.is_active ? 'Активен' : 'Неактивен'}
                        </Badge>
                    </HStack>

                    {/* Base URL */}
                    <HStack
                        bg="bg.subtle"
                        borderRadius="lg" px="3" py="2.5"
                        border="1px solid" borderColor="border"
                    >
                        <LuGlobe size={14} style={{ flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                        <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.300' }} flex="1" truncate fontFamily="mono">
                            {token.base_url}
                        </Text>
                        <IconButton
                            size="2xs" variant="ghost" colorPalette={copied ? 'green' : 'gray'}
                            onClick={() => handleCopy(token.base_url)}
                            aria-label="Скопировать URL"
                        >
                            {copied ? <LuCheck /> : <LuCopy />}
                        </IconButton>
                    </HStack>

                    {/* Last used */}
                    {token.last_used_at && (
                        <HStack gap="1.5">
                            <LuClock size={12} style={{ color: 'var(--chakra-colors-gray-400)' }} />
                            <Text fontSize="2xs" color="gray.400">
                                Последнее использование: {new Date(token.last_used_at).toLocaleString('ru-RU')}
                            </Text>
                        </HStack>
                    )}

                    {/* Actions */}
                    <HStack gap="2">
                        <Button
                            flex="1" size="xs" variant="outline"
                            onClick={handleRegenerate}
                            loading={regenerating}
                            loadingText="..."
                        >
                            <LuRefreshCw /> Перегенерировать
                        </Button>
                        <IconButton
                            size="xs" variant="ghost" colorPalette="red"
                            onClick={handleDelete}
                            aria-label="Удалить ключ"
                        >
                            <LuTrash2 />
                        </IconButton>
                    </HStack>
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}

/* ──────────────────────────────────────────────── */
/*  Данные API-документации                         */
/* ──────────────────────────────────────────────── */
const apiMethods = [
    {
        method: 'GET',
        path: '/prices',
        title: 'Получить цены',
        description: 'Список всех товаров с индивидуальными ценами. Цены рассчитываются с учётом вашей скидки и партнёрского сегмента.',
        icon: LuDollarSign,
        color: 'green',
        params: [
            { name: 'page', type: 'int', desc: 'Номер страницы (по умолчанию: 1)' },
            { name: 'per_page', type: 'int', desc: 'Кол-во на стр. (по умолчанию: 500, макс: 1000)' },
        ],
        responseExample: JSON.stringify({
            data: [
                { uuid: "00000001-...-000000000001", code: "ART-001", sku: "SKU-001", barcode: "4600000000001", name: "Товар 1", base_price: 1500.00, price: 1200.00 },
            ],
            meta: { current_page: 1, last_page: 12, per_page: 500, total: 5847 },
        }, null, 2),
    },
    {
        method: 'GET',
        path: '/stocks',
        title: 'Получить остатки',
        description: 'Остатки товаров по складам вашего региона. «available» — в наличии, «preorder» — доступно для предзаказа.',
        icon: LuWarehouse,
        color: 'teal',
        params: [
            { name: 'page', type: 'int', desc: 'Номер страницы (по умолчанию: 1)' },
            { name: 'per_page', type: 'int', desc: 'Кол-во на стр. (по умолчанию: 500, макс: 1000)' },
        ],
        responseExample: JSON.stringify({
            data: [
                { uuid: "00000001-...-000000000001", code: "ART-001", sku: "SKU-001", barcode: "4600000000001", name: "Товар 1", available: 25, preorder: 100 },
            ],
            meta: { current_page: 1, last_page: 12, per_page: 500, total: 5847 },
        }, null, 2),
    },
    {
        method: 'POST',
        path: '/orders',
        title: 'Создать заказ',
        description: 'Создаёт заказ из доступных позиций. Остатки автоматически делятся на заказ (в наличии) и предзаказ. Если части запрошенного нет — заказ всё равно принимается, а не принятые и частично принятые позиции возвращаются в блоке warnings (not_accepted / partial). Каждая запись несёт line — номер строки запроса (для сопоставления при повторяющихся identifier). Заказ отклоняется (422) только когда недоступны все позиции.',
        icon: LuShoppingCart,
        color: 'blue',
        params: [
            { name: 'inn', type: 'string', required: true, desc: 'ИНН компании' },
            { name: 'delivery_method', type: 'string', desc: 'Способ доставки: «delivery» — доставка по адресу (по умолчанию), «pickup» — самовывоз (адрес не требуется)' },
            { name: 'address', type: 'string', desc: 'Адрес доставки. При delivery_method = «pickup» игнорируется' },
            { name: 'comment', type: 'string', desc: 'Комментарий' },
            { name: 'products', type: 'array', required: true, desc: 'Массив товаров' },
            { name: 'products[].identifier', type: 'string', required: true, desc: 'UUID / code / sku / barcode' },
            { name: 'products[].quantity', type: 'int', required: true, desc: 'Количество (мин. 1)' },
        ],
        requestBody: JSON.stringify({
            inn: "7707083893",
            delivery_method: "delivery",
            address: "г. Москва, ул. Примерная, 1",
            comment: "Срочный заказ",
            products: [
                { identifier: "ART-001", quantity: 5 },
                { identifier: "4600000000003", quantity: 10 },
            ],
        }, null, 2),
        responseExample: JSON.stringify({
            orders: [
                { order_id: 1234, order_number: "ORD-2026-1234", type: "order", delivery_method: "delivery", total_amount: 18000.00, items_count: 2, status: "pending_approval" },
                { order_id: 1235, order_number: "ORD-2026-1235", type: "preorder", delivery_method: "delivery", total_amount: 7400.00, items_count: 1, status: "pending_approval" },
            ],
            total_orders: 2,
            fully_fulfilled: false,
            warnings: {
                message: "Заказ принят. Часть позиций недоступна или отгружена не в полном объёме.",
                not_accepted: [
                    { line: 3, identifier: "XYZ-999", product_id: null, slug: null, name: "XYZ-999", requested: 3, reason: "not_found", message: "Товар не найден" },
                    { line: 4, identifier: "ART-007", product_id: 512, slug: "tovar-7", name: "Товар 7", requested: 2, reason: "out_of_stock", message: "Нет в наличии" },
                ],
                partial: [
                    { line: 1, identifier: "ART-001", product_id: 101, slug: "tovar-1", name: "Товар 1", requested: 100, fulfilled: 25, shortfall: 75 },
                ],
            },
        }, null, 2),
        errorExamples: [
            {
                title: 'Все позиции недоступны (422)',
                body: JSON.stringify({ error: "Ни одна из позиций недоступна для заказа", not_accepted: [{ line: 1, identifier: "XYZ-999", product_id: null, slug: null, name: "XYZ-999", requested: 3, reason: "not_found", message: "Товар не найден" }] }, null, 2),
            },
            {
                title: 'ИНН не найден (422)',
                body: JSON.stringify({ error: "Компания с указанным ИНН не найдена в вашем аккаунте", inn: "0000000000" }, null, 2),
            },
        ],
    },
    {
        method: 'GET',
        path: '/order-changes',
        title: 'Изменения заказов',
        description: 'Лента изменений товарного состава ваших заказов. Правки состава (kind = «edit») свёрнуты к итогу «было → стало» по товару: added (0→N), removed (N→0), changed (N→M) — разнонаправленные движения по одному товару взаимно сворачиваются. Недостача при приёме заказа по API (kind = «api») — «запрошено → принято»: not_accepted (N→0), partial (N→M). Поля from / to — количество до и после. Сортировка: новые изменения первыми.',
        icon: LuArrowRightLeft,
        color: 'purple',
        params: [
            { name: 'type', type: 'string', desc: 'Фильтр по типу: added, removed, changed, not_accepted, partial' },
            { name: 'date_from', type: 'date', desc: 'С даты изменения включительно (YYYY-MM-DD)' },
            { name: 'date_to', type: 'date', desc: 'По дату изменения включительно (YYYY-MM-DD)' },
            { name: 'page', type: 'int', desc: 'Номер страницы (по умолчанию: 1)' },
            { name: 'per_page', type: 'int', desc: 'Кол-во на стр. (по умолчанию: 500, макс: 1000)' },
        ],
        responseExample: JSON.stringify({
            data: [
                { order_number: "29УТ-010000", order_id: 3997, changed_at: "2026-07-14T12:30:00+03:00", kind: "api", type: "not_accepted", product_uuid: "00000001-...-000000000007", product_name: "Товар 7", from: 3, to: 0 },
                { order_number: "29УТ-009999", order_id: 3996, changed_at: "2026-07-13T09:15:00+03:00", kind: "edit", type: "changed", product_uuid: "00000001-...-000000000001", product_name: "Товар 1", from: 7, to: 6 },
            ],
            meta: { current_page: 1, last_page: 3, per_page: 500, total: 1240 },
        }, null, 2),
    },
    {
        method: 'GET',
        path: '/shipments',
        title: 'Получить реализации',
        description: 'Реализации (отгрузочные документы), проведённые в 1С по вашему аккаунту: номер, дата, контрагент, счёт-фактура и сумма. Суммы отдаются в валюте документа (currency_code) без пересчёта — цифры сходятся с накладной. Товарный состав добавляется параметром with_items=1. Сортировка: свежие документы первыми. Для инкрементальной синхронизации используйте updated_since — он фильтрует по полю updated_at из ответа.',
        icon: LuTruck,
        color: 'orange',
        params: [
            { name: 'status', type: 'string|array', desc: 'Статус: new, in_progress, completed, cancelled. Можно передать массив: status[]=new&status[]=completed' },
            { name: 'date_from', type: 'date', desc: 'С даты отгрузки включительно (YYYY-MM-DD)' },
            { name: 'date_to', type: 'date', desc: 'По дату отгрузки включительно (YYYY-MM-DD)' },
            { name: 'updated_since', type: 'datetime', desc: 'Только изменённые с указанного момента (YYYY-MM-DD или YYYY-MM-DD HH:MM:SS) — для догрузки изменений' },
            { name: 'number', type: 'string', desc: 'Часть номера документа; дефисы и пробелы игнорируются (29УТ003413 ≡ 29УТ-003413)' },
            { name: 'inn', type: 'string', desc: 'ИНН контрагента, если у вас несколько юрлиц' },
            { name: 'order_uuid', type: 'string', desc: 'Только реализации по указанному заказу (UUID заказа из 1С)' },
            { name: 'with_items', type: 'bool', desc: 'Добавить товарный состав каждой реализации (по умолчанию: false)' },
            { name: 'page', type: 'int', desc: 'Номер страницы (по умолчанию: 1)' },
            { name: 'per_page', type: 'int', desc: 'Кол-во на стр. (по умолчанию: 100, макс: 500; с with_items=1 — макс: 100)' },
        ],
        responseExample: JSON.stringify({
            data: [
                {
                    id: 4821,
                    uuid: "00000002-...-000000004821",
                    number: "29УТ-003413",
                    erp_number: "29УТ-003413",
                    date: "2026-08-01",
                    status: "completed",
                    status_label: "Выполнена",
                    currency_code: "RUB",
                    total_amount: 18450.50,
                    items_count: 2,
                    invoice_number: "СФ-003413",
                    invoice_date: "2026-08-01",
                    tax_id: "7707083893",
                    company: { id: 12, name: "ООО «Пример»", legal_name: "Общество с ограниченной ответственностью «Пример»", inn: "7707083893" },
                    updated_at: "2026-08-02T10:15:00+03:00",
                    erp_updated_at: "2026-08-01T18:40:00+03:00",
                },
            ],
            meta: { current_page: 1, last_page: 6, per_page: 100, total: 574 },
        }, null, 2),
        errorExamples: [
            {
                title: 'Неверный формат даты (422)',
                body: JSON.stringify({ message: "Дата начала должна быть в формате ГГГГ-ММ-ДД", errors: { date_from: ["Дата начала должна быть в формате ГГГГ-ММ-ДД"] } }, null, 2),
            },
        ],
    },
    {
        method: 'GET',
        path: '/shipments/{id}',
        title: 'Реализация с составом',
        description: 'Одна реализация целиком: реквизиты документа, товарный состав (items — всегда) и заказы, по которым он собран (orders). Вместо {id} можно передать id реализации на сайте, её UUID из 1С или номер документа. Чужие документы недоступны — по ним возвращается 404.',
        icon: LuFileText,
        color: 'cyan',
        params: [
            { name: 'id', type: 'string', required: true, desc: 'ID на сайте, UUID из 1С или номер документа (в пути запроса)' },
        ],
        responseExample: JSON.stringify({
            data: {
                id: 4821,
                uuid: "00000002-...-000000004821",
                number: "29УТ-003413",
                date: "2026-08-01",
                status: "completed",
                status_label: "Выполнена",
                currency_code: "RUB",
                total_amount: 18450.50,
                items_count: 2,
                invoice_number: "СФ-003413",
                invoice_date: "2026-08-01",
                company: { id: 12, name: "ООО «Пример»", legal_name: "ООО «Пример»", inn: "7707083893" },
                items: [
                    {
                        id: 90211,
                        product: { uuid: "00000001-...-000000000001", code: "ART-001", sku: "SKU-001", barcode: "4600000000001", name: "Товар 1", brand: "Бренд" },
                        order_uuid: "00000003-...-000000000777",
                        quantity: 5,
                        price: 1500.00,
                        auto_discount_percent: 10.00,
                        manual_discount_percent: 0.00,
                        subtotal: 7500.00,
                        total: 6750.00,
                        vat_rate: 20,
                    },
                ],
                orders: [
                    { id: 3997, uuid: "00000003-...-000000000777", number: "29УТ-010000", type: "order", status: "completed", status_label: "Выполнен" },
                ],
            },
        }, null, 2),
        errorExamples: [
            {
                title: 'Документ не найден (404)',
                body: JSON.stringify({ message: "Реализация не найдена." }, null, 2),
            },
        ],
    },
];

/* ──────────────────────────────────────────────── */
/*  Главная страница                                */
/* ──────────────────────────────────────────────── */
export default function Index({ tokens: initialTokens }) {
    const [tokens, setTokens] = useState(initialTokens);
    const [creating, setCreating] = useState(false);

    const handleCreate = async () => {
        try {
            setCreating(true);
            const res = await axios.post('/cabinet/api-tokens');
            setTokens(prev => [res.data, ...prev]);
            toaster.create({ title: 'API-ключ создан', type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Ошибка создания ключа', type: 'error' });
        } finally {
            setCreating(false);
        }
    };

    const handleRegenerate = async (id) => {
        try {
            const res = await axios.post(`/cabinet/api-tokens/${id}/regenerate`);
            setTokens(prev => prev.map(t =>
                t.id === id ? { ...t, token: res.data.token, base_url: res.data.base_url } : t
            ));
            toaster.create({ title: 'Хеш перегенерирован', description: 'Используйте новый URL для доступа к API', type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Ошибка перегенерации', type: 'error' });
        }
    };

    const handleDelete = async (id) => {
        try {
            await axios.delete(`/cabinet/api-tokens/${id}`);
            setTokens(prev => prev.filter(t => t.id !== id));
            toaster.create({ title: 'API-ключ удалён', type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Ошибка удаления', type: 'error' });
        }
    };

    return (
        <CabinetLayout title="API">
            <Head title="API — Pecado" />

            {/* Intro */}
            <VStack align="stretch" gap="2" mb="6">
                <Text fontSize="sm" color="gray.500" lineHeight="1.7">
                    Подключите вашу систему к каталогу Pecado через простой REST API.
                    Без авторизации — в URL используется уникальный ключ доступа.
                    Получайте актуальные цены, остатки по вашему региону, отгрузочные документы
                    (реализации) и создавайте заказы программно.
                </Text>
                <HStack
                    bg="amber.50" _dark={{ bg: 'amber.900/20' }}
                    borderRadius="lg" px="3" py="2"
                    border="1px solid" borderColor={{ base: 'amber.200', _dark: 'amber.800' }}
                >
                    <LuTriangleAlert size={16} style={{ flexShrink: 0, color: 'var(--chakra-colors-amber-500)' }} />
                    <Text fontSize="xs" color="amber.700" _dark={{ color: 'amber.300' }}>
                        Храните API-ключ в секрете. Любой, кто знает URL, получает доступ к вашим ценам и может создавать заказы.
                    </Text>
                </HStack>
            </VStack>

            {/* ── Section 1: API Keys ── */}
            <Box mb="10">
                <HStack justify="space-between" mb="4">
                    <Heading size="lg" fontWeight="700">Ваши API-ключи</Heading>
                    <Button
                        size="sm"
                        bg="#9e1b32" color="white"
                        _hover={{ bg: '#7a1527' }}
                        onClick={handleCreate}
                        loading={creating}
                        loadingText="Создание..."
                    >
                        <LuPlus /> Создать ключ
                    </Button>
                </HStack>

                {tokens.length === 0 ? (
                    <Card.Root
                        bg="bg.subtle" borderRadius="xl"
                        border="1px dashed" borderColor="border"

                    >
                        <Card.Body p="8" textAlign="center">
                            <VStack gap="3">
                                <Flex
                                    align="center" justify="center" w="16" h="16" borderRadius="2xl"
                                    bg="purple.50" _dark={{ bg: 'purple.900/20' }}
                                    mx="auto"
                                >
                                    <LuCode size={32} color="var(--chakra-colors-purple-400)" />
                                </Flex>
                                <Text fontWeight="600" color="gray.600" _dark={{ color: 'gray.300' }}>
                                    У вас пока нет API-ключей
                                </Text>
                                <Text fontSize="sm" color="gray.400" maxW="md">
                                    Создайте API-ключ, чтобы получить уникальный URL для доступа к ценам, остаткам и оформлению заказов через API.
                                </Text>
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                ) : (
                    <SimpleGrid columns={{ base: 1, lg: 2 }} gap="4">
                        {tokens.map(token => (
                            <TokenCard
                                key={token.id}
                                token={token}
                                onRegenerate={handleRegenerate}
                                onDelete={handleDelete}
                            />
                        ))}
                    </SimpleGrid>
                )}
            </Box>

            <Separator mb="10" borderColor="border" />

            {/* ── Section 2: API Documentation ── */}
            <Box>
                <VStack align="stretch" gap="2" mb="6">
                    <HStack>
                        <Flex
                            align="center" justify="center" w="10" h="10" borderRadius="lg"
                            bg="blue.50" _dark={{ bg: 'blue.900/30' }}
                        >
                            <LuCode size={20} color="var(--chakra-colors-blue-500)" />
                        </Flex>
                        <Heading size="lg" fontWeight="700">Документация API</Heading>
                    </HStack>

                    <Text fontSize="sm" color="gray.500" lineHeight="1.7">
                        Все запросы выполняются к базовому URL вашего ключа. Например:
                    </Text>

                    <Box
                        bg="gray.900" _dark={{ bg: 'gray.950' }}
                        borderRadius="lg" px="4" py="3"
                    >
                        <Text fontSize="xs" color="gray.400" fontFamily="mono" mb="1">
                            # Базовый URL (замените {'<token>'} на ваш ключ)
                        </Text>
                        <Text fontSize="sm" color="green.300" fontFamily="mono">
                            {window.location.origin}/api/client-api/{'<token>'}
                        </Text>
                        <Text fontSize="2xs" color="amber.300" fontFamily="mono" mt="2">
                            # Только по HTTPS. Запросы по http перенаправляются на https,
                            # при этом POST-запросы (создание заказа) теряют тело — используйте https сразу.
                        </Text>
                    </Box>

                    {/* Identifier hint */}
                    <Card.Root
                        bg="blue.50" _dark={{ bg: 'blue.900/15' }}
                        borderRadius="lg"
                        border="1px solid" borderColor={{ base: 'blue.200', _dark: 'blue.800' }}
                    >
                        <Card.Body p="4">
                            <HStack align="start" gap="3">
                                <LuPackage size={18} style={{ flexShrink: 0, color: 'var(--chakra-colors-blue-500)', marginTop: 2 }} />
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" color="blue.700" _dark={{ color: 'blue.300' }} mb="1">
                                        Идентификация товаров
                                    </Text>
                                    <Text fontSize="xs" color="blue.600" _dark={{ color: 'blue.400' }} lineHeight="1.6">
                                        Во всех ответах и запросах товары представлены четырьмя идентификаторами:
                                        <strong> uuid</strong> (ID из 1С),
                                        <strong> code</strong> (внутренний код),
                                        <strong> sku</strong> (артикул) и
                                        <strong> barcode</strong> (штрихкод).
                                        При создании заказа вы можете использовать любой из них в поле <Code size="xs" colorPalette="blue">identifier</Code>.
                                    </Text>
                                </Box>
                            </HStack>
                        </Card.Body>
                    </Card.Root>
                </VStack>

                {/* API Methods — Accordion */}
                <Accordion.Root collapsible variant="plain">
                    {apiMethods.map((m, i) => (
                        <Accordion.Item key={i} value={`method-${i}`}
                            borderRadius="xl" mb="3"
                            border="1px solid" borderColor="border.muted"
                            bg="bg"
                            overflow="hidden"
                        >
                            {/* Gradient accent */}
                            <Box h="3px" style={{ background: `linear-gradient(90deg, var(--chakra-colors-${m.color}-400), var(--chakra-colors-${m.color}-600))` }} />

                            <Accordion.ItemTrigger px="5" py="3.5" cursor="pointer" _hover={{ bg: { base: 'gray.50', _dark: 'gray.700' } }}>
                                <HStack flex="1" gap="3">
                                    <Flex
                                        align="center" justify="center" w="9" h="9" borderRadius="lg"
                                        bg={`${m.color}.50`} _dark={{ bg: `${m.color}.900/30` }}
                                        flexShrink="0"
                                    >
                                        <m.icon size={18} color={`var(--chakra-colors-${m.color}-500)`} />
                                    </Flex>
                                    <HStack gap="2" flex="1">
                                        <Badge
                                            colorPalette={m.method === 'GET' ? 'green' : 'blue'}
                                            variant="solid" size="sm" fontFamily="mono" fontWeight="700"
                                        >
                                            {m.method}
                                        </Badge>
                                        <Text fontSize="sm" fontFamily="mono" color="gray.500" _dark={{ color: 'gray.400' }}>
                                            {m.path}
                                        </Text>
                                        <Text fontWeight="700" fontSize="sm" ml="1">{m.title}</Text>
                                    </HStack>
                                </HStack>
                                <Accordion.ItemIndicator />
                            </Accordion.ItemTrigger>

                            <Accordion.ItemContent>
                                <Accordion.ItemBody px="5" pb="5" pt="0">
                                    <VStack align="stretch" gap="4">
                                        {/* Description */}
                                        <Text fontSize="sm" color="gray.500" lineHeight="1.6">
                                            {m.description}
                                        </Text>

                                        {/* Parameters */}
                                        {m.params && m.params.length > 0 && (
                                            <Box>
                                                <Text fontSize="2xs" fontWeight="700" color="gray.400" textTransform="uppercase" letterSpacing="0.05em" mb="1.5">
                                                    Параметры
                                                </Text>
                                                <VStack align="stretch" gap="0.5">
                                                    {m.params.map((p, pi) => (
                                                        <HStack key={pi} fontSize="xs" gap="2" py="1" px="2" borderRadius="md"
                                                            bg={pi % 2 === 0 ? { base: 'gray.50', _dark: 'gray.900' } : 'transparent'}
                                                        >
                                                            <Code colorPalette="purple" size="xs" fontWeight="600">{p.name}</Code>
                                                            <Badge size="xs" variant="outline" colorPalette="gray">{p.type}</Badge>
                                                            {p.required && <Badge size="xs" colorPalette="red" variant="subtle">обяз.</Badge>}
                                                            <Text flex="1" color="gray.500">{p.desc}</Text>
                                                        </HStack>
                                                    ))}
                                                </VStack>
                                            </Box>
                                        )}

                                        {/* Request body */}
                                        {m.requestBody && (
                                            <Box>
                                                <Text fontSize="2xs" fontWeight="700" color="gray.400" textTransform="uppercase" letterSpacing="0.05em" mb="1.5">
                                                    Тело запроса (JSON)
                                                </Text>
                                                <Box bg="gray.900" borderRadius="lg" p="3" overflowX="auto">
                                                    <Text as="pre" fontSize="xs" color="green.300" fontFamily="mono" whiteSpace="pre-wrap">
                                                        {m.requestBody}
                                                    </Text>
                                                </Box>
                                            </Box>
                                        )}

                                        {/* Success response */}
                                        {m.responseExample && (
                                            <Box>
                                                <Text fontSize="2xs" fontWeight="700" color="gray.400" textTransform="uppercase" letterSpacing="0.05em" mb="1.5">
                                                    Успешный ответ (200/201)
                                                </Text>
                                                <Box bg="gray.900" borderRadius="lg" p="3" overflowX="auto">
                                                    <Text as="pre" fontSize="xs" color="cyan.300" fontFamily="mono" whiteSpace="pre-wrap">
                                                        {m.responseExample}
                                                    </Text>
                                                </Box>
                                            </Box>
                                        )}

                                        {/* Error examples */}
                                        {m.errorExamples && m.errorExamples.length > 0 && (
                                            <Box>
                                                <Text fontSize="2xs" fontWeight="700" color="gray.400" textTransform="uppercase" letterSpacing="0.05em" mb="1.5">
                                                    Примеры ошибок
                                                </Text>
                                                <VStack align="stretch" gap="2">
                                                    {m.errorExamples.map((err, ei) => (
                                                        <Box key={ei}>
                                                            <Text fontSize="2xs" fontWeight="600" color="red.400" mb="1">
                                                                {err.title}
                                                            </Text>
                                                            <Box bg="gray.900" borderRadius="lg" p="3" overflowX="auto">
                                                                <Text as="pre" fontSize="xs" color="red.300" fontFamily="mono" whiteSpace="pre-wrap">
                                                                    {err.body}
                                                                </Text>
                                                            </Box>
                                                        </Box>
                                                    ))}
                                                </VStack>
                                            </Box>
                                        )}
                                    </VStack>
                                </Accordion.ItemBody>
                            </Accordion.ItemContent>
                        </Accordion.Item>
                    ))}
                </Accordion.Root>

                {/* Rate Limiting Note */}
                <Card.Root mt="3"
                    bg="bg.subtle"
                    borderRadius="xl"
                    border="1px solid"
                    borderColor="border"

                >
                    <Card.Body p="4">
                        <HStack align="start" gap="3">
                            <LuShieldCheck size={18} style={{ flexShrink: 0, color: 'var(--chakra-colors-gray-400)', marginTop: 2 }} />
                            <Box>
                                <Text fontSize="sm" fontWeight="600" mb="1">Лимиты и безопасность</Text>
                                <VStack align="stretch" gap="1">
                                    <Text fontSize="xs" color="gray.500">
                                        • Ограничение: <strong>60 запросов в минуту</strong> на один ключ
                                    </Text>
                                    <Text fontSize="xs" color="gray.500">
                                        • Протокол: <strong>только HTTPS</strong> (запросы по http перенаправляются, POST теряет тело)
                                    </Text>
                                    <Text fontSize="xs" color="gray.500">
                                        • Формат ответа: <strong>JSON</strong> (Content-Type: application/json)
                                    </Text>
                                    <Text fontSize="xs" color="gray.500">
                                        • При превышении лимита: HTTP 429 (Too Many Requests)
                                    </Text>
                                    <Text fontSize="xs" color="gray.500">
                                        • Невалидный ключ: HTTP 404
                                    </Text>
                                </VStack>
                            </Box>
                        </HStack>
                    </Card.Body>
                </Card.Root>
            </Box>
        </CabinetLayout>
    );
}
