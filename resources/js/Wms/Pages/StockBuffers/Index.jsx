import { useRef, useState } from 'react';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Card,
    HStack,
    Image,
    Input,
    SimpleGrid,
    Table,
    Text,
    VStack,
} from '@chakra-ui/react';
import { LuImageOff, LuShieldCheck, LuX } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { Button } from '@/components/ui/button';
import { usePermission } from '@/Admin/hooks/usePermission';
import { useFlashToast } from '@/hooks/useFlashToast';
import axios from 'axios';

const formatAmount = (value) => `${new Intl.NumberFormat('ru-RU').format(Math.round(value))} ₽`;

function StatCard({ label, value, hint }) {
    return (
        <Card.Root>
            <Card.Body>
                <Text fontSize="xs" color="fg.muted">{label}</Text>
                <Text fontSize="2xl" fontWeight="bold">{value}</Text>
                {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
            </Card.Body>
        </Card.Root>
    );
}

function ProductPhoto({ photo }) {
    if (!photo) {
        return (
            <Box
                w="40px"
                h="40px"
                borderRadius="md"
                borderWidth="1px"
                borderColor="border"
                display="flex"
                alignItems="center"
                justifyContent="center"
                color="fg.muted"
                flexShrink={0}
            >
                <LuImageOff size={14} />
            </Box>
        );
    }

    return <Image src={photo} alt="" w="40px" h="40px" objectFit="cover" borderRadius="md" flexShrink={0} />;
}

/**
 * Поиск товара для ручной пометки «придержи N шт».
 */
function ManualAddForm() {
    const [query, setQuery] = useState('');
    const [options, setOptions] = useState([]);
    const [selected, setSelected] = useState(null);
    const [qty, setQty] = useState('1');
    const timer = useRef(null);

    const search = (value) => {
        setQuery(value);
        setSelected(null);
        clearTimeout(timer.current);

        if (value.trim().length < 2) {
            setOptions([]);
            return;
        }

        timer.current = setTimeout(async () => {
            try {
                const { data } = await axios.get('/wms/stock-buffers/search-products', {
                    params: { query: value },
                });
                setOptions(data);
            } catch {
                setOptions([]);
            }
        }, 250);
    };

    const submit = () => {
        if (!selected) {
            return;
        }

        router.post(
            '/wms/stock-buffers/manual',
            { product_id: selected.id, manual_qty: Number(qty) },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setQuery('');
                    setOptions([]);
                    setSelected(null);
                    setQty('1');
                },
            },
        );
    };

    return (
        <Card.Root>
            <Card.Body>
                <Text fontSize="sm" fontWeight="600" mb={2}>
                    Ручная пометка: придержать товар
                </Text>
                <Text fontSize="xs" color="fg.muted" mb={3}>
                    Например, на полке коробка с помятой партией — придержи 1–2 шт,
                    чтобы заказы интернетчиков не претендовали на них.
                </Text>
                <HStack gap={2} align="start" flexWrap="wrap">
                    <Box position="relative" minW="320px" flex="1">
                        <Input
                            size="sm"
                            placeholder="Название, артикул или код товара…"
                            value={selected ? `${selected.name}` : query}
                            onChange={(e) => search(e.target.value)}
                        />
                        {options.length > 0 && !selected && (
                            <Box
                                position="absolute"
                                zIndex={10}
                                bg="bg.panel"
                                borderWidth="1px"
                                borderRadius="md"
                                mt={1}
                                w="full"
                                maxH="260px"
                                overflowY="auto"
                                boxShadow="md"
                            >
                                {options.map((option) => (
                                    <HStack
                                        key={option.id}
                                        px={2}
                                        py={1.5}
                                        gap={2}
                                        cursor="pointer"
                                        _hover={{ bg: 'bg.subtle' }}
                                        onClick={() => setSelected(option)}
                                    >
                                        <ProductPhoto photo={option.photo} />
                                        <VStack align="start" gap={0} flex="1" minW={0}>
                                            <Text fontSize="sm" lineClamp={1}>{option.name}</Text>
                                            <Text fontSize="xs" color="fg.muted">
                                                {option.sku} · остаток {option.stock} шт
                                                {option.manual_qty !== null && ` · пометка ${option.manual_qty} шт`}
                                            </Text>
                                        </VStack>
                                    </HStack>
                                ))}
                            </Box>
                        )}
                    </Box>
                    <Input
                        size="sm"
                        type="number"
                        min={0}
                        max={1000}
                        w="90px"
                        value={qty}
                        onChange={(e) => setQty(e.target.value)}
                        aria-label="Придержать, шт"
                    />
                    <Button size="sm" onClick={submit} disabled={!selected}>
                        <LuShieldCheck /> Придержать
                    </Button>
                </HStack>
            </Card.Body>
        </Card.Root>
    );
}

