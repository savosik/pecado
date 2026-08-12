import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { Box, HStack, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Тонкая полоска «кто из партнёров сейчас на сайте».
 *
 * Смысл прикладной: клиент открыл каталог прямо сейчас — это лучший момент
 * позвонить, а не через два дня, когда менеджер доберётся до списка.
 *
 * Вебсокетов в проекте нет, поэтому опрос. Интервал приходит с сервера
 * (`crm.presence.poll_seconds`), чтобы менять частоту конфигом, а не релизом.
 *
 * Полоска исчезает целиком, когда никого нет: пустая плашка «сейчас никого»
 * занимает ту же высоту и не сообщает ничего.
 */
export default function PresenceBar() {
    const [state, setState] = useState({ clients: [], total: 0 });
    const timer = useRef(null);

    useEffect(() => {
        let cancelled = false;

        const poll = async () => {
            try {
                const { data } = await axios.get(route('crm.presence'));

                if (! cancelled) {
                    setState(data);
                    // Интервал задаёт сервер и он может измениться между опросами,
                    // поэтому следующий заводим здесь, а не одним setInterval.
                    timer.current = window.setTimeout(poll, (data.poll_seconds ?? 45) * 1000);
                }
            } catch {
                // Сеть моргнула или сессия истекла — полоска не то место, где
                // об этом сообщают. Пробуем ещё раз позже.
                if (! cancelled) {
                    timer.current = window.setTimeout(poll, 60_000);
                }
            }
        };

        poll();

        return () => {
            cancelled = true;
            if (timer.current) window.clearTimeout(timer.current);
        };
    }, []);

    if (state.clients.length === 0) {
        return null;
    }

    const hidden = state.total - state.clients.length;

    return (
        <Box
            px={{ base: 3, md: 6 }}
            py={1}
            borderBottomWidth="1px"
            bg="bg.subtle"
            overflowX="auto"
        >
            <HStack gap={2} minH="20px" whiteSpace="nowrap">
                <Box
                    w="6px"
                    h="6px"
                    borderRadius="full"
                    bg="green.solid"
                    flexShrink={0}
                    aria-hidden
                />
                <Text fontSize="11px" color="fg.muted" flexShrink={0}>Сейчас на сайте:</Text>

                {state.clients.map((client) => (
                    <Tooltip
                        key={client.id}
                        content={`${client.name} — открыть карточку`}
                        openDelay={400}
                    >
                        <Link href={route('crm.clients.show', client.id)}>
                            <Text fontSize="11px" fontWeight="medium" _hover={{ textDecoration: 'underline' }}>
                                {client.name}
                            </Text>
                        </Link>
                    </Tooltip>
                ))}

                {hidden > 0 && (
                    <Text fontSize="11px" color="fg.muted" flexShrink={0}>+{hidden}</Text>
                )}
            </HStack>
        </Box>
    );
}
