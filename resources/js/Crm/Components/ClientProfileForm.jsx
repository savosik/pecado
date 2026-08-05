import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import {
    Box,
    HStack,
    Input,
    NativeSelectField,
    NativeSelectRoot,
    SimpleGrid,
    Text,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { TagSelector } from '@/Admin/Components/TagSelector';
import { MarkdownTextEditor } from '@/Admin/Components/Editor/MarkdownTextEditor';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import VoiceButton from '@/shared/voice/VoiceButton';
import { toastSuccess } from '@/utils/toast';

/**
 * Профиль клиента: анкета + паспорт бизнеса + свободные заметки.
 *
 * Гибрид не случаен: только поля — менеджер не запишет нестандартное; только текст —
 * нельзя спросить «покажи всех, кто платит по предоплате».
 *
 * Секции паспорта приходят с бэкенда (`ClientPassport::sections()`) и рисуются циклом,
 * а не перечислены здесь руками: три десятка полей, продублированные в JSX, разошлись бы
 * с правилами проверки на первой же правке.
 *
 * @param {number} clientId
 * @param {object} profile — payload из ClientController::profilePayload()
 * @param {object} options — списки значений енумов с русскими подписями
 * @param {object} passportSections — секции паспорта: подписи, подсказки, типы полей
 * @param {boolean} canEdit — право crm-profile.edit
 */
export default function ClientProfileForm({ clientId, profile, options, passportSections, canEdit }) {
    const [historyOpen, setHistoryOpen] = useState(false);

    const sections = passportSections || {};
    const passportFields = Object.values(sections).flatMap((section) => section.fields);

    const { data, setData, put, processing, errors, isDirty } = useForm({
        decision_maker_name: profile.decision_maker_name || '',
        decision_maker_role: profile.decision_maker_role || '',
        decision_maker_contact: profile.decision_maker_contact || '',
        decision_process: profile.decision_process || '',
        payment_behavior: profile.payment_behavior || '',
        payment_terms: profile.payment_terms || '',
        order_cycle_days: profile.order_cycle_days ?? '',
        preferred_channel: profile.preferred_channel || '',
        sentiment: profile.sentiment || '',
        notes_md: profile.notes_md || '',
        interests: profile.interests || [],
        ...Object.fromEntries(passportFields.map((field) => [field.key, profile[field.key] ?? ''])),
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('crm.clients.profile.update', clientId), {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Профиль сохранён'),
        });
    };

    if (!canEdit) {
        return <ProfileReadOnly profile={profile} sections={sections} />;
    }

    return (
        <form onSubmit={submit}>
            <VStack align="stretch" gap={6}>
                <Section title="Кто принимает решения">
                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                        <Field label="Имя" errorText={errors.decision_maker_name} invalid={!!errors.decision_maker_name}>
                            <Input
                                value={data.decision_maker_name}
                                onChange={(e) => setData('decision_maker_name', e.target.value)}
                                placeholder="Кто решает по закупке"
                            />
                        </Field>
                        <Field label="Должность или роль" errorText={errors.decision_maker_role} invalid={!!errors.decision_maker_role}>
                            <Input
                                value={data.decision_maker_role}
                                onChange={(e) => setData('decision_maker_role', e.target.value)}
                                placeholder="Например: закупщик, владелец"
                            />
                        </Field>
                        <Field label="Контакт" errorText={errors.decision_maker_contact} invalid={!!errors.decision_maker_contact}>
                            <Input
                                value={data.decision_maker_contact}
                                onChange={(e) => setData('decision_maker_contact', e.target.value)}
                                placeholder="Телефон, почта или мессенджер"
                            />
                        </Field>
                    </SimpleGrid>
                    <Field
                        label="Как принимается решение"
                        helperText="Кто согласует, сколько обычно ждать, что важно на встрече"
                        errorText={errors.decision_process}
                        invalid={!!errors.decision_process}
                    >
                        <VoiceTextarea
                            value={data.decision_process}
                            onChange={(value) => setData('decision_process', value)}
                            rows={3}
                        />
                    </Field>
                </Section>

                <Section title="Как платит">
                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                        <Field label="Платёжное поведение" errorText={errors.payment_behavior} invalid={!!errors.payment_behavior}>
                            <EnumSelect
                                value={data.payment_behavior}
                                onChange={(v) => setData('payment_behavior', v)}
                                items={options.payment_behavior}
                            />
                        </Field>
                        <Field label="Условия словами" errorText={errors.payment_terms} invalid={!!errors.payment_terms}>
                            <Input
                                value={data.payment_terms}
                                onChange={(e) => setData('payment_terms', e.target.value)}
                                placeholder="Например: отсрочка 14 дней"
                            />
                        </Field>
                        <Field
                            label="Периодичность закупок, дней"
                            helperText="Через сколько дней обычно возвращается"
                            errorText={errors.order_cycle_days}
                            invalid={!!errors.order_cycle_days}
                        >
                            <Input
                                type="number"
                                min={1}
                                value={data.order_cycle_days}
                                onChange={(e) => setData('order_cycle_days', e.target.value)}
                            />
                        </Field>
                    </SimpleGrid>
                </Section>

                <Section title="Как общаться">
                    <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                        <Field label="Канал связи" errorText={errors.preferred_channel} invalid={!!errors.preferred_channel}>
                            <EnumSelect
                                value={data.preferred_channel}
                                onChange={(v) => setData('preferred_channel', v)}
                                items={options.preferred_channel}
                            />
                        </Field>
                        <Field label="Настроение" errorText={errors.sentiment} invalid={!!errors.sentiment}>
                            <EnumSelect
                                value={data.sentiment}
                                onChange={(v) => setData('sentiment', v)}
                                items={options.sentiment}
                            />
                        </Field>
                        <Field
                            label="Интересы"
                            helperText="Бренды, категории, темы — по ним потом ищем, кому предложить"
                            errorText={errors.interests}
                            invalid={!!errors.interests}
                        >
                            <TagSelector
                                value={data.interests}
                                onChange={(v) => setData('interests', v)}
                                suggestUrl={route('crm.interests.search')}
                                placeholder="Введите интерес и нажмите Enter…"
                            />
                        </Field>
                    </SimpleGrid>
                </Section>

                {Object.entries(sections).map(([key, section]) => (
                    <Section key={key} title={section.label}>
                        <SimpleGrid columns={{ base: 1, md: 3 }} gap={4}>
                            {section.fields.map((field) => (
                                <PassportField
                                    key={field.key}
                                    field={field}
                                    value={data[field.key]}
                                    onChange={(value) => setData(field.key, value)}
                                    error={errors[field.key]}
                                    items={options[field.key]}
                                />
                            ))}
                        </SimpleGrid>
                    </Section>
                ))}

                <Section title="Заметки">
                    {/* Микрофон рядом с редактором, а не внутри: markdown-редактор
                        рисует свою панель инструментов, и кнопка поверх неё легла бы
                        на кнопку разметки. Надиктованное дописывается в конец. */}
                    <HStack justify="flex-end">
                        <VoiceButton
                            size="sm"
                            title="Надиктовать заметку"
                            onAppend={(text) => setData(
                                'notes_md',
                                data.notes_md ? `${data.notes_md.trimEnd()} ${text}` : text,
                            )}
                        />
                    </HStack>
                    <MarkdownTextEditor
                        value={data.notes_md}
                        onChange={(v) => setData('notes_md', v)}
                        placeholder="Всё, что не влезло в поля: договорённости, история отношений, чего избегать…"
                        minHeight={260}
                    />
                    {errors.notes_md && <Text fontSize="sm" color="red.500">{errors.notes_md}</Text>}

                    {profile.notes_updated_at && (
                        <Text fontSize="xs" color="fg.muted">
                            Последняя правка: {profile.notes_updated_by || 'сотрудник'}, {profile.notes_updated_at}
                        </Text>
                    )}

                    <RevisionHistory
                        revisions={profile.revisions}
                        open={historyOpen}
                        onToggle={() => setHistoryOpen((v) => !v)}
                    />
                </Section>

                <HStack>
                    <Button type="submit" loading={processing} disabled={!isDirty}>
                        Сохранить профиль
                    </Button>
                    {isDirty && <Text fontSize="xs" color="fg.muted">Есть несохранённые изменения</Text>}
                    {profile.passport_completeness && (
                        <Text fontSize="xs" color="fg.muted" ml="auto">
                            Паспорт заполнен на {profile.passport_completeness.percent}%
                            {' '}({profile.passport_completeness.filled} из {profile.passport_completeness.total} полей)
                        </Text>
                    )}
                </HStack>
            </VStack>
        </form>
    );
}

function Section({ title, children }) {
    return (
        <VStack align="stretch" gap={3}>
            <Text fontWeight="600" fontSize="sm" color="fg.muted" textTransform="uppercase" letterSpacing="wide">
                {title}
            </Text>
            {children}
        </VStack>
    );
}

/**
 * Одно поле паспорта. Тип приходит с бэкенда вместе с подписью, поэтому новое поле
 * появляется в форме само — без правки этого файла.
 */
function PassportField({ field, value, onChange, error, items }) {
    const common = { value: value ?? '', onChange: (e) => onChange(e.target.value) };

    return (
        <Field
            label={field.label}
            helperText={field.hint}
            errorText={error}
            invalid={!!error}
            gridColumn={field.type === 'text' ? { md: 'span 3' } : undefined}
        >
            {field.type === 'enum' && (
                <EnumSelect value={value ?? ''} onChange={onChange} items={items || []} />
            )}
            {field.type === 'integer' && <Input type="number" min={0} {...common} />}
            {field.type === 'date' && <Input type="date" {...common} />}
            {field.type === 'string' && <Input {...common} />}
            {field.type === 'text' && (
                <VoiceTextarea value={value ?? ''} onChange={onChange} rows={2} />
            )}
        </Field>
    );
}

function EnumSelect({ value, onChange, items }) {
    return (
        <NativeSelectRoot>
            <NativeSelectField
                value={value}
                onChange={(e) => onChange(e.target.value)}
            >
                <option value="">Не указано</option>
                {items.map((item) => (
                    <option key={item.value} value={item.value}>{item.label}</option>
                ))}
            </NativeSelectField>
        </NativeSelectRoot>
    );
}

function RevisionHistory({ revisions, open, onToggle }) {
    if (!revisions?.length) {
        return null;
    }

    return (
        <Box>
            <Button size="xs" variant="ghost" onClick={onToggle}>
                {open ? 'Скрыть историю правок' : `История правок (${revisions.length})`}
            </Button>

            {open && (
                <VStack align="stretch" gap={2} mt={2}>
                    {revisions.map((revision) => (
                        <Box key={revision.id} borderWidth="1px" borderRadius="md" p={2}>
                            <Text fontSize="xs" color="fg.muted" mb={1}>
                                До правки {revision.author}, {revision.created_at}
                            </Text>
                            <Text fontSize="sm" whiteSpace="pre-wrap">{revision.notes_md || '— заметок не было —'}</Text>
                        </Box>
                    ))}
                </VStack>
            )}
        </Box>
    );
}

/**
 * Без права на правку профиль показывается как справка: сотруднику с одним
 * `crm-profile.view` форма только мешала бы.
 */
function ProfileReadOnly({ profile, sections }) {
    const passportRows = Object.values(sections || {}).flatMap((section) =>
        section.fields.map((field) => [
            field.label,
            // У енумов показываем русскую подпись, а не машинное значение.
            profile.passport_labels?.[field.key] ?? profile[field.key],
        ]),
    );

    const rows = [
        ['ЛПР', profile.decision_maker_name],
        ['Должность ЛПР', profile.decision_maker_role],
        ['Контакт ЛПР', profile.decision_maker_contact],
        ['Как принимается решение', profile.decision_process],
        ['Платёжное поведение', profile.payment_behavior_label],
        ['Условия оплаты', profile.payment_terms],
        ['Периодичность закупок, дней', profile.order_cycle_days],
        ['Настроение', profile.sentiment_label],
        ['Интересы', profile.interests?.join(', ')],
        ...passportRows,
    ];

    return (
        <VStack align="stretch" gap={3}>
            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                {rows.map(([label, value]) => (
                    <Box key={label}>
                        <Text fontSize="xs" color="fg.muted">{label}</Text>
                        <Text fontSize="sm">{value || '—'}</Text>
                    </Box>
                ))}
            </SimpleGrid>
            <Box>
                <Text fontSize="xs" color="fg.muted" mb={1}>Заметки</Text>
                <Text fontSize="sm" whiteSpace="pre-wrap">{profile.notes_md || '—'}</Text>
            </Box>
        </VStack>
    );
}
