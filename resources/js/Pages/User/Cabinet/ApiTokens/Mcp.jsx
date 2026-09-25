import { useState } from 'react';
import { Box, HStack, Text } from '@chakra-ui/react';
import { Head, Link } from '@inertiajs/react';
import axios from 'axios';
import { LuCircleCheck, LuCircleDashed, LuKeyRound, LuTriangleAlert } from 'react-icons/lu';
import CabinetLayout from '../CabinetLayout';
import AgentChatBanner from './AgentChatBanner';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';

/* ──────────────────────────────────────────────── */
/*  Страница «ИИ-агенты (MCP)»                      */
/* ──────────────────────────────────────────────── */
// MCP-сервер работает на тех же ключах, что и API v1: отдельного ключа нет.
// Без ключа образец настройки не показывается вовсе — клиенты копировали
// «<ВАШ_КЛЮЧ>» как есть и получали отказ. Вместо образца — кнопка, которая
// создаёт ключ здесь же и раскрывает настройку уже с настоящим ключом.

const formatDateTime = (iso) => new Date(iso).toLocaleString('ru-RU', {
    day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit',
});

/**
 * Состояние подключения: есть ли ключ, подключался ли агент. Клиент видит
 * результат сам, без письма менеджеру «ничего не работает».
 */
function ConnectionStatus({ apiKey, connection, creating, onCreate }) {
    if (!apiKey) {
        return (
            <HStack
                mb="4" flexWrap="wrap" gap="3"
                bg="amber.50" _dark={{ bg: 'amber.900/20' }}
                borderRadius="lg" px="3" py="2.5"
                border="1px solid" borderColor={{ base: 'amber.200', _dark: 'amber.800' }}
            >
                <LuTriangleAlert size={16} style={{ flexShrink: 0, color: 'var(--chakra-colors-amber-500)' }} />
                <Text fontSize="xs" color="amber.700" _dark={{ color: 'amber.300' }} flex="1" minW="200px">
                    {connection.has_inactive_keys
                        ? <>Все ваши ключи отключены — агент не подключится. Включите ключ в разделе{' '}
                            <Box as="span" fontWeight="700" textDecoration="underline"><Link href="/cabinet/api-tokens">API</Link></Box>
                            {' '}или создайте новый.</>
                        : 'Ключ доступа ещё не создан — без него агенту нечем представиться.'}
                </Text>
                <Button size="xs" bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }} onClick={onCreate} loading={creating} loadingText="Создаём…">
                    <LuKeyRound /> Создать ключ и подключить
                </Button>
            </HStack>
        );
    }

    const connected = Boolean(connection.last_connected_at);
    const Icon = connected ? LuCircleCheck : LuCircleDashed;

    return (
        <HStack
            mb="4" gap="2"
            bg={connected ? 'green.50' : 'bg.subtle'} _dark={{ bg: connected ? 'green.900/20' : 'bg.subtle' }}
            borderRadius="lg" px="3" py="2"
            border="1px solid" borderColor={connected ? { base: 'green.200', _dark: 'green.800' } : 'border'}
        >
            <Icon size={16} style={{ flexShrink: 0, color: connected ? 'var(--chakra-colors-green-500)' : 'var(--chakra-colors-gray-400)' }} />
            <Text fontSize="xs" color={connected ? 'green.700' : 'gray.500'} _dark={{ color: connected ? 'green.300' : 'gray.400' }}>
                {connected
                    ? <>Агент подключался {formatDateTime(connection.last_connected_at)}{connection.agent ? ` · ${connection.agent}` : ''}.</>
                    : <>Агент ещё не подключался. Раскройте «Как подключить» ниже и отправьте сообщение своему агенту.</>}
            </Text>
        </HStack>
    );
}

export default function Mcp({ apiKey: initialKey = null, docs = {}, connection = {} }) {
    const [apiKey, setApiKey] = useState(initialKey);
    const [creating, setCreating] = useState(false);
    const [setupOpen, setSetupOpen] = useState(false);

    const copyToClipboard = async (text, title) => {
        try {
            await navigator.clipboard.writeText(text);
            toaster.create({ title, type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Не удалось скопировать', type: 'error' });
        }
    };

    const createKey = async () => {
        try {
            setCreating(true);
            const res = await axios.post('/cabinet/api-tokens', { name: 'ИИ-агент' });
            setApiKey(res.data.token);
            setSetupOpen(true);
            toaster.create({ title: 'Ключ создан', description: 'Сообщение для агента готово — скопируйте его ниже.', type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Не удалось создать ключ', type: 'error' });
        } finally {
            setCreating(false);
        }
    };

    const mcpUrl = docs.mcp || `${window.location.origin}/mcp/client`;

    return (
        <CabinetLayout title="ИИ-агенты (MCP)">
            <Head title="ИИ-агенты (MCP) — Pecado" />

            <ConnectionStatus apiKey={apiKey} connection={connection} creating={creating} onCreate={createKey} />

            <AgentChatBanner
                url={mcpUrl}
                apiKey={apiKey}
                docsUrl={docs.ui}
                openapiUrl={docs.openapi}
                onCopy={copyToClipboard}
                onCreateKey={createKey}
                creatingKey={creating}
                setupOpen={setupOpen}
                onSetupOpenChange={setSetupOpen}
            />
        </CabinetLayout>
    );
}
