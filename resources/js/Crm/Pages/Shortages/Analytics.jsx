import { Head, Link, router } from '@inertiajs/react';
import { Badge, Box, HStack, Input, SimpleGrid, Table, Text, VStack } from '@chakra-ui/react';
import { useState } from 'react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { LuArrowLeft } from 'react-icons/lu';

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

const KIND_LABELS = {
    same_product_wait: 'Подождать прихода',
    defect_same: 'Уценка того же товара',
    linked: 'Подтверждённая связь',
    variant: 'Вариант модели',
    line: 'Линейка бренда',
    functional: 'Функциональный тип',
    category_price: 'Категория и цена',
    semantic: 'Семантика',
    manual: 'Ручной подбор',
};

const Tile = ({ label, value, hint }) => (
    <Box borderWidth="1px" borderRadius="lg" p={4}>
        <Text fontSize="xs" color="fg.muted">{label}</Text>
        <Text fontSize="2xl" fontWeight="bold">{value}</Text>
        {hint && <Text fontSize="xs" color="fg.muted">{hint}</Text>}
    </Box>
);

/**
 * Срез недоборов: воронка спасения заказов цифрами.
 *
 * Спасённая сумма считается точно — по заказам с replacement_for_order_id,
 * ради этого поле и заводилось.
 */
