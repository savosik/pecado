import { useEffect } from 'react';
import { Badge, Box, Flex, HStack, Stack, Text } from '@chakra-ui/react';
import { Head, usePage } from '@inertiajs/react';
import { LuBot, LuMessageSquarePlus } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import { Button } from '@/components/ui/button';
import { useAssistantStore } from '@/stores/useAssistantStore';
import Conversation from '@/components/assistant/Conversation';

const formatDate = (iso) => {
    if (!iso) return '';
    try {
        return new Date(iso).toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    } catch {
        return iso;
    }
};

/**
 * Страница помощника в кабинете: треды слева, диалог справа. Тот же диалог,
 * что в Drawer на любой странице.
 */
export default function AssistantIndex() {
    const { threads, assistant } = usePage().props;
    const current = useAssistantStore((s) => s.thread);
    const open = useAssistantStore((s) => s.open);
    const switchThread = useAssistantStore((s) => s.switchThread);
    const openDialog = useAssistantStore((s) => s.openDialog);
    const closeDialog = useAssistantStore((s) => s.closeDialog);
    const newThread = useAssistantStore((s) => s.newThread);
    const startPolling = useAssistantStore((s) => s.startPolling);

    useEffect(() => {
        // Drawer на этой странице не нужен — диалог встроен в саму страницу.
        if (open) closeDialog();
        if (!current) openDialog().then(() => closeDialog());
        else startPolling();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const list = Array.isArray(threads) ? threads : [];

    return (
        <CabinetLayout title="Помощник">
            <Head title="Помощник" />
            <Flex gap="4" direction={{ base: 'column', md: 'row' }} align="stretch">
                <Stack w={{ base: '100%', md: '280px' }} flexShrink={0} gap="2">
                    <Button size="sm" variant="outline" onClick={newThread}>
                        <LuMessageSquarePlus /> Новый разговор
                    </Button>
                    <Stack gap="1" maxH={{ base: '200px', md: '70vh' }} overflowY="auto">
                        {list.length === 0 && (
                            <Text fontSize="sm" color="fg.subtle" px="2" py="3">Разговоров пока нет.</Text>
                        )}
                        {list.map((t) => (
                            <Box
                                key={t.id}
                                as="button"
                                textAlign="left"
                                onClick={() => switchThread(t.id)}
                                bg={current?.id === t.id ? 'pecado.50' : 'bg'}
                                borderWidth="1px"
                                borderColor={current?.id === t.id ? 'pecado.300' : 'border.muted'}
                                borderRadius="md"
                                px="3"
                                py="2"
                                _hover={{ borderColor: 'pecado.300' }}
                            >
                                <HStack justify="space-between" gap="2">
                                    <Text fontSize="sm" truncate flex="1">{t.title || 'Без темы'}</Text>
                                    {t.status === 'closed' && <Badge size="xs" colorPalette="gray">закрыт</Badge>}
                                </HStack>
                                <Text fontSize="xs" color="fg.subtle">{formatDate(t.last_message_at)}</Text>
                            </Box>
                        ))}
                    </Stack>
                </Stack>
                <Box flex="1" bg="bg" borderWidth="1px" borderColor="border.muted" borderRadius="lg" overflow="hidden" minH={{ base: '60vh', md: '70vh' }} display="flex" flexDirection="column">
                    <HStack px="3" py="2" borderBottomWidth="1px" borderColor="border.muted" gap="2">
                        <LuBot size={16} />
                        <Text fontSize="sm" fontWeight="semibold" truncate>{current?.title || 'Помощник'}</Text>
                    </HStack>
                    <Box flex="1" minH="0">
                        <Conversation voice={assistant?.voice ?? true} attachments={assistant?.attachments || {}} minH="0" />
                    </Box>
                </Box>
            </Flex>
        </CabinetLayout>
    );
}
