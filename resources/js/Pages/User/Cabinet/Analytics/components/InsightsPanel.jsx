import { Box, HStack, VStack, Text, Heading, Badge, Accordion, Span, Tabs } from '@chakra-ui/react';
import {
    LuStar, LuTrendingUp, LuTrendingDown, LuClock, LuCheck, LuList, LuInfo,
} from 'react-icons/lu';

const fmtInt = (v) => Number(v ?? 0).toLocaleString('ru-RU');
const fmtMoney = (v) => Number(v ?? 0).toLocaleString('ru-RU', {
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
});

function Section({ value, icon: Icon, color, title, hint, isEmpty, emptyText, listSlot, methodSlot }) {
    return (
        <Accordion.Item value={value}>
            <Accordion.ItemTrigger py={3}>
                <HStack gap={3} flex="1" textAlign="left">
                    <Box p={2} bg={`${color}.subtle`} color={`${color}.fg`} borderRadius="lg">
                        <Icon size={18} />
                    </Box>
                    <VStack align="start" gap={0} flex="1" minW="0">
                        <Text fontWeight="600" fontSize="md" lineClamp={1}>{title}</Text>
                        <Text fontSize="xs" color="fg.muted" lineClamp={1}>{hint}</Text>
                    </VStack>
                </HStack>
                <Accordion.ItemIndicator />
            </Accordion.ItemTrigger>
            <Accordion.ItemContent>
                <Accordion.ItemBody pt={2} pb={4}>
                    <Tabs.Root defaultValue="list" variant="line" size="sm">
                        <Tabs.List mb={3}>
                            <Tabs.Trigger value="list">
                                <LuList /> Список
                            </Tabs.Trigger>
                            <Tabs.Trigger value="method">
                                <LuInfo /> Как считается
                            </Tabs.Trigger>
                        </Tabs.List>
                        <Tabs.Content value="list" px={0}>
                            {isEmpty ? (
                                <Box py={8} textAlign="center" color="fg.muted">
                                    <Text fontSize="sm">{emptyText}</Text>
                                </Box>
                            ) : listSlot}
                        </Tabs.Content>
                        <Tabs.Content value="method" px={0}>
                            {methodSlot}
                        </Tabs.Content>
                    </Tabs.Root>
                </Accordion.ItemBody>
            </Accordion.ItemContent>
        </Accordion.Item>
    );
}

function MethodBlock({ children }) {
    return (
        <Box bg="bg.subtle" borderRadius="md" p={4}>
            <VStack align="stretch" gap={2} fontSize="sm" color="fg.muted">
                {children}
            </VStack>
        </Box>
    );
}

const linkSx = {
    cursor: 'pointer',
    textDecoration: 'underline',
    textDecorationStyle: 'dotted',
    textUnderlineOffset: '3px',
    textDecorationColor: 'var(--chakra-colors-border)',
    _hover: { textDecorationStyle: 'solid', textDecorationColor: 'currentColor' },
};

function ProductLink({ slug, children }) {
    if (!slug) return <>{children}</>;
    return (
        <Box as="a" href={`/products/${slug}`} {...linkSx}>{children}</Box>
    );
}

function BrandButton({ brandId, label, onApplyFilter }) {
    if (!brandId || !onApplyFilter) {
        return <Text fontSize="sm">{label}</Text>;
    }
    return (
        <Box
            as="button"
            type="button"
            onClick={() => onApplyFilter({ brand_ids: [brandId] })}
            fontSize="sm"
            textAlign="left"
            {...linkSx}
        >
            {label}
        </Box>
    );
}

function ParetoList({ rows, symbol }) {
    return (
        <VStack align="stretch" gap={1} divideY="1px" divideColor="border.muted">
            {rows.map((p, i) => (
                <HStack key={`${p.sku || p.label}-${i}`} justify="space-between" gap={3} py={2} align="start">
                    <HStack gap={2} flex="1" minW="0" align="start">
                        <Text color="fg.muted" fontSize="sm" minW="22px" textAlign="right">{i + 1}.</Text>
                        <LuCheck size={16} color="var(--chakra-colors-green-500)" style={{ marginTop: 3 }} />
                        <VStack align="start" gap={0} minW="0">
                            <ProductLink slug={p.slug}>
                                <Text fontSize="sm" lineClamp={2}>{p.label}</Text>
                            </ProductLink>
                            {p.sku && <Text fontSize="xs" color="fg.muted">арт. {p.sku}</Text>}
                        </VStack>
                    </HStack>
                    <VStack align="end" gap={0} flexShrink={0}>
                        <Text fontWeight="600" fontSize="sm">{fmtMoney(p.amount)} {symbol}</Text>
                        <Text fontSize="xs" color="fg.muted">{fmtInt(p.qty)} шт · {p.share}%</Text>
                    </VStack>
                </HStack>
            ))}
        </VStack>
    );
}

