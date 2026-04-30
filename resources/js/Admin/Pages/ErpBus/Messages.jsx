import { router, Link } from '@inertiajs/react';
import AdminLayout from '@/Admin/Layouts/AdminLayout';
import { PageHeader } from '@/Admin/Components';
import {
    Box,
    Text,
    Badge,
    HStack,
    VStack,
    Input,
    Table,
    IconButton,
    Flex,
    Button,
} from '@chakra-ui/react';
import {
    LuRefreshCw,
    LuArrowLeft,
    LuTrash2,
    LuEye,
    LuInbox,
    LuSend,
    LuCircleCheck,
    LuCircleX,
} from 'react-icons/lu';
import { useState, useCallback } from 'react';
import { toaster } from '@/components/ui/toaster';

/**
 * Пагинатор.
 */
const Pagination = ({ data }) => {
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

export default function Messages({ messages, eventTypes, filters }) {
    const [direction, setDirection] = useState(filters.direction || '');
    const [event, setEvent] = useState(filters.event || '');
    const [status, setStatus] = useState(filters.status || '');
    const [search, setSearch] = useState(filters.search || '');
    const [dateFrom, setDateFrom] = useState(filters.date_from || '');
    const [dateTo, setDateTo] = useState(filters.date_to || '');
    const [clearing, setClearing] = useState(false);

    const applyFilters = useCallback((overrides = {}) => {
        const params = {
            direction: overrides.direction !== undefined ? overrides.direction : direction,
            event: overrides.event !== undefined ? overrides.event : event,
            status: overrides.status !== undefined ? overrides.status : status,
            search: overrides.search !== undefined ? overrides.search : search,
            date_from: overrides.date_from !== undefined ? overrides.date_from : dateFrom,
            date_to: overrides.date_to !== undefined ? overrides.date_to : dateTo,
        };

        // Убрать пустые
        Object.keys(params).forEach(key => {
            if (!params[key]) delete params[key];
        });

        router.get(route('admin.erp-bus.messages'), params, {
            preserveState: true,
            preserveScroll: true,
        });
    }, [direction, event, status, search, dateFrom, dateTo]);

    const handleSearchChange = useCallback((e) => {
        const value = e.target.value;
        setSearch(value);
        clearTimeout(window._erpMsgSearchTimeout);
        window._erpMsgSearchTimeout = setTimeout(() => {
            applyFilters({ search: value });
        }, 500);
    }, [applyFilters]);

    const handleDirectionChange = (e) => {
        const value = e.target.value;
        setDirection(value);
        applyFilters({ direction: value });
    };

    const handleEventChange = (e) => {
        const value = e.target.value;
        setEvent(value);
        applyFilters({ event: value });
    };

    const handleStatusChange = (e) => {
        const value = e.target.value;
        setStatus(value);
        applyFilters({ status: value });
    };

    const handleDateFromChange = (e) => {
        const value = e.target.value;
        setDateFrom(value);
        applyFilters({ date_from: value });
    };

    const handleDateToChange = (e) => {
        const value = e.target.value;
        setDateTo(value);
        applyFilters({ date_to: value });
    };

    const handleRefresh = useCallback(() => {
        router.reload({ preserveScroll: true });
    }, []);

    const handleClear = useCallback(() => {
        setClearing(true);
        router.delete(route('admin.erp-bus.clear-messages'), {
            preserveScroll: true,
            onSuccess: () => {
                toaster.create({ title: 'Лог сообщений очищен', type: 'success' });
                setClearing(false);
            },
            onError: () => {
                toaster.create({ title: 'Ошибка при очистке', type: 'error' });
                setClearing(false);
            },
        });
    }, []);

    return (
        <>
            <Flex justify="space-between" align="center" mb={6}>
                <HStack gap={3}>
                    <Link href={route('admin.erp-bus.index')}>
                        <IconButton size="sm" variant="ghost" aria-label="Назад">
                            <LuArrowLeft />
                        </IconButton>
                    </Link>
                    <PageHeader title="Лог сообщений ERP" />
                </HStack>
                <HStack gap={2}>
                    {messages.total > 0 && (
                        <Button
                            size="sm"
                            variant="outline"
                            colorPalette="red"
                            onClick={handleClear}
                            loading={clearing}
                        >
                            <LuTrash2 />
                            Очистить
                        </Button>
                    )}
                    <IconButton
                        size="sm"
                        variant="outline"
                        aria-label="Обновить"
                        onClick={handleRefresh}
                    >
                        <LuRefreshCw />
                    </IconButton>
                </HStack>
            </Flex>

            {/* Фильтры */}
            <Flex gap={3} mb={4} wrap="wrap">
                <Input
                    placeholder="Поиск по message_id, событию..."
                    value={search}
                    onChange={handleSearchChange}
                    size="sm"
                    maxW="250px"
                />
                <Box as="select"
                    value={direction}
                    onChange={handleDirectionChange}
                    borderWidth="1px"
                    borderRadius="md"
                    px={3}
                    py={1}
                    fontSize="sm"
                    bg="transparent"
                    minW="160px"
                >
                    <option value="">Все направления</option>
                    <option value="incoming">1С → Сайт</option>
                    <option value="outgoing">Сайт → 1С</option>
                </Box>
                <Box as="select"
                    value={event}
                    onChange={handleEventChange}
                    borderWidth="1px"
                    borderRadius="md"
                    px={3}
                    py={1}
                    fontSize="sm"
                    bg="transparent"
                    minW="180px"
                >
                    <option value="">Все события</option>
                    {eventTypes.map((type) => (
                        <option key={type} value={type}>{type}</option>
                    ))}
                </Box>
                <Box as="select"
                    value={status}
                    onChange={handleStatusChange}
                    borderWidth="1px"
                    borderRadius="md"
                    px={3}
                    py={1}
                    fontSize="sm"
                    bg="transparent"
                    minW="140px"
                >
                    <option value="">Все статусы</option>
                    <option value="success">Успешно</option>
                    <option value="failed">Ошибка</option>
                </Box>
                <Input
                    type="date"
                    value={dateFrom}
                    onChange={handleDateFromChange}
                    size="sm"
                    maxW="160px"
                    placeholder="Дата от"
                />
                <Input
                    type="date"
                    value={dateTo}
                    onChange={handleDateToChange}
                    size="sm"
                    maxW="160px"
                    placeholder="Дата до"
                />
            </Flex>

            {/* Таблица сообщений */}
            {messages.data.length === 0 ? (
                <Box textAlign="center" py={10} color="fg.muted">
                    <Text fontSize="lg" fontWeight="medium">Нет записей</Text>
                    <Text fontSize="sm" mt={2}>
                        Включите логирование через ERP_BUS_LOGGING_ENABLED=true в .env
                    </Text>
                </Box>
            ) : (
                <>
                    <Box mb={2}>
                        <Text fontSize="sm" color="fg.muted">
                            Всего записей: {messages.total}
                        </Text>
                    </Box>
                    <Box overflowX="auto">
                        <Table.Root size="sm" striped>
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader w="40px">#</Table.ColumnHeader>
                                    <Table.ColumnHeader>Направление</Table.ColumnHeader>
                                    <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                    <Table.ColumnHeader>Очередь</Table.ColumnHeader>
                                    <Table.ColumnHeader>Message ID</Table.ColumnHeader>
                                    <Table.ColumnHeader>Статус</Table.ColumnHeader>
                                    <Table.ColumnHeader>Дата</Table.ColumnHeader>
                                    <Table.ColumnHeader w="60px"></Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {messages.data.map((msg) => (
                                    <Table.Row key={msg.id}>
                                        <Table.Cell>
                                            <Text fontFamily="mono" fontSize="xs" color="fg.muted">
                                                {msg.id}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge
                                                colorPalette={msg.direction === 'incoming' ? 'yellow' : 'blue'}
                                                size="sm"
                                                variant="subtle"
                                            >
                                                <HStack gap={1}>
                                                    {msg.direction === 'incoming' ? <LuInbox size={12} /> : <LuSend size={12} />}
                                                    <Text>{msg.direction === 'incoming' ? '1С → Сайт' : 'Сайт → 1С'}</Text>
                                                </HStack>
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge colorPalette="purple" size="sm" variant="subtle">
                                                {msg.event}
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontFamily="mono" fontSize="xs" color="fg.muted">
                                                {msg.routing_key || '—'}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontFamily="mono" fontSize="xs" truncate maxW="180px" title={msg.message_id}>
                                                {msg.message_id || '—'}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge
                                                colorPalette={msg.status === 'success' ? 'green' : 'red'}
                                                size="sm"
                                                variant="subtle"
                                            >
                                                <HStack gap={1}>
                                                    {msg.status === 'success'
                                                        ? <LuCircleCheck size={12} />
                                                        : <LuCircleX size={12} />
                                                    }
                                                    <Text>{msg.status === 'success' ? 'Успешно' : 'Ошибка'}</Text>
                                                </HStack>
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text fontSize="sm" color="fg.muted">
                                                {msg.created_at
                                                    ? new Date(msg.created_at).toLocaleString('ru-RU')
                                                    : '—'}
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Link href={route('admin.erp-bus.messages.show', msg.id)}>
                                                <IconButton
                                                    size="xs"
                                                    variant="ghost"
                                                    colorPalette="purple"
                                                    aria-label="Просмотр"
                                                >
                                                    <LuEye />
                                                </IconButton>
                                            </Link>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                    <Pagination data={messages} />
                </>
            )}
        </>
    );
}

Messages.layout = (page) => <AdminLayout>{page}</AdminLayout>;
