import { useState } from 'react';
import { Box, Flex, HStack, Text, VStack } from '@chakra-ui/react';
import { LuCopy, LuDownload, LuExternalLink, LuMonitor, LuChevronDown, LuTerminal } from 'react-icons/lu';
import { AI_AGENT_ICONS } from './aiAgentLogos';
import { AGENT_CLIENTS, MOBILE_OS, OS_LIST, detectOs } from './aiAgentClients';

/**
 * «Скачать агента и подключить Pecado» — под операционную систему посетителя.
 *
 * Показываются только клиенты, которые реально подключат сервер со статическим
 * ключом (см. aiAgentClients.js). ОС определяется по браузеру и переключается
 * вручную: клиент часто открывает кабинет с одного устройства, а агента ставит на другом.
 */

function AgentIcon({ name }) {
    const item = AI_AGENT_ICONS[name];

    if (!item) {
        return null;
    }

    if (item.path) {
        return (
            <Box as="svg" viewBox="0 0 24 24" w="22px" h="22px" flexShrink={0} aria-hidden="true" color="fg">
                <path d={item.path} fill={item.hex ?? 'currentColor'} />
            </Box>
        );
    }

    return (
        <Flex w="22px" h="22px" borderRadius="full" bg={item.hex} align="center" justify="center" flexShrink={0} aria-hidden="true">
            <Text fontSize="11px" fontWeight="800" color="white" lineHeight="1">{item.monogram}</Text>
        </Flex>
    );
}

function CodeBlock({ code, onCopy, copyTitle }) {
    return (
        <Box position="relative" bg="gray.900" _dark={{ bg: 'gray.950' }} borderRadius="lg" p="3" pr="10" overflowX="auto">
            <Text as="pre" fontSize="xs" color="green.300" fontFamily="mono" whiteSpace="pre-wrap" wordBreak="break-all">
                {code}
            </Text>
            <Box
                as="button" type="button" position="absolute" top="2" right="2"
                p="1.5" borderRadius="md" color="whiteAlpha.700" _hover={{ color: 'white', bg: 'whiteAlpha.200' }}
                onClick={() => onCopy(code, copyTitle)} aria-label="Скопировать"
            >
                <LuCopy size={14} />
            </Box>
        </Box>
    );
}

function ClientRow({ client, os, url, apiKey, onCopy }) {
    const [open, setOpen] = useState(false);
    const install = client.install[os];
    const connect = client.connect({ url, key: apiKey, os });

    return (
        <Box border="1px solid" borderColor="border.muted" borderRadius="xl" bg="bg" overflow="hidden">
            <Flex px="4" py="3" gap="3" align="center" wrap="wrap">
                <HStack gap="3" flex="1" minW="200px">
                    <AgentIcon name={client.icon} />
                    <Box>
                        <HStack gap="2">
                            <Text fontWeight="700" fontSize="sm">{client.name}</Text>
                            <Text fontSize="2xs" color="fg.muted" px="1.5" borderRadius="sm" bg="bg.muted">{client.region}</Text>
                        </HStack>
                        <Text fontSize="xs" color="fg.muted">{client.kind}</Text>
                    </Box>
                </HStack>

                <HStack gap="2" flexShrink={0}>
                    {install.url && (
                        <Box
                            as="a" href={install.url} target="_blank" rel="noopener noreferrer"
                            display="inline-flex" alignItems="center" gap="1.5"
                            px="3" py="1.5" borderRadius="md" fontSize="xs" fontWeight="600"
                            bg="#9e1b32" color="white" _hover={{ bg: '#7a1527' }}
                        >
                            {install.label ? <LuExternalLink size={13} /> : <LuDownload size={13} />}
                            {install.label ?? 'Скачать'}
                        </Box>
                    )}
                    <Box
                        as="button" type="button" onClick={() => setOpen((v) => !v)}
                        display="inline-flex" alignItems="center" gap="1"
                        px="3" py="1.5" borderRadius="md" fontSize="xs" fontWeight="600"
                        border="1px solid" borderColor="border" _hover={{ bg: 'bg.muted' }}
                        aria-expanded={open}
                    >
                        Подключить
                        <Box as="span" display="inline-flex" transform={open ? 'rotate(180deg)' : 'none'} transition="transform .2s">
                            <LuChevronDown size={13} />
                        </Box>
                    </Box>
                </HStack>
            </Flex>

            {(install.command || install.note) && (
                <Box px="4" pb="3">
                    {install.command && (
                        <>
                            <HStack gap="1.5" mb="1.5">
                                <LuTerminal size={12} />
                                <Text fontSize="2xs" fontWeight="700" color="fg.muted" textTransform="uppercase" letterSpacing="0.05em">
                                    Установка · {install.shell}
                                </Text>
                            </HStack>
                            <CodeBlock code={install.command} onCopy={onCopy} copyTitle="Команда установки скопирована" />
                        </>
                    )}
                    {install.note && <Text fontSize="2xs" color="fg.muted" mt="1">{install.note}</Text>}
                </Box>
            )}

            {open && (
                <Box px="4" pb="4" pt="1" borderTop="1px dashed" borderColor="border.muted">
                    <Text fontSize="xs" color="fg.muted" my="2">{connect.where}</Text>
                    <CodeBlock code={connect.code} onCopy={onCopy} copyTitle="Настройка подключения скопирована" />
                </Box>
            )}
        </Box>
    );
}