export default function Index() {
    const { rows, summary, cancellations } = usePage().props;
    const { can } = usePermission();
    useFlashToast();

    const canEdit = can('wms-stock-buffers.edit');
    const [clearFor, setClearFor] = useState(null);

    return (
        <>
            <Head title="Страховой запас — Склад" />
            <PageHeader
                title="Страховой запас"
                description="Рисковые товары, по которым клиентам сегмента показывается заниженный остаток — их заказы не претендуют на последние экземпляры на полке"
            />

            <VStack gap={4} align="stretch">
                {!summary.enabled && (
                    <Card.Root borderColor="orange.solid" borderWidth="1px">
                        <Card.Body>
                            <Text fontSize="sm">
                                Занижение показа сейчас <b>выключено</b> (флаг STOCK_BUFFER_ENABLED).
                                Буфер считается и копится, но клиенты видят полные остатки.
                            </Text>
                        </Card.Body>
                    </Card.Root>
                )}

                <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                    <StatCard label="Скрыто от сегмента" value={`${summary.hidden_units} шт`} />
                    <StatCard label="На сумму по базовым ценам" value={formatAmount(summary.hidden_amount)} />
                    <StatCard
                        label="Доля от остатка склада"
                        value={summary.stock_share_pct === null ? '—' : `${summary.stock_share_pct} %`}
                    />
                </SimpleGrid>

                {canEdit && <ManualAddForm />}

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" fontWeight="600" mb={2}>Рисковые SKU</Text>
                        {rows.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">
                                Пусто: ночной пересчёт не нашёл рисковых товаров, ручных пометок нет.
                            </Text>
                        ) : (
                            <Table.ScrollArea>
                                <Table.Root size="sm">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Остаток</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Буфер</Table.ColumnHeader>
                                            <Table.ColumnHeader textAlign="right">Скрыто</Table.ColumnHeader>
                                            <Table.ColumnHeader>Почему в списке</Table.ColumnHeader>
                                            <Table.ColumnHeader>Пометка</Table.ColumnHeader>
                                            {canEdit && <Table.ColumnHeader />}
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {rows.map((row) => (
                                            <Table.Row key={row.id}>
                                                <Table.Cell>
                                                    <HStack gap={2}>
                                                        <ProductPhoto photo={row.photo} />
                                                        <VStack align="start" gap={0} minW={0}>
                                                            <Text fontSize="sm" lineClamp={1}>{row.name}</Text>
                                                            <Text fontSize="xs" color="fg.muted">{row.sku}</Text>
                                                        </VStack>
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell textAlign="right">{row.stock}</Table.Cell>
                                                <Table.Cell textAlign="right">
                                                    {row.manual_qty !== null
                                                        ? <Badge colorPalette="purple" variant="subtle">{row.manual_qty} вручную</Badge>
                                                        : row.effective_qty}
                                                </Table.Cell>
                                                <Table.Cell textAlign="right" fontWeight="600">{row.hidden}</Table.Cell>
                                                <Table.Cell>
                                                    <HStack gap={1} flexWrap="wrap">
                                                        {row.reasons.map((reason) => (
                                                            <Badge key={reason} colorPalette="gray" variant="subtle">{reason}</Badge>
                                                        ))}
                                                    </HStack>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {row.manual_author && (
                                                        <Text fontSize="xs" color="fg.muted">
                                                            {row.manual_author}, {row.manual_set_at}
                                                        </Text>
                                                    )}
                                                </Table.Cell>
                                                {canEdit && (
                                                    <Table.Cell textAlign="right">
                                                        {row.manual_qty !== null && (
                                                            <Button
                                                                size="xs"
                                                                variant="ghost"
                                                                colorPalette="red"
                                                                onClick={() => setClearFor(row)}
                                                            >
                                                                <LuX /> Снять пометку
                                                            </Button>
                                                        )}
                                                    </Table.Cell>
                                                )}
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Table.ScrollArea>
                        )}
                    </Card.Body>
                </Card.Root>

                <Card.Root>
                    <Card.Body>
                        <Text fontSize="sm" fontWeight="600" mb={1}>
                            Отмены в заказах клиентов сегмента
                        </Text>
                        <Text fontSize="xs" color="fg.muted" mb={3}>
                            Главная метрика эпика: после включения буфера доля заказов
                            с отменёнными строками должна пойти вниз.
                        </Text>
                        {cancellations.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">Заказов у клиентов сегмента пока нет.</Text>
                        ) : (
                            <Table.Root size="sm" maxW="480px">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Месяц</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Заказов</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">С отменами</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Доля</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {cancellations.map((month) => (
                                        <Table.Row key={month.month}>
                                            <Table.Cell>{month.month}</Table.Cell>
                                            <Table.Cell textAlign="right">{month.orders}</Table.Cell>
                                            <Table.Cell textAlign="right">{month.with_cancellations}</Table.Cell>
                                            <Table.Cell textAlign="right" fontWeight="600">
                                                {month.pct === null ? '—' : `${month.pct} %`}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                    </Card.Body>
                </Card.Root>
            </VStack>

            <ConfirmDialog
                open={clearFor !== null}
                onClose={() => setClearFor(null)}
                onConfirm={() => {
                    router.delete(`/wms/stock-buffers/${clearFor.id}/manual`, {
                        preserveScroll: true,
                        onFinish: () => setClearFor(null),
                    });
                }}
                title="Снять ручную пометку"
                description={clearFor
                    ? `Пометка «${clearFor.manual_qty} шт» по товару «${clearFor.name}» будет снята — дальше действует расчётный буфер.`
                    : ''}
                confirmLabel="Снять пометку"
                colorPalette="red"
            />
        </>
    );
}

Index.layout = (page) => <WmsLayout>{page}</WmsLayout>;