function TrendList({ rows, symbol, direction, onApplyFilter }) {
    const colorScheme = direction === 'up' ? 'green' : 'red';
    return (
        <VStack align="stretch" gap={1} divideY="1px" divideColor="border.muted">
            {rows.map((b) => {
                const pctText = direction === 'up'
                    ? (b.is_new ? `+${fmtMoney(b.delta)} ${symbol}` : `+${b.delta_pct}%`)
                    : `${b.delta_pct}%`;
                return (
                    <HStack key={b.label} justify="space-between" gap={3} py={2} align="start">
                        <VStack align="start" gap={0} flex="1" minW="0">
                            <HStack gap={2} wrap="wrap">
                                <BrandButton
                                    brandId={b.brand_id}
                                    label={b.label}
                                    onApplyFilter={onApplyFilter}
                                />
                                {b.is_new && (
                                    <Badge size="xs" colorPalette="green" variant="subtle">новый</Badge>
                                )}
                            </HStack>
                            <Text fontSize="xs" color="fg.muted">
                                {fmtMoney(b.previous)} → {fmtMoney(b.amount)} {symbol}
                            </Text>
                        </VStack>
                        <Text color={`${colorScheme}.500`} fontWeight="700" fontSize="sm" whiteSpace="nowrap">
                            {pctText}
                        </Text>
                    </HStack>
                );
            })}
        </VStack>
    );
}

function ReorderList({ rows }) {
    return (
        <VStack align="stretch" gap={1} divideY="1px" divideColor="border.muted">
            {rows.map((p, i) => (
                <HStack key={`${p.sku || p.label}-${i}`} justify="space-between" gap={3} py={2} align="start">
                    <VStack align="start" gap={0} flex="1" minW="0">
                        <ProductLink slug={p.slug}>
                            <Text fontSize="sm" lineClamp={2}>{p.label}</Text>
                        </ProductLink>
                        {p.sku && <Text fontSize="xs" color="fg.muted">арт. {p.sku}</Text>}
                        <Text fontSize="xs" color="fg.muted">
                            обычно каждые {p.avg_interval_days} дн., прошло {p.days_since_last} (+{p.overdue_days} дн.)
                        </Text>
                    </VStack>
                    <VStack align="end" gap={0} flexShrink={0}>
                        <Text fontWeight="600" fontSize="sm">~{fmtInt(p.avg_qty)} шт</Text>
                        <Text fontSize="xs" color="fg.muted">{p.shipments_count} отгрузок</Text>
                    </VStack>
                </HStack>
            ))}
        </VStack>
    );
}

