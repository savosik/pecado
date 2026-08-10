import { useEffect, useState } from 'react';
import axios from 'axios';
import { Badge, Box, HStack, SimpleGrid, Spinner, Table, Text, VStack } from '@chakra-ui/react';
import { Area, AreaChart, CartesianGrid, ResponsiveContainer, Tooltip, XAxis, YAxis } from 'recharts';
import {
    DrawerBackdrop, DrawerBody, DrawerCloseTrigger, DrawerContent, DrawerHeader, DrawerRoot, DrawerTitle,
} from '@/components/ui/drawer';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { useColorMode } from '@/components/ui/color-mode';
import { LuExternalLink } from 'react-icons/lu';

const money = (value) => `${Number(value ?? 0).toLocaleString('ru-RU', { maximumFractionDigits: 0 })} ₽`;

const compact = (value) => {
    const n = Math.abs(Number(value ?? 0));
    const trim = (x) => x.toFixed(1).replace(/\.0$/, '').replace('.', ',');
    if (n >= 1_000_000) return `${trim(n / 1_000_000)} млн`;
    if (n >= 1_000) return `${trim(n / 1_000)} тыс`;

    return String(Math.round(n));
};

const monthLabel = (period) => {
    const d = new Date(period);

    return Number.isNaN(d.getTime())
        ? period
        : d.toLocaleDateString('ru-RU', { month: 'short', year: '2-digit' });
};

