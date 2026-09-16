import { useEffect, useRef, useState } from 'react';
import { Box, Flex, HStack, Text, VStack } from '@chakra-ui/react';
import { AI_AGENT_GROUPS } from './aiAgentLogos';

/**
 * Баннер раздела подключения MCP: живой чат клиента с его ИИ-агентом.
 *
 * Анимирован кодом, а не GIF-файлом: текст остаётся чётким на любом экране,
 * баннер весит килобайты, а не мегабайты, и его можно править без дизайнера.
 * Реплики агента — ровно то, что сервер умеет сегодня (инструменты указаны
 * настоящие); обещаний сверх API здесь нет.
 *
 * Бережно к машине: таймер один, ставится на паузу вне экрана и в фоновой
 * вкладке; при «уменьшить движение» показывается статичный кадр.
 */

const BRAND = '#9e1b32';
const TICK = 50;
const T = { typing: 700, tool: 1500, answer: 2300, charMs: 18, hold: 3400 };

const DIALOGS = [
    {
        label: 'Заказ',
        question: 'Где мой заказ 29УТ-014379?',
        tool: 'client-order-status',
        answer: 'В отгрузке со склада: 12 позиций на 8 344,95 ₽, всё собрано без отмен. Счёт на оплату уже в документах — прислать ссылку?',
    },
    {
        label: 'Оплата',
        question: 'Сколько мы должны и когда платить?',
        tool: 'client-balance',
        answer: 'Просрочки нет. Ближайший платёж — 40 000 ₽ до 20 сентября. Подготовить платёжку с QR-кодом для бухгалтерии?',
    },
    {
        label: 'Повтор',
        question: 'Повтори прошлый заказ, но только то, что есть на складе',
        tool: 'client-call → orders.repeat',
        answer: 'Собрал корзину: 8 позиций в наличии на 3 460 ₽. Ещё 5 можно взять предзаказом, поставка 7–9 дней. Оформляю только наличие?',
    },
    {
        label: 'Документы',
        question: 'Пришли УПД и акт сверки за август',
        tool: 'client-documents',
        answer: 'Нашёл 6 УПД и акт сверки по обоим юрлицам. Ссылки на PDF действуют час — переслать бухгалтеру?',
    },
    {
        label: 'Менеджер',
        question: 'Попроси отправить оба заказа одной машиной',
        tool: 'client-ask-manager',
        answer: 'Передал вашему менеджеру вместе с номерами заказов. Ответ обычно в течение рабочего дня — проверю и расскажу.',
    },
];

const durationOf = (index) => T.answer + DIALOGS[index].answer.length * T.charMs + T.hold;

function usePrefersReducedMotion() {
    const [reduced, setReduced] = useState(
        () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches,
    );

    useEffect(() => {
        const query = window.matchMedia?.('(prefers-reduced-motion: reduce)');
        if (!query) return undefined;
        const onChange = (event) => setReduced(event.matches);
        query.addEventListener('change', onChange);
        return () => query.removeEventListener('change', onChange);
    }, []);

    return reduced;
}

function AgentLogo({ item }) {
    return (
        <HStack
            gap="1.5" px="2.5" py="1.5" borderRadius="full"
            bg="rgba(255,255,255,0.06)" border="1px solid rgba(255,255,255,0.10)"
            flexShrink={0}
        >
            {item.path ? (
                <Box as="svg" viewBox="0 0 24 24" w="16px" h="16px" aria-hidden="true">
                    <path d={item.path} fill={item.hex ?? 'currentColor'} />
                </Box>
            ) : (
                <Flex
                    w="16px" h="16px" borderRadius="full" bg={item.hex}
                    align="center" justify="center" aria-hidden="true"
                >
                    <Text fontSize="9px" fontWeight="800" color="white" lineHeight="1">{item.monogram}</Text>
                </Flex>
            )}
            <Text fontSize="xs" fontWeight="600" color="whiteAlpha.900" whiteSpace="nowrap">{item.name}</Text>
        </HStack>
    );
}

function TypingDots() {
    return (
        <HStack gap="1" px="3.5" py="3" borderRadius="16px" borderTopLeftRadius="4px" bg="rgba(255,255,255,0.08)">
            {[0, 1, 2].map((i) => (
                <Box
                    key={i} w="6px" h="6px" borderRadius="full" bg="whiteAlpha.700"
                    animation={`agentDot 1s ${i * 0.15}s infinite ease-in-out`}
                />
            ))}
        </HStack>
    );
}