export default function InsightsPanel({ insights = {}, currency, onApplyFilter }) {
    const symbol = currency?.symbol || '₽';
    const pareto = insights.pareto ?? [];
    const rising = insights.rising ?? [];
    const falling = insights.falling ?? [];
    const reorder = insights.reorder ?? [];

    return (
        <VStack align="stretch" gap={4}>
            <Box>
                <HStack gap={2} mb={1}>
                    <Heading size="lg">Что заказать в следующий раз</Heading>
                    <Badge colorPalette="purple" size="sm">подсказки</Badge>
                </HStack>
                <Text color="fg.muted" fontSize="sm">
                    Готовые выводы из ваших отгрузок: что точно нужно держать, что начать брать чаще,
                    а что — пересмотреть. Подходит, если нет времени разбираться с графиками.
                </Text>
            </Box>

            <Accordion.Root collapsible multiple defaultValue={[]}>
                <Section
                    value="pareto"
                    icon={LuStar}
                    color="orange"
                    title={`Что точно держать (${pareto.length})`}
                    hint="Эти товары дают 80% оборота — без них никак"
                    isEmpty={pareto.length === 0}
                    emptyText="Нет данных за выбранный период"
                    listSlot={<ParetoList rows={pareto} symbol={symbol} />}
                    methodSlot={
                        <MethodBlock>
                            <Text>Используется правило Парето (80/20) применительно к вашим отгрузкам:</Text>
                            <Text>1. Берём все товары за выбранный период и считаем сумму отгрузок по каждому.</Text>
                            <Text>2. Сортируем по убыванию суммы.</Text>
                            <Text>3. Идём сверху и накапливаем долю — как только она достигает 80% от общего оборота, останавливаемся.</Text>
                            <Text>Итог — это «фундамент» продаж: пропустите эти позиции — потеряете большую часть выручки.</Text>
                        </MethodBlock>
                    }
                />
                <Section
                    value="rising"
                    icon={LuTrendingUp}
                    color="green"
                    title={`Растёт спрос (${rising.length})`}
                    hint="Эти бренды клиенты разбирают активнее — стоит докупить"
                    isEmpty={rising.length === 0}
                    emptyText="Нет брендов с заметным ростом"
                    listSlot={<TrendList rows={rising} symbol={symbol} direction="up" onApplyFilter={onApplyFilter} />}
                    methodSlot={
                        <MethodBlock>
                            <Text>Сравниваем выбранный период с предыдущим такой же длины:</Text>
                            <Text>1. Например, выбран «Текущий месяц» (1–5 мая) — сравниваем с 26–30 апреля.</Text>
                            <Text>2. Для каждого бренда смотрим сумму отгрузок «сейчас» vs «раньше».</Text>
                            <Text>3. В список попадают бренды с приростом более +10% (или совсем новые).</Text>
                            <Text>4. Бренд считается «новым», если в прошлом периоде давал меньше 5% от текущего объёма — для них вместо процентов показываем абсолютный прирост в рублях.</Text>
                            <Text>5. Сортировка — по абсолютному приросту в рублях, чтобы наверх не выскакивали микро-бренды с гигантскими процентами.</Text>
                        </MethodBlock>
                    }
                />
                <Section
                    value="falling"
                    icon={LuTrendingDown}
                    color="red"
                    title={`Падает спрос (${falling.length})`}
                    hint="Эти бренды просели — может, заказать меньше"
                    isEmpty={falling.length === 0}
                    emptyText="Нет брендов с заметным спадом"
                    listSlot={<TrendList rows={falling} symbol={symbol} direction="down" onApplyFilter={onApplyFilter} />}
                    methodSlot={
                        <MethodBlock>
                            <Text>Тот же сравнительный анализ периодов, но в обратную сторону:</Text>
                            <Text>1. В список попадают бренды с падением более чем -10%.</Text>
                            <Text>2. Чтобы не было шума, бренд должен был давать в прошлом периоде минимум 0.5% от текущего оборота.</Text>
                            <Text>3. Сортировка — по абсолютному падению в рублях.</Text>
                            <Text>Если бренд просел — это может означать сезонность, конкурентов, изменение цены или ситуацию на полке у клиента. Стоит проверить причины перед следующим заказом.</Text>
                        </MethodBlock>
                    }
                />
                <Section
                    value="reorder"
                    icon={LuClock}
                    color="blue"
                    title={`Пора повторить заказ (${reorder.length})`}
                    hint="Регулярные товары, по которым прошёл обычный интервал"
                    isEmpty={reorder.length === 0}
                    emptyText="Нет товаров с регулярными закупками за год"
                    listSlot={<ReorderList rows={reorder} />}
                    methodSlot={
                        <MethodBlock>
                            <Text>Анализируем регулярность закупа за последние 12 месяцев (независимо от выбранного периода):</Text>
                            <Text>1. Берём товары, которые отгружались минимум 3 раза.</Text>
                            <Text>2. Считаем средний интервал: количество дней между первой и последней отгрузкой делим на (число отгрузок − 1).</Text>
                            <Text>3. Если с последней отгрузки прошло больше среднего интервала — товар попадает в список.</Text>
                            <Text>4. «Просрочка» = (дней с последней отгрузки) − (средний интервал). Чем больше — тем выше в списке.</Text>
                            <Text>5. Размер заказа: средний по прошлым отгрузкам (общее количество / число отгрузок).</Text>
                            <Text>Отсекаются интервалы короче 5 и длиннее 180 дней — это слишком случайные сценарии для прогноза.</Text>
                        </MethodBlock>
                    }
                />
            </Accordion.Root>
        </VStack>
    );
}
