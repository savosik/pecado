import { Box, HStack, Text } from '@chakra-ui/react';
import { Head, Link } from '@inertiajs/react';
import CabinetLayout from '../CabinetLayout';
import AgentChatBanner from './AgentChatBanner';
import { LuTriangleAlert } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';

/* ──────────────────────────────────────────────── */
/*  Страница «ИИ-агенты (MCP)»                      */
/* ──────────────────────────────────────────────── */
// MCP-сервер работает на тех же ключах, что и API v1: отдельного ключа нет,
// поэтому без ключа баннер показывает заглушку и ведёт в раздел «API».
export default function Mcp({ apiKey = null, docs = {} }) {
    const copyToClipboard = async (text, title) => {
        try {
            await navigator.clipboard.writeText(text);
            toaster.create({ title, type: 'success' });
        } catch (err) {
            toaster.create({ title: 'Не удалось скопировать', type: 'error' });
        }
    };

    const mcpUrl = docs.mcp || `${window.location.origin}/mcp/client`;

    return (
        <CabinetLayout title="ИИ-агенты (MCP)">
            <Head title="ИИ-агенты (MCP) — Pecado" />

            {!apiKey && (
                <HStack
                    mb="4"
                    bg="amber.50" _dark={{ bg: 'amber.900/20' }}
                    borderRadius="lg" px="3" py="2"
                    border="1px solid" borderColor={{ base: 'amber.200', _dark: 'amber.800' }}
                >
                    <LuTriangleAlert size={16} style={{ flexShrink: 0, color: 'var(--chakra-colors-amber-500)' }} />
                    <Text fontSize="xs" color="amber.700" _dark={{ color: 'amber.300' }}>
                        Чтобы подключить агента, нужен API-ключ — создайте его в разделе{' '}
                        <Box as="span" fontWeight="700" textDecoration="underline">
                            <Link href="/cabinet/api-tokens">API</Link>
                        </Box>.
                    </Text>
                </HStack>
            )}

            <AgentChatBanner
                url={mcpUrl}
                apiKey={apiKey ?? '<ВАШ_КЛЮЧ>'}
                docsUrl={docs.ui}
                openapiUrl={docs.openapi}
                onCopy={copyToClipboard}
            />
        </CabinetLayout>
    );
}
