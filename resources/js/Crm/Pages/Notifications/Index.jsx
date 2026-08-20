import { useMemo, useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, Heading, Input, Text, VStack } from '@chakra-ui/react';
import { LuBellRing, LuCirclePlus, LuOctagonX, LuPencil, LuSend, LuTrash2 } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import RuleFormDialog from './components/RuleFormDialog';

/**
 * Пульт уведомлений — список правил маршрутизации.
 *
 * Правила сгруппированы по событию и показаны «лестницей разбора»: сверху вниз
 * в порядке приоритета, с отметкой, где сработает остановка и какие правила
 * она отрежет. Без этого Sieve-логика остаётся неочевидной, а непонятное
 * правило менеджер обходит мессенджером — ровно то, от чего уходим.
 */
export default function Index({ rules, filters, events, canManageAll, counts }) {
    const [tab, setTab] = useState(filters?.scope || 'policy');
    const [search, setSearch] = useState(filters?.search || '');
    const [editing, setEditing] = useState(null);

    const visible = useMemo(() => {
        const byTab = rules.filter((rule) => {
            if (tab === 'policy') return rule.is_policy && !rule.is_system;
            if (tab === 'exceptions') return !rule.is_policy && !rule.is_system;
            return rule.is_system;
        });

        if (!search.trim()) return byTab;

        const needle = search.trim().toLowerCase();
        return byTab.filter(
            (rule) =>
                rule.name.toLowerCase().includes(needle) ||
                (rule.scope_label || '').toLowerCase().includes(needle),
        );
    }, [rules, tab, search]);

    // Группировка по событию: внутри группы правила уже отсортированы бэкендом
    // по приоритету, поэтому порядок в списке совпадает с порядком разбора.
    const groups = useMemo(() => {
        const map = new Map();
        visible.forEach((rule) => {
            if (!map.has(rule.event_key)) {
                map.set(rule.event_key, { label: rule.event_label, items: [] });
            }
            map.get(rule.event_key).items.push(rule);
        });
        return Array.from(map.entries());
    }, [visible]);

    const toggle = (rule) => router.post(`/crm/notifications/rules/${rule.id}/toggle`, {}, { preserveScroll: true });
    const remove = (rule) => router.delete(`/crm/notifications/rules/${rule.id}`, { preserveScroll: true });
    const testSend = (rule) => router.post(`/crm/notifications/rules/${rule.id}/test-send`, {}, { preserveScroll: true });
    const override = (rule) => router.post(`/crm/notifications/rules/${rule.id}/override`, {}, { preserveScroll: true });

    const tabs = [
        { key: 'policy', label: 'Политика отдела', hint: 'Действуют на всех партнёров', count: counts.policy },
        { key: 'exceptions', label: 'Исключения', hint: 'Правила конкретных клиентов', count: counts.exceptions },
        { key: 'system', label: 'Системные', hint: 'Поведение по умолчанию', count: counts.system },
    ];

    return (
        <CrmLayout>
            <Head title="Пульт уведомлений" />

            <VStack align="stretch" gap={5}>
                <Flex justify="space-between" align="center" wrap="wrap" gap={3}>
                    <HStack gap={3}>
                        <LuBellRing size={22} />
                        <Heading size="lg">Пульт уведомлений</Heading>
                    </HStack>
                    <Button size="sm" onClick={() => setEditing({})}>
                        <LuCirclePlus /> Новое правило
                    </Button>
                </Flex>

                <Text fontSize="sm" color="fg.muted" maxW="4xl">
                    Правила разбираются сверху вниз в порядке приоритета. Срабатывают все подходящие,
                    но правило с отметкой «остановка» прерывает разбор — так задаётся «вместо», а не «вдобавок».
                    Один и тот же адрес получит письмо один раз.
                </Text>

                <HStack gap={2} wrap="wrap">
                    {tabs.map((item) => (
                        <Button
                            key={item.key}
                            size="sm"
                            variant={tab === item.key ? 'solid' : 'outline'}
                            onClick={() => setTab(item.key)}
                            title={item.hint}
                        >
                            {item.label}
                            <Badge ml={2} variant="subtle">{item.count}</Badge>
                        </Button>
                    ))}
                    <Input
                        size="sm"
                        maxW="260px"
                        placeholder="Поиск по названию"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                </HStack>

                {tab === 'policy' && (
                    <Box borderWidth="1px" borderRadius="md" p={3} bg="blue.50" _dark={{ bg: 'blue.950' }}>
                        <Text fontSize="sm">
                            Основной способ настройки. Одно правило с получателем-ролью
                            («бухгалтеру этого контрагента») покрывает всю базу — заводить правило
                            на каждого клиента не нужно.
                        </Text>
                    </Box>
                )}

                {groups.length === 0 && (
                    <Card.Root>
                        <Card.Body>
                            <Text color="fg.muted">
                                {tab === 'exceptions'
                                    ? 'Исключений нет — все клиенты обслуживаются политикой отдела.'
                                    : 'Правил пока нет.'}
                            </Text>
                        </Card.Body>
                    </Card.Root>
                )}

                {groups.map(([eventKey, group]) => (
                    <Card.Root key={eventKey}>
                        <Card.Header pb={2}>
                            <Heading size="sm">{group.label}</Heading>
                        </Card.Header>
                        <Card.Body pt={0}>
                            <VStack align="stretch" gap={0}>
                                {group.items.map((rule, index) => {
                                    const stoppedByAbove = group.items
                                        .slice(0, index)
                                        .some((r) => r.stop_processing && r.is_active);

                                    return (
                                        <Box
                                            key={rule.id}
                                            borderTopWidth={index === 0 ? 0 : '1px'}
                                            py={3}
                                            opacity={rule.is_active ? 1 : 0.55}
                                        >
                                            <Flex justify="space-between" align="start" gap={4} wrap="wrap">
                                                <Box flex="1 1 420px">
                                                    <HStack gap={2} mb={1} wrap="wrap">
                                                        <Badge variant="outline">{rule.priority}</Badge>
                                                        <Text fontWeight="600">{rule.name}</Text>
                                                        {rule.is_system && (
                                                            <Badge colorPalette="gray" variant="subtle">системное</Badge>
                                                        )}
                                                        {rule.stop_processing && (
                                                            <Badge colorPalette="red" variant="subtle">
                                                                <LuOctagonX size={12} /> остановка
                                                            </Badge>
                                                        )}
                                                        {rule.is_stale && (
                                                            <Badge colorPalette="orange" variant="subtle">
                                                                ни разу не сработало
                                                            </Badge>
                                                        )}
                                                    </HStack>

                                                    <Text fontSize="sm" color="fg.muted">
                                                        {rule.humanized}
                                                    </Text>

                                                    {stoppedByAbove && rule.is_active && (
                                                        <Text fontSize="xs" color="orange.600" mt={1}>
                                                            Может не сработать: правило выше останавливает разбор
                                                        </Text>
                                                    )}

                                                    <HStack gap={2} mt={2} wrap="wrap">
                                                        <Badge variant="subtle" colorPalette="purple">
                                                            {rule.scope_label}
                                                        </Badge>
                                                        {rule.matched_count > 0 && (
                                                            <Text fontSize="xs" color="fg.muted">
                                                                сработало {rule.matched_count} раз
                                                                {rule.last_matched_at ? `, последний — ${rule.last_matched_at}` : ''}
                                                            </Text>
                                                        )}
                                                    </HStack>
                                                </Box>

                                                <HStack gap={2}>
                                                    <Switch
                                                        checked={rule.is_active}
                                                        disabled={!rule.can_edit}
                                                        onCheckedChange={() => toggle(rule)}
                                                    />
                                                    {rule.can_edit && !rule.is_system && (
                                                        <Button size="xs" variant="ghost" onClick={() => setEditing(rule)}>
                                                            <LuPencil />
                                                        </Button>
                                                    )}
                                                    {rule.is_system && canManageAll && (
                                                        <Button size="xs" variant="ghost" onClick={() => override(rule)} title="Создать копию, перекрывающую системное правило">
                                                            Переопределить
                                                        </Button>
                                                    )}
                                                    <Button size="xs" variant="ghost" onClick={() => testSend(rule)} title="Отправить проверочное письмо себе">
                                                        <LuSend />
                                                    </Button>
                                                    {rule.can_edit && !rule.is_system && (
                                                        <Button size="xs" variant="ghost" colorPalette="red" onClick={() => remove(rule)}>
                                                            <LuTrash2 />
                                                        </Button>
                                                    )}
                                                </HStack>
                                            </Flex>
                                        </Box>
                                    );
                                })}
                            </VStack>
                        </Card.Body>
                    </Card.Root>
                ))}
            </VStack>

            {editing !== null && (
                <RuleFormDialog
                    rule={editing.id ? editing : null}
                    events={events}
                    canManageAll={canManageAll}
                    onClose={() => setEditing(null)}
                />
            )}
        </CrmLayout>
    );
}
