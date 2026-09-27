import { useEffect, useRef } from 'react';
import { Box, HStack, Stack, Text } from '@chakra-ui/react';
import { LuBot, LuCircleAlert } from 'react-icons/lu';
import { useAssistantStore } from '@/stores/useAssistantStore';
import AssistantMessage from './AssistantMessage';
import ConfirmationCard from './ConfirmationCard';
import Composer from './Composer';

/**
 * Лента диалога и поле ввода — общий блок для Drawer и страницы кабинета.
 */
export default function Conversation({ prefill = null, voice = true, attachments = {}, minH = '320px' }) {
    const messages = useAssistantStore((s) => s.messages);
    const confirmations = useAssistantStore((s) => s.confirmations);
    const busy = useAssistantStore((s) => s.busy);
    const error = useAssistantStore((s) => s.error);
    const loading = useAssistantStore((s) => s.loading);
    const quota = useAssistantStore((s) => s.quota);
    const decide = useAssistantStore((s) => s.decide);
    const bottomRef = useRef(null);
    const lastIdRef = useRef(0);

    const visible = messages.filter((m) => !(m.role === 'assistant' && m.status === 'pending' && !busy));
    const lastText = visible.length ? `${visible[visible.length - 1].id}:${(visible[visible.length - 1].text || '').length}` : '';

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ block: 'end', behavior: lastIdRef.current === 0 ? 'auto' : 'smooth' });
        lastIdRef.current = visible.length;
    }, [lastText, confirmations.length]);

    return (
        <Stack gap="0" h="100%" minH={minH} overflow="hidden">
            {/* Прокручивается только лента, не панель целиком: minH=0 нужен flex-элементу,
                иначе он растёт по содержимому и полоса уезжает на родителя. */}
            <Stack flex="1" minH="0" overflowY="auto" px="3" py="3" gap="3">
                {visible.length === 0 && !loading && (
                    <Stack align="center" justify="center" flex="1" color="fg.subtle" gap="2" py="8" textAlign="center">
                        <LuBot size={28} />
                        <Text fontSize="sm">Спросите о ценах и остатках, соберите заказ, попросите документы или сверку — отвечу по вашим данным.</Text>
                        <Text fontSize="xs">Можно прислать фото прайса или Excel: сравню с нашими ценами.</Text>
                    </Stack>
                )}
                {visible.map((m) => (
                    <AssistantMessage key={m.id} message={m} streaming={m.status === 'streaming' || m.status === 'pending'} />
                ))}
                {confirmations.map((c) => (
                    <ConfirmationCard key={c.id} confirmation={c} onDecide={decide} disabled={busy} />
                ))}
                {quota && (
                    <HStack gap="2" fontSize="xs" color="fg.muted" bg="bg.muted" p="2" borderRadius="md">
                        <LuCircleAlert size={14} />
                        <Text>{quota.message}</Text>
                    </HStack>
                )}
                {error && (
                    <HStack gap="2" fontSize="xs" color="red.600" bg="red.50" p="2" borderRadius="md">
                        <LuCircleAlert size={14} />
                        <Text>{error}</Text>
                    </HStack>
                )}
                <Box ref={bottomRef} />
            </Stack>
            <Composer prefill={prefill} voice={voice} attachments={attachments} />
        </Stack>
    );
}