export default function Analytics({ metrics, layers, managers, retention, repeated, filters }) {
    const [from, setFrom] = useState(filters.from);
    const [to, setTo] = useState(filters.to);

    const apply = () => router.get('/crm/analytics/shortages', { from, to }, { preserveState: true });

    return (
        <>
            <Head title="Аналитика недоборов — CRM" />

            <HStack mb={2}>
                <Link href="/crm/shortages">
                    <Button size="xs" variant="ghost"><LuArrowLeft /> К очереди недоборов</Button>
                </Link>
                <Link href="/crm/analytics">
                    <Button size="xs" variant="ghost">Отчёты продаж</Button>
                </Link>
            </HStack>

            <PageHeader
                title="Недоборы: аналитика"
                description="Скорость реакции, покрытие, конверсия и спасённая сумма — по подборкам замен"
            />

            <HStack mb={4} gap={2} flexWrap="wrap">
                <Input type="date" size="sm" width="170px" value={from} onChange={(e) => setFrom(e.target.value)} />
                <Text color="fg.muted">—</Text>
                <Input type="date" size="sm" width="170px" value={to} onChange={(e) => setTo(e.target.value)} />
                <Button size="sm" onClick={apply}>Показать</Button>
            </HStack>

            <SimpleGrid columns={{ base: 2, md: 3, xl: 6 }} gap={3} mb={6}>
                <Tile label="Подборок за период" value={metrics.offers_total} />
                <Tile
                    label="Медиана реакции"
                    value={metrics.median_reaction_hours !== null ? `${metrics.median_reaction_hours} ч` : '—'}
                    hint="от отмены до письма; цель — до конца дня"
                />
                <Tile
                    label="Покрытие"
                    value={metrics.coverage_pct !== null ? `${metrics.coverage_pct}%` : '—'}
                    hint={`отправлено ${metrics.offers_sent} из ${metrics.offers_total}`}
                />
                <Tile
                    label="Конверсия"
                    value={metrics.conversion_pct !== null ? `${metrics.conversion_pct}%` : '—'}
                    hint={`согласовано ${metrics.offers_confirmed}`}
                />
                <Tile
                    label="Строк закрыто заменой"
                    value={metrics.replaced_lines_pct !== null ? `${metrics.replaced_lines_pct}%` : '—'}
                    hint={`${metrics.replaced_lines} из ${metrics.cancelled_lines} отменённых`}
                />
                <Tile label="Спасённая сумма" value={`${money(metrics.saved_amount)} ₽`} hint="заказы-замены периода" />
            </SimpleGrid>

            <SimpleGrid columns={{ base: 1, xl: 2 }} gap={6} alignItems="start">
                <VStack align="stretch" gap={6}>
                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <Text fontWeight="bold" mb={3}>Какие слои принимает клиент</Text>
                        {layers.length === 0 ? (
                            <Text fontSize="sm" color="fg.muted">Данных за период нет.</Text>
                        ) : (
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Слой</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Предложено</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Выбрано</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {layers.map((row) => (
                                        <Table.Row key={row.kind}>
                                            <Table.Cell>{KIND_LABELS[row.kind] || row.kind}</Table.Cell>
                                            <Table.Cell textAlign="right">{row.offered}</Table.Cell>
                                            <Table.Cell textAlign="right">{row.chosen}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        )}
                        <Text mt={2} fontSize="xs" color="fg.muted">
                            Вход для тюнинга автоподбора и решения об автоотправке (фаза 3).
                        </Text>
                    </Box>

                    <Box borderWidth="1px" borderRadius="lg" p={4}>
                        <Text fontWeight="bold" mb={2}>Удержание</Text>
                        <HStack gap={6}>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Клиентов с недобором</Text>
                                <Text fontSize="xl" fontWeight="bold">{retention.shortage_clients}</Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Заказали снова после недобора</Text>
                                <Text fontSize="xl" fontWeight="bold">
                                    {retention.shortage_repeat_pct !== null ? `${retention.shortage_repeat_pct}%` : '—'}
                                </Text>
                            </Box>
                            <Box>
                                <Text fontSize="xs" color="fg.muted">Повторные заказы у остальных</Text>
                                <Text fontSize="xl" fontWeight="bold">
                                    {retention.other_repeat_pct !== null ? `${retention.other_repeat_pct}%` : '—'}
                                </Text>
                            </Box>
                        </HStack>
                        <Text mt={2} fontSize="xs" color="fg.muted">
                            Ответ цифрой на «удар по репутации»: теряем ли клиентов после недобора.
                        </Text>
                    </Box>

                    {managers.length > 0 && (
                        <Box borderWidth="1px" borderRadius="lg" p={4}>
                            <Text fontWeight="bold" mb={3}>По менеджерам</Text>
                            <Table.Root size="sm">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Менеджер</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Подборок</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Согласовано</Table.ColumnHeader>
                                        <Table.ColumnHeader textAlign="right">Спасено, ₽</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {managers.map((row) => (
                                        <Table.Row key={row.manager}>
                                            <Table.Cell>{row.manager}</Table.Cell>
                                            <Table.Cell textAlign="right">{row.offers}</Table.Cell>
                                            <Table.Cell textAlign="right">{row.confirmed}</Table.Cell>
                                            <Table.Cell textAlign="right">{money(row.saved)}</Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}
                </VStack>

                <Box borderWidth="1px" borderRadius="lg" p={4}>
                    <HStack justify="space-between" mb={3}>
                        <Text fontWeight="bold">Повторные недоборы за 90 дней</Text>
                        <Badge variant="subtle">уходит закупщику еженедельно</Badge>
                    </HStack>
                    {repeated.length === 0 ? (
                        <Text fontSize="sm" color="fg.muted">Повторных недоборов нет.</Text>
                    ) : (
                        <Table.Root size="sm">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeader>Товар</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Отмен</Table.ColumnHeader>
                                    <Table.ColumnHeader textAlign="right">Потеряно, ₽</Table.ColumnHeader>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {repeated.map((row, index) => (
                                    <Table.Row key={index}>
                                        <Table.Cell>
                                            <Text fontSize="sm" lineClamp={1}>{row.name}</Text>
                                        </Table.Cell>
                                        <Table.Cell textAlign="right">{row.shortages}</Table.Cell>
                                        <Table.Cell textAlign="right">{money(row.lost_amount)}</Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    )}
                </Box>
            </SimpleGrid>
        </>
    );
}

Analytics.layout = (page) => <CrmLayout>{page}</CrmLayout>;