export default function AgentConnectGuide({ url, apiKey, onCopy }) {
    const [os, setOs] = useState(detectOs);
    const detected = detectOs();
    const mobile = MOBILE_OS.includes(os);
    const clients = AGENT_CLIENTS.filter((client) => client.install[os]);

    return (
        <Box>
            <Text fontWeight="700" fontSize="sm" mb="1">Скачайте агента и подключите Pecado</Text>
            <Text fontSize="xs" color="fg.muted" mb="3">
                Выберите систему — покажем агентов, которые подключаются к кабинету по вашему ключу,
                и готовую настройку.
            </Text>

            <Flex gap="1.5" wrap="wrap" mb="4" role="tablist" aria-label="Операционная система">
                {OS_LIST.map((item) => {
                    const active = item.key === os;
                    return (
                        <Box
                            key={item.key} as="button" type="button" role="tab" aria-selected={active}
                            onClick={() => setOs(item.key)}
                            px="3" py="1.5" borderRadius="full" fontSize="xs" fontWeight="600"
                            border="1px solid" borderColor={active ? '#9e1b32' : 'border'}
                            bg={active ? '#9e1b32' : 'bg'} color={active ? 'white' : 'fg'}
                            _hover={active ? undefined : { bg: 'bg.muted' }}
                        >
                            {item.label}
                            {item.key === detected && (
                                <Text as="span" fontWeight="400" opacity="0.75"> · ваша</Text>
                            )}
                        </Box>
                    );
                })}
            </Flex>

            {mobile ? (
                <HStack
                    align="flex-start" gap="3" p="4" borderRadius="xl"
                    bg="bg.muted" border="1px solid" borderColor="border.muted"
                >
                    <Box color="fg.muted" pt="0.5"><LuMonitor size={20} /></Box>
                    <Box>
                        <Text fontSize="sm" fontWeight="600" mb="1">Подключение делается на компьютере</Text>
                        <Text fontSize="xs" color="fg.muted" lineHeight="1.6">
                            Мобильные приложения ИИ-агентов пока не умеют подключать внешний сервер по ключу
                            доступа. Откройте эту страницу на компьютере — там будут ссылки на агентов
                            и готовые настройки.
                        </Text>
                    </Box>
                </HStack>
            ) : (
                <VStack align="stretch" gap="2.5">
                    {clients.map((client) => (
                        <ClientRow key={client.id} client={client} os={os} url={url} apiKey={apiKey} onCopy={onCopy} />
                    ))}
                </VStack>
            )}
        </Box>
    );
}
