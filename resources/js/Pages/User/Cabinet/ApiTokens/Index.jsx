import { useState } from 'react';
import {
    Box, Flex, Text, Card, HStack, VStack, Badge, Button,
    IconButton, Heading, Code, SimpleGrid, Separator,
} from '@chakra-ui/react';
import { Head } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import CopyableUrl from './CopyableUrl';
import {
    LuPlus, LuCopy, LuCheck, LuTrash2, LuRefreshCw,
    LuCode, LuKey, LuTriangleAlert, LuClock,
    LuBookOpen, LuExternalLink, LuRocket,
} from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

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

                    {/* API v1: адрес общий, ключ — в заголовке */}
                    <CopyableUrl
                        label="Адрес API v1"
                        value={token.v1_base_url}
                        icon={LuRocket}
                        caption={
                            <>
                                Токен передаётся в заголовке{' '}
                                <Code size="xs" colorPalette="purple">Authorization: Bearer {'<ключ>'}</Code>
                            </>
                        }
                    />

                    {/* Ключ: копируется отдельно, чтобы подставить в заголовок */}
                    <HStack
                        bg="bg.subtle"
                        borderRadius="lg" px="3" py="2.5"
                        border="1px solid" borderColor="border"
                    >
                        <LuKey size={14} style={{ flexShrink: 0, color: 'var(--chakra-colors-gray-400)' }} />
                        <Text fontSize="xs" color="gray.600" _dark={{ color: 'gray.300' }} flex="1" truncate fontFamily="mono">
                            {token.token}
                        </Text>
                        <IconButton
                            size="2xs" variant="ghost" colorPalette={copied ? 'green' : 'gray'}
                            onClick={() => handleCopy(token.token)}
                            aria-label="Скопировать ключ"
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

export default function Index({ tokens: initialTokens, docs = {} }) {
    const [tokens, setTokens] = useState(initialTokens);
    const [creating, setCreating] = useState(false);

    // Быстрый старт подставляет первый ключ; без ключей — заглушка.
    const sampleKey = tokens[0]?.token ?? '<ВАШ_КЛЮЧ>';

    const copyToClipboard = async (text, title) => {
        try {
            await navigator.clipboard.writeText(text);
            toaster.create({ title, type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Не удалось скопировать', type: 'error' });
        }
    };
    const v1Base = tokens[0]?.v1_base_url ?? `${window.location.origin}/api/client/v1`;
    const quickStart = [
        {
            title: 'Кто я и что мне доступно',
            code: `curl -H "Authorization: Bearer ${sampleKey}" \\\n  ${v1Base}/me`,
        },
        {
            title: 'Цены по артикулам',
            code: `curl -H "Authorization: Bearer ${sampleKey}" \\\n  "${v1Base}/catalog/prices?identifiers[]=АРТИКУЛ"`,
        },
        {
            title: 'Создать заказ (с ключом идемпотентности)',
            code: `curl -X POST ${v1Base}/orders \\\n  -H "Authorization: Bearer ${sampleKey}" \\\n  -H "Idempotency-Key: <уникальный-ключ>" \\\n  -H "Content-Type: application/json" \\\n  -d '{"products":[{"identifier":"АРТИКУЛ","quantity":1}]}'`,
        },
    ];

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
                    Подключите вашу систему или ИИ-агента к кабинету Pecado через REST API.
                    Ключ доступа передаётся в заголовке запроса. Получайте актуальные цены,
                    остатки по вашему региону, отгрузочные документы (реализации) и создавайте заказы программно.
                </Text>
                <HStack
                    bg="amber.50" _dark={{ bg: 'amber.900/20' }}
                    borderRadius="lg" px="3" py="2"
                    border="1px solid" borderColor={{ base: 'amber.200', _dark: 'amber.800' }}
                >
                    <LuTriangleAlert size={16} style={{ flexShrink: 0, color: 'var(--chakra-colors-amber-500)' }} />
                    <Text fontSize="xs" color="amber.700" _dark={{ color: 'amber.300' }}>
                        Храните API-ключ в секрете. Любой, кто знает ключ, получает доступ к вашим ценам и может создавать заказы.
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

            {/* ── Section 2: API v1 — основной ── */}
            <Box mb="10">
                <VStack align="stretch" gap="4">
                    <HStack>
                        <Flex
                            align="center" justify="center" w="10" h="10" borderRadius="lg"
                            bg="purple.50" _dark={{ bg: 'purple.900/30' }}
                        >
                            <LuBookOpen size={20} color="var(--chakra-colors-purple-500)" />
                        </Flex>
                        <Heading size="lg" fontWeight="700">Документация API v1</Heading>
                    </HStack>

                    <Text fontSize="sm" color="gray.500" lineHeight="1.7">
                        API v1 — основной и развивающийся интерфейс: каталог с вашими ценами и остатками,
                        корзины, заказы, реализации, документы и оплаты. Ключ передаётся в заголовке
                        {' '}<Code size="xs">Authorization: Bearer</Code>, ответы приходят в едином конверте
                        {' '}<Code size="xs">{'{data, meta}'}</Code>. ИИ-агента подключают в разделе «ИИ-агенты (MCP)»,
                        прежний формат с ключом в адресе описан в разделе «Legacy API».
                    </Text>

                    <HStack gap="3" flexWrap="wrap">
                        <Button asChild size="sm" bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }}>
                            <a href={docs.ui} target="_blank" rel="noopener noreferrer">
                                <LuExternalLink /> Открыть документацию
                            </a>
                        </Button>
                        <Button asChild size="sm" variant="outline">
                            <a href={docs.openapi} target="_blank" rel="noopener noreferrer">
                                <LuCode /> OpenAPI JSON для агентов
                            </a>
                        </Button>
                    </HStack>

                    {/* Быстрый старт */}
                    <Card.Root
                        bg="bg" borderRadius="xl"
                        border="1px solid" borderColor="border.muted"
                        overflow="hidden"
                    >
                        <Card.Body p="5">
                            <HStack mb="3">
                                <LuRocket size={18} style={{ color: 'var(--chakra-colors-purple-500)' }} />
                                <Text fontWeight="700" fontSize="sm">Быстрый старт</Text>
                            </HStack>
                            <VStack align="stretch" gap="3">
                                {quickStart.map((step, i) => (
                                    <Box key={i}>
                                        <Text fontSize="2xs" fontWeight="700" color="gray.400" textTransform="uppercase" letterSpacing="0.05em" mb="1.5">
                                            {i + 1}. {step.title}
                                        </Text>
                                        <Box bg="gray.900" _dark={{ bg: 'gray.950' }} borderRadius="lg" p="3" overflowX="auto">
                                            <Text as="pre" fontSize="xs" color="green.300" fontFamily="mono" whiteSpace="pre-wrap">
                                                {step.code}
                                            </Text>
                                        </Box>
                                    </Box>
                                ))}
                                <Text fontSize="2xs" color="gray.400">
                                    Начните с <Code size="xs">/me</Code>: он отдаёт ваши юрлица, состояние разделов
                                    и полный каталог операций с флагом <Code size="xs">allowed</Code>.
                                    Заголовок <Code size="xs">Idempotency-Key</Code> на создании заказа обязателен —
                                    повтор с тем же ключом не создаст второй заказ.
                                </Text>
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                </VStack>
            </Box>
        </CabinetLayout>
    );
}