export default function AgentChatBanner() {
    const rootRef = useRef(null);
    const reduced = usePrefersReducedMotion();
    const [visible, setVisible] = useState(true);
    const [pageVisible, setPageVisible] = useState(true);
    const [state, setState] = useState({ index: 0, t: 0 });

    useEffect(() => {
        const node = rootRef.current;
        if (!node || typeof IntersectionObserver === 'undefined') return undefined;
        const observer = new IntersectionObserver(([entry]) => setVisible(entry.isIntersecting), { threshold: 0.15 });
        observer.observe(node);
        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const onVisibility = () => setPageVisible(document.visibilityState === 'visible');
        document.addEventListener('visibilitychange', onVisibility);
        return () => document.removeEventListener('visibilitychange', onVisibility);
    }, []);

    useEffect(() => {
        if (reduced || !visible || !pageVisible) return undefined;

        const id = setInterval(() => {
            setState((s) => {
                const t = s.t + TICK;
                return t >= durationOf(s.index) ? { index: (s.index + 1) % DIALOGS.length, t: 0 } : { index: s.index, t };
            });
        }, TICK);

        return () => clearInterval(id);
    }, [reduced, visible, pageVisible]);

    // Статичный кадр: реплика целиком, без печати и таймера.
    const t = reduced ? Number.MAX_SAFE_INTEGER : state.t;
    const dialog = DIALOGS[state.index];
    const typed = Math.max(0, Math.min(dialog.answer.length, Math.floor((t - T.answer) / T.charMs)));
    const answering = t >= T.answer;
    const answerDone = typed >= dialog.answer.length;

    return (
        <Box
            ref={rootRef}
            borderRadius="2xl" overflow="hidden" color="white"
            bg="linear-gradient(135deg, #1b0a11 0%, #2d0f1b 50%, #120d16 100%)"
            border="1px solid rgba(255,255,255,0.08)"
            boxShadow="0 18px 40px rgba(20, 4, 10, 0.25)"
            aria-label="Пример работы ИИ-агента в кабинете Pecado"
        >
            <style>{`
                @keyframes agentDot { 0%, 80%, 100% { transform: translateY(0); opacity: .45 } 40% { transform: translateY(-4px); opacity: 1 } }
                @keyframes agentIn { from { opacity: 0; transform: translateY(8px) } to { opacity: 1; transform: none } }
                @keyframes agentCaret { 50% { opacity: 0 } }
            `}</style>

            {/* Заголовок окна */}
            <HStack justify="space-between" px={{ base: 4, md: 5 }} py="3" borderBottom="1px solid rgba(255,255,255,0.07)">
                <HStack gap="3">
                    <HStack gap="1.5" aria-hidden="true">
                        <Box w="9px" h="9px" borderRadius="full" bg="#ff5f57" />
                        <Box w="9px" h="9px" borderRadius="full" bg="#febc2e" />
                        <Box w="9px" h="9px" borderRadius="full" bg="#28c840" />
                    </HStack>
                    <Text fontSize="xs" fontWeight="600" color="whiteAlpha.800">Ваш ИИ-агент · Pecado MCP</Text>
                </HStack>
                <HStack gap="1.5">
                    <Box w="7px" h="7px" borderRadius="full" bg="#28c840" boxShadow="0 0 0 3px rgba(40,200,64,0.18)" />
                    <Text fontSize="2xs" color="whiteAlpha.700">на связи</Text>
                </HStack>
            </HStack>

            {/* Диалог */}
            <VStack
                key={state.index}
                align="stretch" gap="3"
                px={{ base: 4, md: 5 }} pt="4" pb="3"
                h={{ base: '270px', md: '230px' }}
                overflow="hidden"
                aria-live="off"
            >
                <Flex justify="flex-end" animation={reduced ? undefined : 'agentIn .35s ease-out'}>
                    <Box
                        maxW="85%" px="3.5" py="2.5" bg={BRAND}
                        borderRadius="16px" borderBottomRightRadius="4px"
                    >
                        <Text fontSize="sm" lineHeight="1.5">{dialog.question}</Text>
                    </Box>
                </Flex>

                {t >= T.typing && (
                    <HStack align="flex-start" gap="2.5" animation={reduced ? undefined : 'agentIn .35s ease-out'}>
                        <Flex
                            w="28px" h="28px" borderRadius="full" flexShrink={0}
                            bg="linear-gradient(135deg, #c2294a, #6d1223)"
                            align="center" justify="center" aria-hidden="true"
                        >
                            <Box as="svg" viewBox="0 0 24 24" w="14px" h="14px">
                                <path fill="white" d="M12 2l1.9 5.6L19.5 9.5l-5.6 1.9L12 17l-1.9-5.6L4.5 9.5l5.6-1.9zM19 15l.9 2.1L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.9z" />
                            </Box>
                        </Flex>

                        <VStack align="flex-start" gap="2" maxW="88%">
                            {t < T.tool ? (
                                <TypingDots />
                            ) : (
                                <HStack
                                    gap="1.5" px="2" py="1" borderRadius="md"
                                    bg="rgba(255,255,255,0.05)" border="1px dashed rgba(255,255,255,0.15)"
                                >
                                    <Text fontSize="2xs" color={answering ? '#6ee7a0' : 'whiteAlpha.600'}>{answering ? '✓' : '…'}</Text>
                                    <Text fontSize="2xs" fontFamily="mono" color="whiteAlpha.700">{dialog.tool}</Text>
                                </HStack>
                            )}

                            {answering && (
                                <Box
                                    px="3.5" py="2.5" bg="rgba(255,255,255,0.08)"
                                    border="1px solid rgba(255,255,255,0.10)"
                                    borderRadius="16px" borderTopLeftRadius="4px"
                                >
                                    <Text fontSize="sm" lineHeight="1.55" color="whiteAlpha.950">
                                        {dialog.answer.slice(0, typed)}
                                        {!answerDone && (
                                            <Box as="span" display="inline-block" w="2px" h="1em" ml="1px" bg="whiteAlpha.800" verticalAlign="text-bottom" animation="agentCaret 1s steps(1) infinite" />
                                        )}
                                    </Text>
                                </Box>
                            )}
                        </VStack>
                    </HStack>
                )}
            </VStack>

            {/* Темы */}
            <HStack px={{ base: 4, md: 5 }} pb="4" gap="1.5" flexWrap="wrap">
                {DIALOGS.map((d, i) => {
                    const active = i === state.index;
                    return (
                        <Box
                            as="button" type="button" key={d.label}
                            onClick={() => setState({ index: i, t: 0 })}
                            px="2.5" py="1" borderRadius="full" fontSize="2xs" fontWeight="600"
                            bg={active ? 'rgba(158,27,50,0.55)' : 'rgba(255,255,255,0.05)'}
                            color={active ? 'white' : 'whiteAlpha.700'}
                            border="1px solid" borderColor={active ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.08)'}
                            cursor="pointer" transition="background .2s"
                            aria-pressed={active}
                        >
                            {d.label}
                        </Box>
                    );
                })}
            </HStack>

            {/* Логотипы агентов */}
            <Box px={{ base: 4, md: 5 }} py="4" bg="rgba(0,0,0,0.22)" borderTop="1px solid rgba(255,255,255,0.07)">
                <Text fontSize="2xs" fontWeight="700" letterSpacing="0.08em" textTransform="uppercase" color="whiteAlpha.600" mb="3">
                    Подключается к агентам с поддержкой MCP
                </Text>
                <Flex gap={{ base: 3, md: 5 }} flexWrap="wrap">
                    {AI_AGENT_GROUPS.map((group) => (
                        <Box key={group.region}>
                            <Text fontSize="2xs" color="whiteAlpha.500" mb="1.5">{group.region}</Text>
                            <Flex gap="1.5" flexWrap="wrap">
                                {group.items.map((item) => <AgentLogo key={item.name} item={item} />)}
                            </Flex>
                        </Box>
                    ))}
                </Flex>
            </Box>

            {/* Слоган */}
            <Box px={{ base: 4, md: 5 }} py={{ base: 5, md: 6 }} textAlign="center">
                <Text fontSize={{ base: 'xl', md: '2xl' }} fontWeight="800" lineHeight="1.25" letterSpacing="-0.01em">
                    Рутину — агенту.{' '}
                    <Box
                        as="span"
                        display={{ base: 'block', sm: 'inline' }}
                        bgImage="linear-gradient(90deg, #ff8fa3, #f4c27a)"
                        bgClip="text" color="transparent"
                    >
                        Будущее уже{'\u00a0'}в{'\u00a0'}Pecado.
                    </Box>
                </Text>
            </Box>
        </Box>
    );
}
