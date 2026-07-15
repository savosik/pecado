import { router, Link, Deferred } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader, DeleteAllButton } from '@/Admin/Components';
import {
    Box,
    Text,
    Badge,
    HStack,
    VStack,
    SimpleGrid,
    Input,
    Separator,
    Table,
    IconButton,
    Flex,
    Card,
    Button,
} from '@chakra-ui/react';
import {
    LuRefreshCw,
    LuCircleCheck,
    LuCircleAlert,
    LuCircle,
    LuTriangleAlert,
    LuInbox,
    LuSend,
    LuSkull,
    LuArrowDownToLine,
    LuTrash2,
    LuShieldAlert,
    LuFileText,
} from 'react-icons/lu';
import { useState, useCallback } from 'react';
import { toaster } from '@/components/ui/toaster';

/**
 * Карточка статуса одной очереди.
 */
const QueueCard = ({ queue }) => {
    let colorPalette = 'green';
    let icon = LuCircleCheck;
    let statusText = 'Пустая';

    if (!queue.exists) {
        colorPalette = 'gray';
        icon = LuCircleAlert;
        statusText = 'Не найдена';
    } else if (queue.name.startsWith('erp_dlq.') && queue.total > 0) {
        colorPalette = 'red';
        icon = LuSkull;
        statusText = `${queue.total} мёртвых`;
    } else if (queue.total > 0) {
        colorPalette = 'yellow';
        icon = LuCircle;
        statusText = `${queue.ready} ожид. / ${queue.unacked} обраб.`;
    }

    const Icon = icon;

    return (
        <Card.Root
            size="sm"
            borderWidth="1px"
            borderColor={`${colorPalette}.200`}
            _dark={{ borderColor: `${colorPalette}.800` }}
        >
            <Card.Body p={3}>
                <HStack justify="space-between" mb={1}>
                    <Text fontSize="xs" fontFamily="mono" fontWeight="medium" truncate>
                        {queue.name}
                    </Text>
                    <Icon
                        size={16}
                        color={`var(--chakra-colors-${colorPalette}-500)`}
                    />
                </HStack>
                <Badge colorPalette={colorPalette} size="sm" variant="subtle">
                    {statusText}
                </Badge>
                {queue.exists && (
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Воркеров: {queue.consumers}
                    </Text>
                )}
            </Card.Body>
        </Card.Root>
    );
};

/**
 * Секция с группой очередей.
 */
const QueueSection = ({ title, icon: SectionIcon, queues, colorPalette }) => {
    const totalMessages = queues.reduce((sum, q) => sum + q.total, 0);

    return (
        <Box>
            <HStack mb={3} gap={2}>
                <SectionIcon size={18} />
                <Text fontWeight="bold" fontSize="md">{title}</Text>
                {totalMessages > 0 && (
                    <Badge colorPalette={colorPalette} variant="solid" size="sm">
                        {totalMessages}
                    </Badge>
                )}
            </HStack>
            <SimpleGrid columns={{ base: 1, sm: 2, md: 3, lg: 4 }} gap={3}>
                {queues.map((q) => (
                    <QueueCard key={q.name} queue={q} />
                ))}
            </SimpleGrid>
        </Box>
    );
};

/**
 * Заглушка на время отложенной загрузки статуса очередей.
 */
const QueuesLoading = () => (
    <VStack gap={6} align="stretch" mb={8}>
        {['Входящие (1С → Сайт)', 'DLQ (Мёртвые)', 'Исходящие (Сайт → 1С)', 'Внешние (через shovel)'].map((title) => (
            <Box key={title}>
                <HStack gap={2} mb={3} color="fg.muted">
                    <LuRefreshCw size={16} />
                    <Text fontSize="sm" fontWeight="medium">{title}</Text>
                    <Text fontSize="xs">— загрузка статуса…</Text>
                </HStack>
                <SimpleGrid columns={{ base: 1, md: 2, lg: 3 }} gap={3}>
                    {[0, 1, 2].map((i) => (
                        <Box key={i} h="72px" borderRadius="md" borderWidth="1px" borderColor="border" bg="bg.muted/40" />
                    ))}
                </SimpleGrid>
            </Box>
        ))}
    </VStack>
);

/**
 * Пагинатор.
 */
