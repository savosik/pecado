import { useEffect, useMemo, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Badge,
    Box,
    Container,
    Flex,
    Heading,
    HStack,
    Image,
    Text,
    VStack,
} from '@chakra-ui/react';
import UserLayout from '../UserLayout';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { Radio, RadioGroup } from '@/components/ui/radio';
import { NumberInputField, NumberInputRoot } from '@/components/ui/number-input';
import { LuCircleCheck, LuPhone } from 'react-icons/lu';

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(value || 0);

/**
 * «Замена по заказу N» — страница без единого поля ввода реквизитов.
 *
 * По каждой отменённой строке — радио-варианты замены с ценами клиента,
 * «подождать прихода» и «без замены». Количество предзаполнено, увеличить
 * нельзя. Выбор хранится в localStorage — переживает редирект на вход.
 */
export default function Show({ state, offer, isOwner = false }) {
    const { auth } = usePage().props;
    const storageKey = `substitution-choice-${offer.uuid}`;

    // choices[lineId] = { type: 'candidate'|'wait'|'none', candidateId, quantity }
    const [choices, setChoices] = useState({});
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [result, setResult] = useState(null);

    useEffect(() => {
        try {
            const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (saved) setChoices(saved);
        } catch { /* повреждённый черновик выбора игнорируем */ }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const persist = (next) => {
        setChoices(next);
        try {
            localStorage.setItem(storageKey, JSON.stringify(next));
        } catch { /* приватный режим — просто не сохраняем */ }
    };

    const choiceFor = (line) => choices[line.id] || { type: 'none', candidateId: null, quantity: null };

    const setChoice = (line, patch) => {
        persist({ ...choices, [line.id]: { ...choiceFor(line), ...patch } });
    };

    const totals = useMemo(() => {
        if (state !== 'open') return { replacement: 0, original: 0 };

        let replacement = 0;
        let original = 0;

        (offer.lines || []).forEach((line) => {
            original += line.subtotal;
            const choice = choices[line.id];
            if (choice?.type === 'candidate') {
                const candidate = line.candidates.find((c) => c.id === choice.candidateId);
                if (candidate) {
                    const qty = Math.min(choice.quantity || candidate.suggested_quantity, candidate.max_quantity);
                    replacement += candidate.price * qty;
                }
            }
        });

        return { replacement, original };
    }, [choices, offer, state]);

    const submit = async () => {
        if (!auth?.user) {
            // Вход с возвратом на эту же страницу: выбор уже в localStorage.
            const url = new URL(window.location.href);
            url.searchParams.set('login', '1');
            window.location.href = url.toString();
            return;
        }

        setBusy(true);
        setError(null);

        try {
            const payload = {
                choices: (offer.lines || []).map((line) => {
                    const choice = choiceFor(line);
                    const candidate = line.candidates.find((c) => c.id === choice.candidateId);

                    return {
                        source_order_item_id: line.id,
                        type: choice.type,
                        candidate_id: choice.type === 'candidate' ? choice.candidateId : null,
                        quantity: choice.type === 'candidate' && candidate
                            ? Math.min(choice.quantity || candidate.suggested_quantity, candidate.max_quantity)
                            : null,
                    };
                }),
            };

            const { data } = await axios.post(`/substitutions/${offer.uuid}/confirm`, payload);
            localStorage.removeItem(storageKey);
            setResult(data);
        } catch (e) {
            if (e.response?.status === 409) {
                setError(e.response.data.message);
                setTimeout(() => window.location.reload(), 4000);
            } else {
                setError(e.response?.data?.message || 'Не удалось согласовать замену. Попробуйте ещё раз или свяжитесь с менеджером.');
            }
        } finally {
            setBusy(false);
        }
    };

    const managerBlock = offer.manager?.name && (
        <Box borderWidth="1px" borderRadius="lg" p={4} mt={6}>
            <HStack gap={2} mb={1}>
                <LuPhone size={16} />
                <Text fontWeight="medium">Ваш менеджер: {offer.manager.name}</Text>
            </HStack>
            <Text fontSize="sm" color="fg.muted">
                {[offer.manager.phone, offer.manager.email].filter(Boolean).join(' · ')}
            </Text>
        </Box>
    );

    // ── Заглушки: неактуальная и уже согласованная подборка ──
    if (state === 'inactive' || state === 'confirmed') {
        return (
            <UserLayout>
                <Head title={`Замена по заказу ${offer.order_number}`} />
                <Container maxW="2xl" py={12}>
                    {state === 'confirmed' ? (
                        <Alert status="success" title="Замена уже согласована">
                            {(offer.orders || []).length > 0
                                ? `Создан заказ ${offer.orders.map((o) => o.number).join(', ')} — он связан с заказом ${offer.order_number}.`
                                : `Выбор по заказу ${offer.order_number} зафиксирован.`}
                        </Alert>
                    ) : (
                        <Alert status="warning" title="Подборка неактуальна">
                            Срок действия подборки по заказу {offer.order_number} истёк или она закрыта.
                            Свяжитесь с менеджером — подберём замену вместе.
                        </Alert>
                    )}
                    {managerBlock}
                </Container>
            </UserLayout>
        );
    }

    // ── Успех после согласования ──
    if (result) {
        return (
            <UserLayout>
                <Head title={`Замена по заказу ${offer.order_number}`} />
                <Container maxW="2xl" py={12}>
                    <VStack gap={4} align="stretch">
                        <Alert status="success" title={result.already_confirmed ? 'Уже согласовано' : 'Замена согласована'}>
                            {(result.orders || []).length > 0
                                ? `Создан заказ ${result.orders.map((o) => o.number).join(', ')}, связан с заказом ${result.source_number || offer.order_number}. Менеджер уже видит ваш выбор.`
                                : 'Вы отказались от замены — мы зафиксировали это, менеджер свяжется при необходимости.'}
                        </Alert>
                        {(result.orders || []).map((o) => (
                            <a key={o.id} href={`/cabinet/orders/${o.id}`}>
                                <Button variant="outline" width="full">
                                    <LuCircleCheck /> Открыть заказ {o.number}
                                </Button>
                            </a>
                        ))}
                        {managerBlock}
                    </VStack>
                </Container>
            </UserLayout>
        );
    }

    // ── Основная страница выбора ──
    return (
        <UserLayout>
            <Head title={`Замена по заказу ${offer.order_number}`} />
            <Container maxW="3xl" py={{ base: 4, md: 8 }}>
                <Heading size="lg" mb={1}>Замена по заказу {offer.order_number}</Heading>
                <Text color="fg.muted" mb={6}>
                    Часть позиций не прошла контроль качества при сборке. Ниже — варианты замены
                    с вашими ценами. Подборка действует до {offer.expires_at}.
                </Text>

                {error && (
                    <Alert status="error" title="Не получилось" mb={4}>{error}</Alert>
                )}

                <VStack align="stretch" gap={5}>
                    {offer.lines.map((line) => {
                        const choice = choiceFor(line);

                        return (
                            <Box key={line.id} borderWidth="1px" borderRadius="lg" p={{ base: 3, md: 4 }}>
                                <Text fontWeight="medium" textDecoration="line-through" color="fg.muted">
                                    {line.name}
                                </Text>
                                <Text fontSize="sm" color="fg.muted" mb={3}>
                                    отменено {line.quantity} шт. на {money(line.subtotal)} ₽
                                </Text>

                                <RadioGroup
                                    value={choice.type === 'candidate' ? `c-${choice.candidateId}` : choice.type}
                                    onValueChange={(e) => {
                                        const value = e.value;
                                        if (value === 'wait' || value === 'none') {
                                            setChoice(line, { type: value, candidateId: null });
                                        } else {
                                            const id = Number(value.slice(2));
                                            const candidate = line.candidates.find((c) => c.id === id);
                                            setChoice(line, {
                                                type: 'candidate',
                                                candidateId: id,
                                                quantity: candidate ? Math.min(candidate.suggested_quantity, candidate.max_quantity) : 1,
                                            });
                                        }
                                    }}
                                >
                                    <VStack align="stretch" gap={2}>
                                        {line.candidates.map((candidate) => (
                                            <Box
                                                key={candidate.id}
                                                borderWidth="1px"
                                                borderRadius="md"
                                                p={3}
                                                bg={candidate.is_defect ? 'orange.subtle' : 'transparent'}
                                                borderColor={choice.candidateId === candidate.id ? 'colorPalette.solid' : 'border'}
                                            >
                                                <Radio value={`c-${candidate.id}`}>
                                                    <Flex gap={3} align="center" flexWrap="wrap">
                                                        {candidate.image_url && (
                                                            <Image src={candidate.image_url} alt="" boxSize="44px" objectFit="cover" borderRadius="md" />
                                                        )}
                                                        <Box flex="1" minW="200px">
                                                            <HStack gap={2} flexWrap="wrap">
                                                                <Text fontSize="sm" fontWeight="medium">{candidate.name}</Text>
                                                                {candidate.is_defect && (
                                                                    <Badge colorPalette="orange" variant="solid" size="xs">уценка</Badge>
                                                                )}
                                                            </HStack>
                                                            <Text fontSize="xs" color="fg.muted">
                                                                {candidate.is_defect && candidate.defect_description
                                                                    ? `${candidate.reason} — ${candidate.defect_description}`
                                                                    : candidate.reason}
                                                            </Text>
                                                            {candidate.partial && (
                                                                <Text fontSize="xs" color="orange.fg">
                                                                    доступно {candidate.max_quantity} из {line.quantity}
                                                                </Text>
                                                            )}
                                                        </Box>
                                                        <Text fontWeight="semibold" whiteSpace="nowrap">
                                                            {money(candidate.price)} ₽/шт.
                                                        </Text>
                                                    </Flex>
                                                </Radio>

                                                {choice.type === 'candidate' && choice.candidateId === candidate.id && candidate.max_quantity > 1 && (
                                                    <HStack mt={2} pl={7} gap={2}>
                                                        <Text fontSize="sm" color="fg.muted">Количество:</Text>
                                                        <NumberInputRoot
                                                            size="sm"
                                                            width="90px"
                                                            min={1}
                                                            max={candidate.max_quantity}
                                                            value={String(choice.quantity || candidate.max_quantity)}
                                                            onValueChange={(e) => setChoice(line, {
                                                                quantity: Math.min(Number(e.value) || 1, candidate.max_quantity),
                                                            })}
                                                        >
                                                            <NumberInputField />
                                                        </NumberInputRoot>
                                                        <Text fontSize="xs" color="fg.muted">не больше отменённого</Text>
                                                    </HStack>
                                                )}
                                            </Box>
                                        ))}

                                        {line.wait_available && (
                                            <Box borderWidth="1px" borderRadius="md" p={3}>
                                                <Radio value="wait">
                                                    <Text fontSize="sm">
                                                        Подождать прихода — товар ожидается на складе, зарезервируем за вами
                                                    </Text>
                                                </Radio>
                                            </Box>
                                        )}

                                        <Box borderWidth="1px" borderRadius="md" p={3}>
                                            <Radio value="none">
                                                <Text fontSize="sm" color="fg.muted">Без замены по этой позиции</Text>
                                            </Radio>
                                        </Box>
                                    </VStack>
                                </RadioGroup>
                            </Box>
                        );
                    })}
                </VStack>

                <Flex
                    mt={6}
                    p={4}
                    borderWidth="1px"
                    borderRadius="lg"
                    justify="space-between"
                    align="center"
                    gap={4}
                    flexWrap="wrap"
                    position="sticky"
                    bottom={2}
                    bg="bg.panel"
                    boxShadow="md"
                >
                    <Box>
                        <Text fontWeight="semibold">
                            Итого замена: {money(totals.replacement)} ₽
                        </Text>
                        <Text fontSize="sm" color="fg.muted">
                            вместо {money(totals.original)} ₽ отменённых позиций
                        </Text>
                    </Box>
                    <Button colorPalette="green" size="lg" loading={busy} onClick={submit}>
                        Согласовать замену
                    </Button>
                </Flex>

                {!auth?.user && (
                    <Text mt={2} fontSize="xs" color="fg.muted">
                        Для согласования потребуется вход в аккаунт — выбор сохранится.
                    </Text>
                )}
                {auth?.user && !isOwner && (
                    <Alert status="warning" mt={3} title="Вы вошли под другим аккаунтом">
                        Согласовать замену может только владелец заказа.
                    </Alert>
                )}

                {managerBlock}
            </Container>
        </UserLayout>
    );
}
