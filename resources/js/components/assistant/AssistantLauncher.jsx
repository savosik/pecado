import { useEffect } from 'react';
import { Box, HStack, IconButton, Text } from '@chakra-ui/react';
import { router, usePage } from '@inertiajs/react';
import { LuBot, LuX } from 'react-icons/lu';
import { useAssistantStore } from '@/stores/useAssistantStore';
import { isStaffPanel, pageContextFrom } from './pageContext';
import AssistantDrawer from './AssistantDrawer';

/**
 * Иконка-консультант в углу страницы. Живёт в корневом лейауте, переживает
 * переходы Inertia; при переходе выпускает и гасит одну реплику по контексту
 * страницы; клик по реплике открывает диалог с этим вопросом.
 *
 * Рендерится только когда сервер дал prop `assistant` (авторизованный клиент
 * с юрлицом и доступный помощник). null — ни иконки, ни реплик.
 */
export default function AssistantLauncher() {
    const { props, component } = usePage();
    const assistant = props.assistant;
    const userId = props.auth?.user?.id ?? null;

    const bubble = useAssistantStore((s) => s.bubble);
    const open = useAssistantStore((s) => s.open);
    const setOwner = useAssistantStore((s) => s.setOwner);
    const setBubbleRules = useAssistantStore((s) => s.setBubbleRules);
    const onNavigate = useAssistantStore((s) => s.onNavigate);
    const openDialog = useAssistantStore((s) => s.openDialog);
    const clickBubble = useAssistantStore((s) => s.clickBubble);
    const dismissBubble = useAssistantStore((s) => s.dismissBubble);
    const event = useAssistantStore((s) => s.event);
    const unread = assistant?.unread ?? 0;

    const active = Boolean(assistant?.available) && !isStaffPanel(component);

    useEffect(() => {
        setOwner(userId);
    }, [userId, setOwner]);

    useEffect(() => {
        if (!active) return undefined;

        setBubbleRules(assistant.bubbles);
        onNavigate(pageContextFrom(component, props), assistant.bubbles);
        event('shown', { page: pageContextFrom(component, props).type });

        const off = router.on('navigate', (e) => {
            const page = e.detail.page;
            const context = pageContextFrom(page.component, page.props);
            onNavigate(context, page.props.assistant?.bubbles || assistant.bubbles);
        });

        return off;
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active, userId]);

    if (!active) return null;

    const openFromIcon = () => {
        event('opened', { page: pageContextFrom(component, props).type });
        openDialog();
    };

    return (
        <>
            <Box
                position="fixed"
                right={{ base: '12px', md: '20px' }}
                bottom={{ base: 'calc(78px + env(safe-area-inset-bottom))', lg: '24px' }}
                zIndex="1350"
                display="flex"
                flexDirection="column"
                alignItems="flex-end"
                gap="2"
                pointerEvents="none"
            >
                {bubble && !open && (
                    <HStack
                        role="button"
                        tabIndex={0}
                        onClick={clickBubble}
                        onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') clickBubble(); }}
                        pointerEvents="auto"
                        bg="bg"
                        color="fg"
                        boxShadow="lg"
                        borderWidth="1px"
                        borderColor="border.muted"
                        borderRadius="xl"
                        borderBottomRightRadius="sm"
                        px="3"
                        py="2"
                        maxW="280px"
                        fontSize="sm"
                        cursor="pointer"
                        gap="2"
                        css={{
                            animation: 'assistantBubbleIn 220ms ease-out',
                            '@media (prefers-reduced-motion: reduce)': { animation: 'none' },
                            '@keyframes assistantBubbleIn': {
                                from: { opacity: 0, transform: 'translateY(6px)' },
                                to: { opacity: 1, transform: 'translateY(0)' },
                            },
                        }}
                        _hover={{ borderColor: 'pecado.300' }}
                    >
                        <Text flex="1">{bubble.text}</Text>
                        <IconButton
                            size="2xs"
                            variant="ghost"
                            aria-label="Скрыть"
                            onClick={(e) => { e.stopPropagation(); dismissBubble(); }}
                        >
                            <LuX />
                        </IconButton>
                    </HStack>
                )}
                <Box position="relative" pointerEvents="auto">
                    <IconButton
                        aria-label="Помощник"
                        title="Помощник: цены, заказы, документы, долг"
                        colorPalette="pecado"
                        borderRadius="full"
                        size="lg"
                        boxShadow="lg"
                        onClick={openFromIcon}
                    >
                        <LuBot />
                    </IconButton>
                    {unread > 0 && !open && (
                        <Box position="absolute" top="-1px" right="-1px" w="10px" h="10px" bg="red.500" borderRadius="full" borderWidth="2px" borderColor="bg" />
                    )}
                </Box>
            </Box>
            <AssistantDrawer voice={assistant.voice} attachments={assistant.attachments} />
        </>
    );
}
