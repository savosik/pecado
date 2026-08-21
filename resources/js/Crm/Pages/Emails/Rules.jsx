import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import axios from 'axios';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { Alert } from '@/components/ui/alert';
import { Switch } from '@/components/ui/switch';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import RuleForm from '@/Crm/Pages/Emails/components/RuleForm';
import { LuBan, LuMail, LuPlus, LuTrash2 } from 'react-icons/lu';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Правила-фильтры над потоком писем.
 *
 * Отдельного пункта меню у правил нет: это вкладка внутри «Писем». Дробление
 * на разделы — ровно то, из-за чего предыдущий подход оказался непонятным.
 */
export default function Rules({
    rules,
    fieldGroups,
    operators,
    unaryOperators,
    tagSuggestions,
    autoSendEnabled,
    streamEnabled,
    canManage,
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

    const saved = (data) => {
        setEditing(null);
        toastSuccess(
            'Правило сохранено',
            data?.moved ? `Из «Мимо фильтров» перешло писем: ${data.moved}` : undefined,
        );
        reload();
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

                {rules.length === 0 && !editing && (
                    <Alert status="info" title="Правил пока нет">
                        Пока правил нет, письма, собранные системой, лежат в папке «Мимо фильтров»
                        и никуда не уходят. Правило — это условие и адреса: «содержит акт-сверки
                        и ИНН такой-то → отправить бухгалтеру».
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
                                    {rule.matched_count === 0 && (
                                        <Badge colorPalette="orange" variant="subtle">ничего не поймало</Badge>
                                    )}
                                </HStack>

                                <Text fontSize="sm" color="fg.muted">{rule.conditions_text}</Text>
                                <Text fontSize="sm">→ {rule.recipients.join(', ')}</Text>
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
                                {canManage && (
                                    <Button size="xs" variant="outline" onClick={() => setEditing(rule)}>
                                        Изменить
                                    </Button>
                                )}
                                {canManage && (
                                    <Button
                                        size="xs"
                                        variant="ghost"
                                        colorPalette="red"
                                        onClick={() => setPendingDelete(rule.id)}
                                        title="Удалить правило"
                                    >
                                        <LuTrash2 />
                                    </Button>
                                )}
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
