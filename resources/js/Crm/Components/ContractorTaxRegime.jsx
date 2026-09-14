import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge, Box, Dialog, HStack, Portal, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuCheckCheck, LuPencil } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { RowActionButton } from '@/shared/Panel/RowActions';
import { toastError } from '@/utils/toast';

/**
 * «Всё так же» — продлить актуальность ответа без изменений.
 */
export function confirmTaxRegime(contractorId) {
    router.post(route('crm.contractors.tax-regime.confirm', contractorId), {}, {
        preserveScroll: true,
        onError: (errors) => toastError(
            'Не удалось подтвердить налоговый режим',
            Object.values(errors)[0] || 'Попробуйте ещё раз.',
        ),
    });
}

/**
 * Почему ответ требует внимания — одной фразой, без которой бейдж
 * «Нужно подтвердить» ничего не объясняет.
 */
function attentionHint(regime, options) {
    if (regime.freshness.value === 'missing') {
        return regime.current
            ? `Клиент указал режим сейчас, но не план на ${regime.target_year} год — уточните.`
            : `Узнайте у партнёра, на какой системе налогообложения юрлицо работает сейчас и на какой будет в ${regime.target_year} году.`;
    }

    if (regime.freshness.value !== 'outdated') {
        return null;
    }

    if (!regime.can_confirm) {
        return `План был на ${regime.planned_year} год, и этот год наступил. Укажите режим сейчас и план на ${regime.target_year} год.`;
    }

    const days = regime.planned?.value === 'undecided'
        ? options?.undecided_confirm_days
        : options?.confirm_days;

    return `Ответу больше ${days} дней. Если ничего не изменилось — подтвердите, иначе обновите.`;
}

export function TaxRegimeFreshnessBadge({ regime }) {
    return (
        <Badge colorPalette={regime.freshness.color} variant="subtle">
            {regime.freshness.label}
            {regime.fresh_until && ` до ${regime.fresh_until}`}
        </Badge>
    );
}

export function TaxRegimeShiftBadge({ regime }) {
    if (!regime.shift) {
        return null;
    }

    return (
        <Badge colorPalette={regime.shift.color} variant={regime.shift.risk ? 'solid' : 'subtle'}>
            {regime.shift.label}
        </Badge>
    );
}

export function VatPreferenceBadge({ regime }) {
    if (!regime.vat_preference) {
        return null;
    }

    return (
        <Badge colorPalette={regime.vat_preference.color} variant="outline">
            {regime.vat_preference.short}
        </Badge>
    );
}

function SelectField({ label, value, placeholder, options, onChange }) {
    return (
        <Field label={label}>
            <NativeSelectRoot size="sm">
                <NativeSelectField value={value ?? ''} onChange={(e) => onChange(e.target.value || null)}>
                    <option value="">{placeholder}</option>
                    {options.map((option) => (
                        <option key={option.value} value={option.value}>{option.label}</option>
                    ))}
                </NativeSelectField>
            </NativeSelectRoot>
        </Field>
    );
}

/**
 * Форма ответа: режим сейчас, план на следующий год, важность НДС, комментарий.
 */