function Stat({ label, value, hint }) {
    return (
        <Box bg="bg.panel" borderWidth="1px" borderColor="border" borderRadius="lg" p={3}>
            <Text fontSize="xs" color="fg.muted">{label}</Text>
            <Text fontSize="lg" fontWeight="700">{value}</Text>
            {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
        </Box>
    );
}

function Breakdown({ title, rows }) {
    if (!rows || rows.length === 0) return null;

    return (
        <Box>
            <Text fontSize="sm" fontWeight="600" mb={2}>{title}</Text>
            <Table.Root size="sm">
                <Table.Body>
                    {rows.slice(0, 8).map((row) => (
                        <Table.Row key={row.key ?? row.label}>
                            <Table.Cell>
                                <Text fontSize="sm">{row.label}</Text>
                            </Table.Cell>
                            <Table.Cell textAlign="right" w="120px">
                                <Text fontSize="sm">{money(row.amount)}</Text>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}

/**
 * Провал в партнёра: тренд, разрезы и последние документы.
 *
 * Панель, а не отдельная страница: грядка отвечает на вопрос «что здесь не так»,
 * и ответ должен приходить, не теряя полотно из виду — иначе после каждого
 * партнёра приходилось бы возвращаться и заново искать глазами, где остановился.
 */
export default function BedDrawer({ tile, month, scope, scopeId, onClose }) {
    const { colorMode } = useColorMode();
    const dark = colorMode === 'dark';

    const [data, setData] = useState(null);
    const [error, setError] = useState(null);

    useEffect(() => {
        if (!tile) {
            setData(null);
            setError(null);

            return;
        }

        // Чистим ответ предыдущего партнёра до запроса: иначе при переходе
        // с грядки на грядку панель успевает показать чужие цифры под новым
        // именем — а это не «мигнуло», а неверные данные о партнёре.
        setData(null);
        setError(null);

        let cancelled = false;
        const params = { month };
        if (scope === 'manager') {
            params.scope = 'manager';
            params.scope_id = scopeId;
        }

        axios.get(route('crm.beds.details', tile.id), { params })
            .then(({ data: payload }) => { if (!cancelled) setData(payload); })
            .catch((e) => {
                if (!cancelled) setError(e?.response?.data?.message || 'Не удалось загрузить карточку партнёра.');
            });

        return () => { cancelled = true; };
    }, [tile, month, scope, scopeId]);

    const signals = data?.signals;

    return (
        <DrawerRoot open={tile !== null} onOpenChange={(e) => !e.open && onClose()} size="xl">
            <DrawerBackdrop />
            <DrawerContent>
                <DrawerHeader>
                    <DrawerTitle>{tile?.name ?? ''}</DrawerTitle>
                </DrawerHeader>
                <DrawerBody>
                    {error && <Alert status="error" title="Ошибка">{error}</Alert>}

                    {!error && tile && !data && (
                        <HStack justify="center" py={10}><Spinner size="lg" /></HStack>
                    )}

                    {/* Обе проверки обязательны. Панель остаётся смонтированной ради
                        анимации закрытия, и на закрытии `tile` обнуляется раньше,
                        чем сбрасывается `data`: без защиты по `tile` тело успевает
                        отрендериться на старых данных и падает на `tile.id`. */}
                    {tile && data && (
                        <VStack align="stretch" gap={5}>
                            <HStack gap={2} flexWrap="wrap">
                                {signals?.abc && (
                                    <Badge colorPalette={signals.abc === 'A' ? 'green' : signals.abc === 'B' ? 'blue' : 'gray'} variant="subtle">
                                        Класс {signals.abc} по обороту за год
                                    </Badge>
                                )}
                                {tile?.sleeping && (
                                    <Badge colorPalette="orange" variant="subtle">
                                        Не покупает {signals?.days_since ?? tile.days_since} дн. при цикле {signals?.cycle_days ?? tile.cycle_days}
                                    </Badge>
                                )}
                                {tile?.plan === null && (
                                    <Badge colorPalette="gray" variant="subtle">Плана на месяц нет</Badge>
                                )}
                            </HStack>

                            <SimpleGrid columns={{ base: 2, md: 4 }} gap={3}>
                                <Stat label="Оборот за 12 мес" value={money(data.metrics?.total_amount)} />
                                <Stat label="Отгрузок" value={data.metrics?.shipments_count ?? 0} />
                                <Stat label="Средний чек" value={money(data.metrics?.avg_check)} />
                                <Stat
                                    label="План месяца"
                                    value={tile?.plan === null ? '—' : money(tile?.plan)}
                                    hint={tile?.plan === null ? 'не задан' : `факт ${money(tile?.fact)}`}
                                />
                            </SimpleGrid>

                            <Box>
                                <Text fontSize="sm" fontWeight="600" mb={2}>Отгрузки по месяцам</Text>
                                <Box h="200px">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <AreaChart data={data.timeline?.points ?? []} margin={{ top: 4, right: 8, bottom: 0, left: 0 }}>
                                            <CartesianGrid strokeDasharray="3 3" stroke={dark ? '#2f2f2c' : '#eceae5'} vertical={false} />
                                            <XAxis
                                                dataKey="period"
                                                tickFormatter={monthLabel}
                                                tick={{ fontSize: 11, fill: dark ? '#c3c2b7' : '#52514e' }}
                                                axisLine={false}
                                                tickLine={false}
                                            />
                                            <YAxis
                                                tickFormatter={compact}
                                                tick={{ fontSize: 11, fill: dark ? '#c3c2b7' : '#52514e' }}
                                                axisLine={false}
                                                tickLine={false}
                                                width={52}
                                            />
                                            <Tooltip
                                                formatter={(value) => [money(value), 'Отгружено']}
                                                labelFormatter={monthLabel}
                                                contentStyle={{
                                                    background: dark ? '#1a1a19' : '#fcfcfb',
                                                    border: `1px solid ${dark ? '#3a3a36' : '#e2e0da'}`,
                                                    borderRadius: 8,
                                                    fontSize: 12,
                                                }}
                                            />
                                            <Area
                                                type="monotone"
                                                dataKey="amount"
                                                name="Отгружено"
                                                stroke={dark ? '#3987e5' : '#2a78d6'}
                                                strokeWidth={2}
                                                fill={dark ? '#3987e5' : '#2a78d6'}
                                                fillOpacity={0.18}
                                            />
                                        </AreaChart>
                                    </ResponsiveContainer>
                                </Box>
                            </Box>

                            <SimpleGrid columns={{ base: 1, md: 2 }} gap={5}>
                                <Breakdown title="Бренды" rows={data.brands} />
                                <Breakdown title="Категории" rows={data.categories} />
                            </SimpleGrid>

                            <Breakdown title="Товары" rows={data.products} />

                            {(data.documents ?? []).length > 0 && (
                                <Box>
                                    <Text fontSize="sm" fontWeight="600" mb={2}>Последние документы</Text>
                                    <VStack align="stretch" gap={1}>
                                        {data.documents.map((doc) => (
                                            <HStack key={`${doc.type}-${doc.id}`} justify="space-between" gap={3}>
                                                <Text fontSize="sm">{doc.title}</Text>
                                                <HStack gap={3}>
                                                    <Text fontSize="xs" color="fg.muted">{doc.happened_at_label}</Text>
                                                    <Text fontSize="sm" color="fg.muted">{doc.amount_label}</Text>
                                                    {doc.entity?.url && (
                                                        <Button size="xs" variant="ghost" asChild aria-label="Открыть документ">
                                                            <a href={doc.entity.url}><LuExternalLink /></a>
                                                        </Button>
                                                    )}
                                                </HStack>
                                            </HStack>
                                        ))}
                                    </VStack>
                                </Box>
                            )}

                            <HStack>
                                <Button size="sm" variant="outline" asChild>
                                    <a href={route('crm.clients.show', tile.id)}>
                                        Открыть карточку партнёра <LuExternalLink />
                                    </a>
                                </Button>
                            </HStack>
                        </VStack>
                    )}
                </DrawerBody>
                <DrawerCloseTrigger />
            </DrawerContent>
        </DrawerRoot>
    );
}
