import { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Badge,
    Box,
    HStack,
    Input,
    NativeSelectField,
    NativeSelectRoot,
    Text,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { toastSuccess } from '@/utils/toast';

/**
 * Статусы партнёра.
 *
 * Их два и они разного происхождения: жизненный статус — поле сайта, им управляет
 * менеджер; лояльность приходит из 1С и перезаписывается там при каждом partner.updated,
 * поэтому здесь она только читается и подписана источником.
 *
 * @param {number} clientId
 * @param {object} lifecycle — payload из ClientController::lifecyclePayload()
 * @param {Array} options — варианты жизненного статуса с цветами
 * @param {object|null} loyalty — client_status из 1С
 * @param {boolean} canEdit — право crm-profile.edit
 */
export default function ClientLifecyclePanel({ clientId, lifecycle, options, loyalty, canEdit }) {
    const [historyOpen, setHistoryOpen] = useState(false);

    const form = useForm({
        lifecycle_status: lifecycle.status,
        reason: '',
    });
    const { data, setData, processing, errors } = form;

    // Стадию меняют и чипом в шапке карточки. useForm читает начальное значение
    // один раз, поэтому без синхронизации селект остался бы на прежней стадии,
    // а «Сохранить статус» предлагал бы откатить только что сделанную смену.
    useEffect(() => {
        setData('lifecycle_status', lifecycle.status);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lifecycle.status]);

    // transform, а не setData перед отправкой: setData асинхронен, и «Применить»
    // из подсказки уехало бы со старым значением селекта.
    const submit = (status, reason) => {
        form
            .transform(() => ({
                lifecycle_status: status,
                reason: reason ?? data.reason,
            }))
            .put(route('crm.clients.lifecycle.update', clientId), {
                preserveScroll: true,
                onSuccess: () => {
                    setData('reason', '');
                    toastSuccess('Статус изменён');
                },
            });
    };

    return (
        <VStack align="stretch" gap={4}>
            <HStack gap={8} align="start" wrap="wrap">
                <Box>
                    <Text fontSize="xs" color="fg.muted" mb={1}>Жизненный статус</Text>
                    <Badge colorPalette={lifecycle.status_color} variant="subtle" size="lg">
                        {lifecycle.status_label}
                    </Badge>
                    {lifecycle.changed_at && (
                        <Text fontSize="xs" color="fg.muted" mt={1}>
                            {lifecycle.changed_by || 'Сотрудник'}, {lifecycle.changed_at}
                        </Text>
                    )}
                </Box>

                <Box>
                    <Text fontSize="xs" color="fg.muted" mb={1}>Статус лояльности</Text>
                    {loyalty
                        ? <Badge colorPalette="gray" variant="subtle" size="lg">{loyalty.name}</Badge>
                        : <Text fontSize="sm">—</Text>}
                    <Text fontSize="xs" color="fg.muted" mt={1}>Источник: 1С, в CRM не редактируется</Text>
                </Box>
            </HStack>

            {lifecycle.hint && canEdit && (
                <Box borderWidth="1px" borderColor="orange.300" borderRadius="md" p={3} bg="orange.50" _dark={{ bg: 'orange.950' }}>
                    <Text fontSize="sm">
                        Похоже, статус стоит сменить на «{lifecycle.hint.label}» — {lifecycle.hint.reason}.
                    </Text>
                    <HStack mt={2} gap={2}>
                        <Button
                            size="xs"
                            loading={processing}
                            onClick={() => submit(lifecycle.hint.status, lifecycle.hint.reason)}
                        >
                            Применить
                        </Button>
                        <Text fontSize="xs" color="fg.muted">Подсказка от {lifecycle.hint.at}</Text>
                    </HStack>
                </Box>
            )}

            {canEdit && (
                <HStack gap={3} align="end" wrap="wrap">
                    <Field label="Сменить статус" errorText={errors.lifecycle_status} invalid={!!errors.lifecycle_status} maxW="220px">
                        <NativeSelectRoot>
                            <NativeSelectField
                                value={data.lifecycle_status}
                                onChange={(e) => setData('lifecycle_status', e.target.value)}
                            >
                                {options.map((option) => (
                                    <option key={option.value} value={option.value}>{option.label}</option>
                                ))}
                            </NativeSelectField>
                        </NativeSelectRoot>
                    </Field>

                    <Field label="Причина" errorText={errors.reason} invalid={!!errors.reason} flex="1" minW="240px">
                        <Input
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            placeholder="Например: не отвечает третий месяц"
                        />
                    </Field>

                    <Button
                        loading={processing}
                        disabled={data.lifecycle_status === lifecycle.status}
                        onClick={() => submit(data.lifecycle_status)}
                    >
                        Сохранить статус
                    </Button>
                </HStack>
            )}

            {lifecycle.history?.length > 0 && (
                <Box>
                    <Button size="xs" variant="ghost" onClick={() => setHistoryOpen((v) => !v)}>
                        {historyOpen ? 'Скрыть историю статусов' : `История статусов (${lifecycle.history.length})`}
                    </Button>

                    {historyOpen && (
                        <VStack align="stretch" gap={2} mt={2}>
                            {lifecycle.history.map((change) => (
                                <Box key={change.id} borderWidth="1px" borderRadius="md" p={2}>
                                    <Text fontSize="sm">
                                        {change.from ? `${change.from} → ${change.to}` : `Установлен: ${change.to}`}
                                    </Text>
                                    <Text fontSize="xs" color="fg.muted">
                                        {change.author}, {change.created_at}
                                        {change.reason ? ` — ${change.reason}` : ''}
                                    </Text>
                                </Box>
                            ))}
                        </VStack>
                    )}
                </Box>
            )}
        </VStack>
    );
}
