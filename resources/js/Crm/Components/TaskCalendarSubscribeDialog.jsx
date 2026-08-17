import { useEffect, useState } from 'react';
import axios from 'axios';
import { Box, Dialog, HStack, Input, Portal, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuCopy, LuRefreshCw } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Подписка внешнего календаря на задачи: готовая ссылка + инструкция.
 *
 * Ссылка одна и постоянная — календарь сам подтягивает изменения. Перевыпуск
 * отзывает утёкший URL. Честный дисклеймер: Google обновляет внешние фиды
 * раз в несколько часов, мгновенных изменений там не будет.
 */
export default function TaskCalendarSubscribeDialog({ open, onClose }) {
    const [links, setLinks] = useState([]);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (!open) {
            return;
        }

        axios.get('/crm/tasks/calendar-links')
            .then((res) => setLinks(res.data.data))
            .catch(() => toastError('Не удалось получить ссылки подписки'));
    }, [open]);

    const copy = async (url) => {
        try {
            await navigator.clipboard.writeText(url);
            toastSuccess('Ссылка скопирована');
        } catch {
            toastError('Не удалось скопировать — выделите ссылку вручную');
        }
    };

    const rotate = async (scope) => {
        setBusy(true);
        try {
            const res = await axios.post('/crm/tasks/calendar-links/rotate', { scope });
            setLinks(res.data.data);
            toastSuccess('Ссылка перевыпущена', 'Старая ссылка больше не работает.');
        } catch (e) {
            toastError('Не удалось перевыпустить', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={({ open: isOpen }) => !isOpen && onClose()} size="lg" scrollBehavior="inside">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Подписаться в календаре</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={5}>
                                {links.map((link) => (
                                    <Box key={link.scope}>
                                        <HStack justify="space-between" mb={1}>
                                            <Text fontSize="sm" fontWeight="600">{link.label}</Text>
                                            {link.last_fetched_at_label && (
                                                <Text fontSize="xs" color="green.fg">
                                                    календарь забирал фид {link.last_fetched_at_label}
                                                </Text>
                                            )}
                                        </HStack>
                                        <HStack gap={2}>
                                            <Input size="sm" readOnly value={link.url} onFocus={(e) => e.target.select()} />
                                            <Button size="sm" variant="outline" onClick={() => copy(link.url)} title="Скопировать ссылку">
                                                <LuCopy />
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                colorPalette="red"
                                                loading={busy}
                                                onClick={() => rotate(link.scope)}
                                                title="Перевыпустить: старая ссылка перестанет работать"
                                            >
                                                <LuRefreshCw />
                                            </Button>
                                        </HStack>
                                    </Box>
                                ))}

                                <Box borderTopWidth="1px" pt={3}>
                                    <Text fontSize="sm" fontWeight="600" mb={1}>Google Календарь</Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        Настройки → «Добавить календарь» → «Добавить по URL» → вставьте ссылку.
                                    </Text>
                                    <Text fontSize="sm" fontWeight="600" mt={3} mb={1}>Яндекс Календарь</Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        Слева «Новый календарь» → «По ссылке (iCal)» → вставьте ссылку.
                                    </Text>
                                    <Text fontSize="xs" color="orange.fg" mt={3}>
                                        Внешние календари обновляют подписки сами, раз в несколько часов
                                        (Google — иногда до суток). Перенесённая задача появится там
                                        с задержкой — это ограничение календарей, не сайта.
                                    </Text>
                                </Box>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="outline" onClick={onClose}>Закрыть</Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
