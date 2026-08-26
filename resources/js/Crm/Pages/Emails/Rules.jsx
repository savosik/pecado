import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { SearchInput } from '@/Admin/Components/SearchInput';
import { Checkbox } from '@/components/ui/checkbox';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { Switch } from '@/components/ui/switch';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import RuleForm from '@/Crm/Pages/Emails/components/RuleForm';
import { LuBan, LuList, LuMail, LuPlay, LuPlus } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Правила-фильтры над потоком писем.
 *
 * Отдельного пункта меню у правил нет: это вкладка внутри «Писем». Дробление
 * на разделы — ровно то, из-за чего предыдущий подход оказался непонятным.
 */
const selectStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '190px',
};

export default function Rules({
    rules,
    filters = {},
    authors = [],
    fieldGroups,
    operators,
    unaryOperators,
    tagSuggestions,
    autoSendEnabled,
    streamEnabled,
    canManage,
    applyToOldDays = 14,
    prefillTag = null,
}) {
    // Переход из сводки «Мимо фильтров»: форма открывается сразу и с набранным
    // условием — иначе менеджер, увидевший «недоборы: 340», попадал бы на пустой
    // экран и переписывал метку руками.
    const [editing, setEditing] = useState(() => (prefillTag && canManage
        ? { conditions: [{ field: 'tag', op: 'has_tag', value: prefillTag }] }
        : null));
    const [pendingDelete, setPendingDelete] = useState(null);
    const [busy, setBusy] = useState(false);

    const reload = () => router.reload();

    // Правил станет много, и список без отбора быстро перестанет быть списком.
    const apply = (patch) => {
        router.get(route('crm.emails.rules.index'), { ...filters, ...patch }, {
            preserveState: true,
            replace: true,
        });
    };

    const saved = () => {
        setEditing(null);
        toastSuccess(
            'Правило сохранено',
            'Оно ловит письма, которые система соберёт дальше. Чтобы поднять уже собранные — «Применить к старым».',
        );
        reload();
    };

    const applyToOld = async (rule) => {
        setBusy(true);
        try {
            const res = await axios.post(route('crm.emails.rules.apply-to-old', rule.id));
            toastSuccess('Готово', res.data?.message);
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const toggle = async (rule) => {
        setBusy(true);
        try {
            await axios.post(route('crm.emails.rules.toggle', rule.id));
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const remove = async (id) => {
        setBusy(true);
        try {
            await axios.delete(route('crm.emails.rules.destroy', id));
            toastSuccess('Правило удалено');
            reload();
        } catch (e) {
            toastError('Не получилось', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
            setPendingDelete(null);
        }
    };

    return (
        <>
            <Head title="CRM — Правила писем" />
            <PageHeader
                title="Правила"
                description="Фильтры над потоком писем: кому и что отправлять"
                actions={(
                    <HStack gap={2}>
                        <Link href={route('crm.emails.index')}>
                            <Button size="sm" variant="outline"><LuMail /> К письмам</Button>
                        </Link>
                        <Link href={route('crm.emails.occasions.index')}>
                            <Button size="sm" variant="outline"><LuList /> Поводы</Button>
                        </Link>
                        <Link href={route('crm.emails.suppressions.index')}>
                            <Button size="sm" variant="outline"><LuBan /> Стоп-лист</Button>
                        </Link>
                        {canManage && (
                            <Button size="sm" onClick={() => setEditing({})}><LuPlus /> Новое правило</Button>
                        )}
                    </HStack>
                )}
            />

            <VStack align="stretch" gap={4}>
                {!streamEnabled && (
                    <Alert status="info" title="Система пока не собирает письма сама">
                        Правила уже можно завести и посмотреть, что они ловят, — они работают
                        и с письмами менеджеров. Автоматическая сборка писем по поводам включается
                        флагом MAIL_STREAM_ENABLED.
                    </Alert>
                )}

                {!editing && (
                    <HStack gap={3} flexWrap="wrap" align="center">
                        <Box flex="1" minW="220px">
                            <SearchInput
                                value={filters.search || ''}
                                onChange={(value) => apply({ search: value || undefined })}
                                placeholder="Поиск по названию, условию и адресу..."
                            />
                        </Box>

                        <Box minW="200px">
                            <SearchInput
                                value={filters.client || ''}
                                onChange={(value) => apply({ client: value || undefined })}
                                placeholder="Партнёр..."
                            />
                        </Box>

                        <select
                            value={filters.author_id || ''}
                            onChange={(e) => apply({ author_id: e.target.value || undefined })}
                            style={selectStyle}
                        >
                            <option value="">Кто угодно завёл</option>
                            {authors.map((author) => (
                                <option key={author.id} value={author.id}>{author.name}</option>
                            ))}
                        </select>

                        <Checkbox
                            checked={!!filters.only_auto}
                            onCheckedChange={(e) => apply({ only_auto: e.checked ? 1 : undefined })}
                        >
                            Только автоматические
                        </Checkbox>
                    </HStack>
                )}

                {editing && (
                    <RuleForm
                        rule={editing.id || editing.conditions ? editing : null}
                        fieldGroups={fieldGroups}
                        operators={operators}
                        unaryOperators={unaryOperators}
                        tagSuggestions={tagSuggestions}
                        autoSendEnabled={autoSendEnabled}
                        onSaved={saved}
                        onCancel={() => setEditing(null)}
                    />
                )}

                {rules.length === 0 && !editing && (filters.search || filters.client || filters.author_id || filters.only_auto) && (
                    <Alert status="info" title="Под отбор ничего не подошло">
                        Отбор по партнёру показывает правила, которые действительно ловили его
                        письма. Правило, ещё ничего не поймавшее, сюда не попадёт.
                    </Alert>
                )}

                {rules.length === 0 && !editing && !(filters.search || filters.client || filters.author_id || filters.only_auto) && (
                    <Alert status="info" title="Правил пока нет">
                        Пока правил нет, письма, собранные системой, лежат в папке «Мимо фильтров»
                        и никуда не уходят. Правило — это условие и адреса: «содержит акт-сверки
                        и ИНН такой-то → отправить бухгалтеру». Новое правило работает вперёд:
                        ловит то, что система соберёт после его создания.
                    </Alert>
                )}

                {rules.map((rule) => (
                    <Box
                        key={rule.id}
                        borderWidth="1px"
                        borderRadius="lg"
                        p={4}
                        opacity={rule.is_active ? 1 : 0.6}
                    >
                        <HStack justifyContent="space-between" align="start" flexWrap="wrap" gap={3}>
                            <VStack align="start" gap={1} flex="1" minW="280px">
                                <HStack gap={2} flexWrap="wrap">
                                    <Text fontSize="md" fontWeight="700">{rule.name}</Text>
                                    {rule.auto_send && (
                                        <Badge colorPalette="purple" variant="subtle">отправляет само</Badge>
                                    )}
                                    {rule.catches_nothing && (
                                        <Badge colorPalette="orange" variant="subtle">ничего не поймало</Badge>
                                    )}
                                </HStack>

                                <Text fontSize="sm" color="fg.muted">{rule.conditions_text}</Text>
                                <Text fontSize="sm">→ {rule.recipients.join(', ')}</Text>
                                {rule.clients?.length > 0 && (
                                    <Text fontSize="xs" color="fg.muted">
                                        подписаны: {rule.clients.slice(0, 3).map((c) => c.label).join(', ')}
                                        {rule.clients.length > 3 ? ` и ещё ${rule.clients.length - 3}` : ''}
                                    </Text>
                                )}
                                {rule.cc.length > 0 && (
                                    <Text fontSize="xs" color="fg.muted">копия: {rule.cc.join(', ')}</Text>
                                )}
                                <Text fontSize="xs" color="fg.muted">
                                    Поймано за месяц: {rule.matched_last_month} · всего: {rule.matched_count}
                                    {rule.last_matched_at_label ? ` · последний раз ${rule.last_matched_at_label}` : ''}
                                    {rule.author ? ` · завёл ${rule.author}` : ''}
                                </Text>
                            </VStack>

                            <HStack gap={2}>
                                {canManage && (
                                    <Switch
                                        checked={rule.is_active}
                                        disabled={busy}
                                        onCheckedChange={() => toggle(rule)}
                                    />
                                )}
                                <RowActions
                                    size="xs"
                                    edit={{ allowed: Boolean(canManage), onClick: () => setEditing(rule) }}
                                    extra={[{
                                        icon: LuPlay,
                                        label: `Применить к старым: разобрать письма за последние ${applyToOldDays} дн. до создания правила`,
                                        allowed: Boolean(canManage && rule.is_active),
                                        disabled: busy,
                                        onClick: () => applyToOld(rule),
                                    }]}
                                    delete={{
                                        allowed: Boolean(canManage),
                                        label: 'Удалить правило',
                                        onClick: () => setPendingDelete(rule.id),
                                    }}
                                />
                            </HStack>
                        </HStack>
                    </Box>
                ))}
            </VStack>

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => remove(pendingDelete)}
                title="Удалить правило?"
                description="Письма, которые оно уже поймало, останутся на месте. Новые ловиться перестанут."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </>
    );
}

Rules.layout = (page) => <CrmLayout>{page}</CrmLayout>;