const Pagination = ({ data, paramName = 'page' }) => {
    if (data.last_page <= 1) return null;

    return (
        <HStack justify="center" gap={2} mt={4}>
            {data.links.map((link, i) => {
                if (!link.url) {
                    return (
                        <Text key={i} fontSize="sm" color="fg.muted" px={2}>
                            {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                        </Text>
                    );
                }

                return (
                    <Box
                        key={i}
                        as="button"
                        px={3}
                        py={1}
                        fontSize="sm"
                        borderRadius="md"
                        bg={link.active ? 'blue.500' : 'transparent'}
                        color={link.active ? 'white' : 'fg'}
                        _hover={{ bg: link.active ? 'blue.600' : 'bg.muted' }}
                        onClick={() => router.visit(link.url, { preserveScroll: true })}
                    >
                        {link.label.replace('&laquo;', '«').replace('&raquo;', '»')}
                    </Box>
                );
            })}
        </HStack>
    );
};

export default function Index({ queues, processed, failedJobs, eventStats, eventTypes, filters, validationErrors, validationErrorsCount, processingErrors, processingErrorsCount, recoveredCount, busMessagesCount, busLoggingEnabled }) {
    const [search, setSearch] = useState(filters.search || '');
    const [deleteAllDialogOpen, setDeleteAllDialogOpen] = useState(false);
    const [deleteAllProcessing, setDeleteAllProcessing] = useState(false);
    const openDeleteAllDialog = () => setDeleteAllDialogOpen(true);
    const closeDeleteAllDialog = () => setDeleteAllDialogOpen(false);
    const confirmDeleteAll = () => {
        setDeleteAllProcessing(true);
        router.delete(route('admin.bulk-delete-all', 'erp-processed-messages'), {
            onSuccess: () => {
                toaster.create({ title: 'Все записи успешно удалены', type: 'success' });
                setDeleteAllDialogOpen(false);
                setDeleteAllProcessing(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при массовом удалении', type: 'error' });
                setDeleteAllProcessing(false);
            },
        });
    };
    const [eventFilter, setEventFilter] = useState(filters.event || '');
    const [clearingValidation, setClearingValidation] = useState(false);
    const [clearingBusMessages, setClearingBusMessages] = useState(false);
    const [clearingProcessed, setClearingProcessed] = useState(false);

    const handleClearBusMessages = useCallback(() => {
        if (!window.confirm('Очистить весь лог сообщений шины? Это действие необратимо.')) return;
        setClearingBusMessages(true);
        router.delete(route('admin.erp-bus.clear-messages'), {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({ title: 'Лог сообщений очищен', type: 'success' });
                setClearingBusMessages(false);
                router.reload({ preserveScroll: true });
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при очистке лога', type: 'error' });
                setClearingBusMessages(false);
            },
        });
    }, []);

    const handleClearProcessed = useCallback(() => {
        if (!window.confirm('Очистить журнал обработанных сообщений и статистику? Это действие необратимо.')) return;
        setClearingProcessed(true);
        router.delete(route('admin.erp-bus.clear-processed'), {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({ title: 'Журнал обработанных сообщений очищен', type: 'success' });
                setClearingProcessed(false);
                router.reload({ preserveScroll: true });
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при очистке журнала', type: 'error' });
                setClearingProcessed(false);
            },
        });
    }, []);

    const handleRefresh = useCallback(() => {
        router.reload({ preserveScroll: true });
    }, []);

    const handleSearch = useCallback((e) => {
        const value = e.target.value;
        setSearch(value);

        // Debounced navigation
        clearTimeout(window._erpBusSearchTimeout);
        window._erpBusSearchTimeout = setTimeout(() => {
            router.get(
                route('admin.erp-bus.index'),
                { search: value, event: eventFilter },
                { preserveState: true, preserveScroll: true }
            );
        }, 500);
    }, [eventFilter]);

    const handleEventFilter = useCallback((e) => {
        const value = e.target.value;
        setEventFilter(value);
        router.get(
            route('admin.erp-bus.index'),
            { search, event: value },
            { preserveState: true, preserveScroll: true }
        );
    }, [search]);

    const handleClearValidationErrors = useCallback(() => {
        setClearingValidation(true);
        router.delete(route('admin.erp-bus.clear-validation-errors'), {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({ title: 'Лог ошибок валидации очищен', type: 'success' });
                setClearingValidation(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при очистке лога', type: 'error' });
                setClearingValidation(false);
            },
        });
    }, []);

    const hasError = queues?.error;

    return (
        <>
            <Flex justify="space-between" align="center" mb={6}>
                <PageHeader title="Шина ERP"
                    actions={
                        <DeleteAllButton
                            sectionLabel="записи ERP"
                                dialogOpen={deleteAllDialogOpen}
                                onOpen={openDeleteAllDialog}
                                onClose={closeDeleteAllDialog}
                                onConfirm={confirmDeleteAll}
                                isLoading={deleteAllProcessing}
                        />
                    }
                />
                <IconButton
                    size="sm"
                    variant="outline"
                    aria-label="Обновить"
                    onClick={handleRefresh}
                >
                    <LuRefreshCw />
                </IconButton>
            </Flex>

            {/* Статус очередей — отложенная загрузка (Inertia::defer):
                страница открывается сразу, статус из RabbitMQ подтягивается отдельным
                запросом, поэтому обращение к Management API не блокирует рендер. */}
            <Deferred data="queues" fallback={<QueuesLoading />}>
                <>
                    {/* Ошибка подключения */}
                    {hasError && (
                        <Box
                            bg="red.50"
                            _dark={{ bg: 'red.900/30' }}
                            borderWidth="1px"
                            borderColor="red.200"
                            _darkBorder={{ borderColor: 'red.700' }}
                            borderRadius="md"
                            p={4}
                            mb={6}
                        >
                            <HStack gap={2}>
                                <LuTriangleAlert size={20} color="var(--chakra-colors-red-500)" />
                                <Text color="red.600" _dark={{ color: 'red.300' }} fontWeight="medium">
                                    {queues?.error}
                                </Text>
                            </HStack>
                        </Box>
                    )}

                    {/* Статус очередей */}
                    {!hasError && queues && (
                        <VStack gap={6} align="stretch" mb={8}>
                            <QueueSection
                                title="Входящие (1С → Сайт)"
                                icon={LuInbox}
                                queues={queues.incoming || []}
                                colorPalette="yellow"
                            />
                            <QueueSection
                                title="DLQ (Мёртвые)"
                                icon={LuSkull}
                                queues={queues.dlq || []}
                                colorPalette="red"
                            />
                            <QueueSection
                                title="Исходящие (Сайт → 1С)"
                                icon={LuSend}
                                queues={queues.outgoing || []}
                                colorPalette="blue"
                            />
                            <QueueSection
                                title="Внешние (через shovel)"
                                icon={LuArrowDownToLine}
                                queues={queues.external || []}
                                colorPalette="cyan"
                            />
                        </VStack>
                    )}
                </>
            </Deferred>

            {/* Лог сообщений */}
            <Card.Root mb={6} borderWidth="1px" borderColor="purple.200" _dark={{ borderColor: 'purple.800' }}>
                <Card.Body p={4}>
                    <Flex justify="space-between" align="center">
                        <HStack gap={3}>
                            <LuFileText size={22} color="var(--chakra-colors-purple-500)" />
                            <Box>
                                <HStack gap={2}>
                                    <Text fontWeight="bold" fontSize="md">
                                        Лог сообщений
                                    </Text>
                                    {busMessagesCount > 0 && (
                                        <Badge colorPalette="purple" variant="solid" size="sm">
                                            {busMessagesCount}
                                        </Badge>
                                    )}
                                </HStack>
                                <Text fontSize="sm" color="fg.muted">
                                    {busLoggingEnabled
                                        ? 'Логирование включено — все сообщения записываются'
                                        : 'Логирование выключено (ERP_BUS_LOGGING_ENABLED=false)'}
                                </Text>
                            </Box>
                        </HStack>
                        <HStack gap={2}>
                            {busMessagesCount > 0 && (
                                <Button
                                    size="sm"
                                    colorPalette="red"
                                    variant="outline"
                                    loading={clearingBusMessages}
                                    onClick={handleClearBusMessages}
                                >
                                    <LuTrash2 />
                                    Очистить журнал
                                </Button>
                            )}
                            <Link href={route('admin.erp-bus.messages')}>
                                <Button size="sm" colorPalette="purple" variant="outline">
                                    <LuFileText />
                                    Открыть лог
                                </Button>
                            </Link>
                        </HStack>
                    </Flex>
                </Card.Body>
            </Card.Root>

            <Separator mb={6} />

            {/* Ошибки валидации JSON Schema */}
            <Box mb={8}>
                <HStack justify="space-between" mb={3}>
                    <HStack gap={2}>
                        <LuShieldAlert size={18} color="var(--chakra-colors-orange-500)" />
                        <Text fontWeight="bold" fontSize="md">
                            Ошибки валидации JSON Schema
                        </Text>
                        {validationErrorsCount > 0 && (
                            <Badge colorPalette="orange" variant="solid" size="sm">
                                {validationErrorsCount}
                            </Badge>
                        )}
                    </HStack>
                    {validationErrorsCount > 0 && (
                        <Button
                            size="xs"
                            variant="outline"
                            colorPalette="red"
                            loading={clearingValidation}
                            onClick={handleClearValidationErrors}
                        >
                            <LuTrash2 />
                            Очистить лог
                        </Button>
                    )}
                </HStack>

                {(!validationErrors || validationErrors.data.length === 0) ? (
                    <Box textAlign="center" py={6} color="fg.muted">
                        <Text color="green.500" fontWeight="medium">Ошибок валидации нет! ✅</Text>
                    </Box>
                ) : (
                    <>
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Направление</Table.ColumnHeader>
                                        <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                        <Table.ColumnHeader>Message ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Ошибки</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {validationErrors.data.map((err) => (
                                        <Table.Row key={err.id}>
                                            <Table.Cell>
                                                <Badge
                                                    colorPalette={err.direction === 'incoming' ? 'yellow' : 'blue'}
                                                    size="sm"
                                                    variant="subtle"
                                                >
                                                    {err.direction === 'incoming' ? '1С → Сайт' : 'Сайт → 1С'}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette="orange" size="sm" variant="subtle">
                                                    {err.event}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs" truncate maxW="200px">
                                                    {err.message_id || '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell maxW="400px">
                                                <VStack align="start" gap={1}>
                                                    {(err.errors || []).map((e, i) => (
                                                        <Text key={i} fontSize="xs" color="orange.600" _dark={{ color: 'orange.300' }}>
                                                            {e}
                                                        </Text>
                                                    ))}
                                                </VStack>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {err.created_at
                                                        ? new Date(err.created_at).toLocaleString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                        <Pagination data={validationErrors} paramName="validation_page" />
                    </>
                )}
            </Box>

            <Separator mb={6} />

            {/* Ошибки обработки (v15.4): payload валиден, но обработать сообщение не удалось */}
            <Box mb={8}>
                <HStack justify="space-between" mb={3}>
                    <HStack gap={2}>
                        <LuCircleAlert size={18} color="var(--chakra-colors-red-500)" />
                        <Text fontWeight="bold" fontSize="md">
                            Ошибки обработки
                        </Text>
                        {processingErrorsCount > 0 && (
                            <Badge colorPalette="red" variant="solid" size="sm">
                                {processingErrorsCount}
                            </Badge>
                        )}
                    </HStack>
                    {recoveredCount > 0 && (
                        <Link href={route('admin.erp-bus.messages', { status: 'recovered' })}>
                            <Badge colorPalette="purple" variant="subtle" size="sm" cursor="pointer">
                                Восстановлено сущностей: {recoveredCount}
                            </Badge>
                        </Link>
                    )}
                </HStack>

                <Text fontSize="xs" color="fg.muted" mb={3}>
                    Сообщение прошло проверку по схеме, но обработать его не удалось, и повтор не поможет.
                    Требует разбирательства на стороне 1С.
                </Text>

                {(!processingErrors || processingErrors.data.length === 0) ? (
                    <Box textAlign="center" py={6} color="fg.muted">
                        <Text color="green.500" fontWeight="medium">Ошибок обработки нет! ✅</Text>
                    </Box>
                ) : (
                    <>
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Направление</Table.ColumnHeader>
                                        <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                        <Table.ColumnHeader>Message ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Причина</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {processingErrors.data.map((err) => (
                                        <Table.Row key={err.id}>
                                            <Table.Cell>
                                                <Badge
                                                    colorPalette={err.direction === 'incoming' ? 'yellow' : 'blue'}
                                                    size="sm"
                                                    variant="subtle"
                                                >
                                                    {err.direction === 'incoming' ? '1С → Сайт' : 'Сайт → 1С'}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette="red" size="sm" variant="subtle">
                                                    {err.event}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs" truncate maxW="200px">
                                                    {err.message_id || '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell maxW="480px">
                                                <Text fontSize="xs" color="red.600" _dark={{ color: 'red.300' }}>
                                                    {err.error_message || '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted" whiteSpace="nowrap">
                                                    {err.created_at
                                                        ? new Date(err.created_at).toLocaleString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                        <Pagination data={processingErrors} paramName="processing_page" />
                    </>
                )}
            </Box>

            <Separator mb={6} />

            {/* Статистика по типам событий */}
            {eventStats && eventStats.length > 0 && (
                <Box mb={8}>
                    <HStack justify="space-between" mb={3}>
                        <Text fontWeight="bold" fontSize="md">
                            📊 Статистика по типам событий
                        </Text>
                        <Button
                            size="xs"
                            variant="outline"
                            colorPalette="red"
                            loading={clearingProcessed}
                            onClick={handleClearProcessed}
                        >
                            <LuTrash2 />
                            Очистить статистику и журнал
                        </Button>
                    </HStack>
                    <SimpleGrid columns={{ base: 2, sm: 3, md: 4, lg: 6 }} gap={3}>
                        {eventStats.map((stat) => (
                            <Card.Root key={stat.event} size="sm">
                                <Card.Body p={3} textAlign="center">
                                    <Text fontSize="xs" fontFamily="mono" color="fg.muted" truncate>
                                        {stat.event}
                                    </Text>
                                    <Text fontSize="xl" fontWeight="bold" color="blue.500">
                                        {stat.count}
                                    </Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        {stat.last_at
                                            ? new Date(stat.last_at).toLocaleString('ru-RU')
                                            : '—'}
                                    </Text>
                                </Card.Body>
                            </Card.Root>
                        ))}
                    </SimpleGrid>
                </Box>
            )}

            <Separator mb={6} />

            {/* Журнал обработанных сообщений */}
            <Box mb={8}>
                <HStack justify="space-between" mb={3}>
                    <Text fontWeight="bold" fontSize="md">
                        ✅ Журнал обработанных сообщений
                    </Text>
                    {processed.data.length > 0 && (
                        <Button
                            size="xs"
                            variant="outline"
                            colorPalette="red"
                            loading={clearingProcessed}
                            onClick={handleClearProcessed}
                        >
                            <LuTrash2 />
                            Очистить журнал
                        </Button>
                    )}
                </HStack>

                <HStack gap={3} mb={4}>
                    <Input
                        placeholder="Поиск по message_id..."
                        value={search}
                        onChange={handleSearch}
                        size="sm"
                        maxW="300px"
                    />
                    <Box as="select"
                        value={eventFilter}
                        onChange={handleEventFilter}
                        borderWidth="1px"
                        borderRadius="md"
                        px={3}
                        py={1}
                        fontSize="sm"
                        bg="transparent"
                        minW="200px"
                    >
                        <option value="">Все события</option>
                        {eventTypes.map((type) => (
                            <option key={type} value={type}>{type}</option>
                        ))}
                    </Box>
                </HStack>

                {processed.data.length === 0 ? (
                    <Box textAlign="center" py={8} color="fg.muted">
                        <Text>Нет обработанных сообщений</Text>
                        <Text fontSize="sm" mt={1}>
                            Если очереди пусты и здесь пусто — 1С вероятно не посылала сообщений
                        </Text>
                    </Box>
                ) : (
                    <>
                        <Box overflowX="auto">
                            <Table.Root size="sm" striped>
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Message ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                        <Table.ColumnHeader>Обработано</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {processed.data.map((msg) => (
                                        <Table.Row key={msg.message_id}>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs">
                                                    {msg.message_id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette="blue" size="sm" variant="subtle">
                                                    {msg.event}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {msg.processed_at
                                                        ? new Date(msg.processed_at).toLocaleString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                        <Pagination data={processed} />
                    </>
                )}
            </Box>

            <Separator mb={6} />

            {/* Ошибки (failed_jobs) */}
            <Box mb={8}>
                <Text fontWeight="bold" fontSize="md" mb={3}>
                    ❌ Ошибки обработки (failed_jobs)
                </Text>

                {failedJobs.data.length === 0 ? (
                    <Box textAlign="center" py={6} color="fg.muted">
                        <Text color="green.500" fontWeight="medium">Ошибок нет! 🎉</Text>
                    </Box>
                ) : (
                    <>
                        <Box overflowX="auto">
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>ID</Table.ColumnHeader>
                                        <Table.ColumnHeader>Очередь</Table.ColumnHeader>
                                        <Table.ColumnHeader>Ошибка</Table.ColumnHeader>
                                        <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {failedJobs.data.map((job) => (
                                        <Table.Row key={job.id}>
                                            <Table.Cell>
                                                <Text fontFamily="mono" fontSize="xs">
                                                    {job.id}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge colorPalette="red" size="sm" variant="subtle">
                                                    {job.queue}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell maxW="400px">
                                                <Text fontSize="xs" color="red.500" truncate title={job.exception}>
                                                    {job.exception
                                                        ? job.exception.substring(0, 200) + '...'
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text fontSize="sm" color="fg.muted">
                                                    {job.failed_at
                                                        ? new Date(job.failed_at).toLocaleString('ru-RU')
                                                        : '—'}
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                        <Pagination data={failedJobs} paramName="failed_page" />
                    </>
                )}
            </Box>
        </>
    );
}

Index.layout = (page) => <AdminLayout>{page}</AdminLayout>;