export function TaxRegimeDialog({ contractorId, contractorName, regime, options, open, onClose }) {
    const [form, setForm] = useState(regime?.form ?? {});
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setForm(regime?.form ?? {});
        }
    }, [open, regime]);

    if (!options || !contractorId) {
        return null;
    }

    const year = options.target_year;
    const ready = Boolean(form.current_regime && form.planned_regime);
    const set = (field) => (value) => setForm((prev) => ({ ...prev, [field]: value }));

    const submit = () => {
        setBusy(true);

        router.put(route('crm.contractors.tax-regime.update', contractorId), {
            current_regime: form.current_regime,
            planned_regime: form.planned_regime,
            vat_preference: form.vat_preference ?? null,
            note: form.note?.trim() || null,
        }, {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (errors) => toastError(
                'Не удалось сохранить налоговый режим',
                Object.values(errors)[0] || 'Попробуйте ещё раз.',
            ),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onClose(); }} size="md">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>Налоговый режим: {contractorName}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                <Text fontSize="sm" color="fg.muted">
                                    Главное — будет ли юрлицо принимать НДС к вычету: такому покупателю нужен
                                    товар с НДС. Ответ действует {options.confirm_days} дней (если партнёр
                                    не решил — {options.undecided_confirm_days}) и в любом случае до 1 января
                                    {` ${year}`} года.
                                </Text>

                                <SelectField
                                    label="Сейчас"
                                    value={form.current_regime}
                                    placeholder="Выберите режим"
                                    options={options.current}
                                    onChange={set('current_regime')}
                                />

                                <SelectField
                                    label={`В ${year} году`}
                                    value={form.planned_regime}
                                    placeholder="Выберите режим"
                                    options={options.planned}
                                    onChange={set('planned_regime')}
                                />

                                <SelectField
                                    label="Насколько партнёру важен НДС в товаре"
                                    value={form.vat_preference}
                                    placeholder="Не выяснено"
                                    options={options.vat_preference}
                                    onChange={set('vat_preference')}
                                />

                                <Field
                                    label="Комментарий"
                                    helperText="Необязательно: со слов кого, от чего зависит решение, когда перезвонить."
                                >
                                    <Textarea
                                        rows={2}
                                        maxLength={500}
                                        value={form.note ?? ''}
                                        placeholder="Например: бухгалтер ждёт итогов года, решат в декабре"
                                        onChange={(e) => set('note')(e.target.value)}
                                    />
                                </Field>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Отмена</Button>
                                <Button colorPalette="blue" onClick={submit} loading={busy} disabled={!ready}>
                                    Сохранить
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/**
 * Блок «Налоговый режим» юрлица — в карточке контрагента и во вкладке юрлиц партнёра.
 *
 * Сбор ответов для оценки рисков перехода клиентов на НДС: режим сейчас,
 * план на следующий год, важность НДС и насколько ответ свежий. Ответ даёт
 * менеджер или сам клиент в опросе на сайте — источник виден в строке
 * подтверждения. Устаревший ответ подтверждается одной кнопкой.
 */
export default function ContractorTaxRegime({ contractorId, contractorName, regime, options, canEdit = false }) {
    const [editing, setEditing] = useState(false);

    if (!regime) {
        return null;
    }

    const hint = attentionHint(regime, options);
    const known = regime.current || regime.planned || regime.vat_preference;

    return (
        <Box>
            <HStack justify="space-between" align="start" gap={2} wrap="wrap">
                <HStack gap={2} wrap="wrap">
                    <Text fontSize="sm" fontWeight="600">Налоговый режим</Text>
                    <TaxRegimeFreshnessBadge regime={regime} />
                    <TaxRegimeShiftBadge regime={regime} />
                    <VatPreferenceBadge regime={regime} />
                </HStack>

                {canEdit && (
                    <HStack gap={1}>
                        {regime.can_confirm && regime.freshness.value !== 'fresh' && (
                            <Button size="xs" variant="outline" onClick={() => confirmTaxRegime(contractorId)}>
                                <LuCheckCheck /> Всё так же
                            </Button>
                        )}
                        <RowActionButton
                            size="xs"
                            icon={LuPencil}
                            label={known ? 'Изменить налоговый режим' : 'Заполнить налоговый режим'}
                            onClick={() => setEditing(true)}
                        />
                    </HStack>
                )}
            </HStack>

            {known && (
                <VStack align="stretch" gap={0.5} mt={1}>
                    <Text fontSize="sm">
                        <Text as="span" color="fg.muted">Сейчас: </Text>
                        {regime.current?.label ?? '—'}
                    </Text>
                    <Text fontSize="sm">
                        <Text as="span" color="fg.muted">
                            В {regime.planned_year ?? regime.target_year} году:{' '}
                        </Text>
                        {regime.planned?.label ?? '—'}
                    </Text>
                    {regime.vat_preference && (
                        <Text fontSize="sm">
                            <Text as="span" color="fg.muted">НДС в товаре: </Text>
                            {regime.vat_preference.label}
                        </Text>
                    )}
                    {(regime.confirmed_at || regime.source) && (
                        <Text fontSize="xs" color="fg.muted">
                            {regime.confirmed_at ? `Подтверждено ${regime.confirmed_at}` : 'Не подтверждено'}
                            {regime.confirmed_by && ` · ${regime.confirmed_by}`}
                            {regime.source && ` · ${regime.source.label}`}
                            {regime.note && ` · «${regime.note}»`}
                        </Text>
                    )}
                </VStack>
            )}

            {hint && (
                <Text fontSize="xs" color={regime.freshness.value === 'missing' ? 'red.600' : 'orange.600'} mt={1}>
                    {hint}
                </Text>
            )}

            <TaxRegimeDialog
                contractorId={contractorId}
                contractorName={contractorName}
                regime={regime}
                options={options}
                open={editing}
                onClose={() => setEditing(false)}
            />
        </Box>
    );
}
