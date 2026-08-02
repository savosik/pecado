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
    Textarea,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { TagSelector } from '@/Admin/Components/TagSelector';
import { MarkdownTextEditor } from '@/Admin/Components/Editor/MarkdownTextEditor';
import { toastSuccess } from '@/utils/toast';

/**
 * Профиль клиента: анкета + свободные заметки.
 *
 * Гибрид не случаен: только поля — менеджер не запишет нестандартное; только текст —
 * нельзя спросить «покажи всех, кто платит по предоплате».
 *
 * @param {number} clientId
 * @param {object} profile — payload из ClientController::profilePayload()
 * @param {object} options — списки значений енумов с русскими подписями
 * @param {boolean} canEdit — право crm-profile.edit
 */
export default function ClientProfileForm({ clientId, profile, options, canEdit }) {
    const [historyOpen, setHistoryOpen] = useState(false);

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
    });

    const submit = (e) => {
        e.preventDefault();
        put(route('crm.clients.profile.update', clientId), {
            preserveScroll: true,
            onSuccess: () => toastSuccess('Профиль сохранён'),
        });
    };

    if (!canEdit) {
        return <ProfileReadOnly profile={profile} />;
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
                        <Textarea
                            value={data.decision_process}
                            onChange={(e) => setData('decision_process', e.target.value)}
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

                <Section title="Заметки">
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
function ProfileReadOnly({ profile }) {
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
