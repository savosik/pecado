import { HStack, IconButton, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuBot, LuHistory, LuMessageSquarePlus } from 'react-icons/lu';
import {
    DrawerBody,
    DrawerCloseTrigger,
    DrawerContent,
    DrawerHeader,
    DrawerRoot,
    DrawerTitle,
} from '@/components/ui/drawer';
import { useAssistantStore } from '@/stores/useAssistantStore';
import Conversation from './Conversation';

/**
 * Диалог помощника поверх текущей страницы: клиент продолжает смотреть
 * товар, пока помощник отвечает.
 */
export default function AssistantDrawer({ voice, attachments }) {
    const open = useAssistantStore((s) => s.open);
    const prefill = useAssistantStore((s) => s.prefill);
    const thread = useAssistantStore((s) => s.thread);
    const busy = useAssistantStore((s) => s.busy);
    const closeDialog = useAssistantStore((s) => s.closeDialog);
    const newThread = useAssistantStore((s) => s.newThread);

    return (
        // Немодальный: без затемнения, фокус не заперт, страница под ним живая —
        // клиент кликает по товару из ответа, быстрый просмотр открывается поверх
        // и его крестик работает. Закрывается только крестиком или Esc.
        <DrawerRoot
            open={open}
            onOpenChange={(e) => { if (!e.open) closeDialog(); }}
            placement="end"
            size={{ base: 'full', md: 'lg' }}
            modal={false}
            closeOnInteractOutside={false}
            trapFocus={false}
            preventScroll={false}
        >
            <DrawerContent boxShadow="2xl">
                <DrawerHeader py="3" borderBottomWidth="1px" borderColor="border.muted">
                    <HStack justify="space-between" pr="8">
                        <HStack gap="2">
                            <LuBot size={18} />
                            <DrawerTitle fontSize="md">Помощник</DrawerTitle>
                            {thread?.title && (
                                <Text fontSize="xs" color="fg.subtle" maxW="180px" truncate display={{ base: 'none', md: 'block' }}>
                                    · {thread.title}
                                </Text>
                            )}
                        </HStack>
                        <HStack gap="1">
                            <IconButton size="sm" variant="ghost" aria-label="Новый разговор" title="Новый разговор" onClick={newThread} disabled={busy}>
                                <LuMessageSquarePlus />
                            </IconButton>
                            <IconButton size="sm" variant="ghost" aria-label="История разговоров" title="История разговоров" asChild>
                                <Link href="/cabinet/assistant" onClick={closeDialog}>
                                    <LuHistory />
                                </Link>
                            </IconButton>
                        </HStack>
                    </HStack>
                </DrawerHeader>
                <DrawerBody p="0" display="flex" flexDirection="column">
                    <Conversation prefill={prefill} voice={voice} attachments={attachments} minH="0" />
                </DrawerBody>
                <DrawerCloseTrigger />
            </DrawerContent>
        </DrawerRoot>
    );
}
