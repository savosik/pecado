import { useState } from 'react';
import axios from 'axios';
import { Box, Dialog, HStack, Input, Portal, SimpleGrid, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field } from '@/components/ui/field';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Заголовок секции формы: тонкая линия с подписью, чтобы 14 полей читались
 * блоками «документ → стороны → подписание → срок», а не сплошной простынёй.
 */
function Section({ title, children }) {
    return (
        <Box>
            <Text fontSize="xs" fontWeight="semibold" textTransform="uppercase" letterSpacing="wide" color="fg.muted" mb={2}>
                {title}
            </Text>
            {children}
        </Box>
    );
}

/**
 * Форма договора — одна на создание и правку, открывается диалогом.
 *
 * Контрагент выбирается из базы; если юрлица в базе нет (иностранный
 * поставщик), сторону вписывают текстом. Партнёр подтягивается с контрагента
 * сам, выбирать его нужно только для договора без юрлица.
 */
export default function ContractForm({
    open,
    contract = null,
    categories = [],
    statuses = [],
    paymentTerms = [],
    forms = [],
    managers = [],
    initialCategoryId = null,
    initialCompany = null,
    initialClient = null,
    onSaved,
    onCancel,
}) {
    const [form, setForm] = useState(() => ({
        category_id: contract?.category?.id || initialCategoryId || categories[0]?.id || '',
        number: contract?.number || '',
        counterparty_name: contract?.company ? '' : (contract?.counterparty_name || ''),
        date: contract?.date_iso || '',
        signed_at: contract?.signed_at_iso || '',
        valid_from: contract?.valid_from_iso || '',
        valid_until: contract?.valid_until_iso || '',
        status: contract?.status || 'draft',
        payment_terms: contract?.payment_terms || '',
        form: contract?.form || '',
        responsible_manager_id: contract?.manager?.id || '',
        is_visible_in_cabinet: contract?.is_visible_in_cabinet ?? true,
        comment: contract?.comment || '',
    }));
    const [company, setCompany] = useState(() => (
        contract?.company
            ? { id: contract.company.id, label: contract.company.name }
            : (initialCompany ? { id: initialCompany.id, label: initialCompany.name } : null)
    ));
    const [client, setClient] = useState(() => (
        contract?.partner
            ? { id: contract.partner.id, label: contract.partner.name }
            : (initialClient ? { id: initialClient.id, label: initialClient.name } : null)
    ));
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);

    const patch = (changes) => setForm((prev) => ({ ...prev, ...changes }));
    const errorOf = (key) => (errors[key] ? errors[key][0] : null);

    const save = async () => {
        setSaving(true);
        setErrors({});

        const payload = {
            ...form,
            category_id: form.category_id || null,
            company_id: company?.id || null,
            client_id: client?.id || null,
            counterparty_name: company ? null : (form.counterparty_name || null),
            date: form.date || null,
            signed_at: form.signed_at || null,
            valid_from: form.valid_from || null,
            valid_until: form.valid_until || null,
            payment_terms: form.payment_terms || null,
            form: form.form || null,
            responsible_manager_id: form.responsible_manager_id || null,
        };

        try {
            const res = contract
                ? await axios.patch(route('crm.contracts.update', contract.id), payload)
                : await axios.post(route('crm.contracts.store'), payload);

            toastSuccess(contract ? 'Договор обновлён' : 'Договор заведён');
            onSaved?.(res.data);
        } catch (e) {
            if (e.response?.status === 422) {
                setErrors(e.response.data.errors || {});
                toastError('Проверьте поля формы');
            } else {
                toastError('Не удалось сохранить договор');
            }
        } finally {
            setSaving(false);
        }
    };

    const field = (key, label, control, extra = {}) => (
        <Field label={label} invalid={!!errorOf(key)} errorText={errorOf(key)} {...extra}>
            {control}
        </Field>
    );

    const select = (key, options, placeholder = null) => (
        <NativeSelectRoot size="sm">
            <NativeSelectField value={form[key]} onChange={(e) => patch({ [key]: e.target.value })}>
                {placeholder !== null && <option value="">{placeholder}</option>}
                {options}
            </NativeSelectField>
        </NativeSelectRoot>
    );

    const dateInput = (key) => (
        <Input size="sm" type="date" value={form[key]} onChange={(e) => patch({ [key]: e.target.value })} />
    );

    const renderEntity = (item) => (
        <Box>
            <Text fontSize="sm">{item.label}</Text>
            {item.sublabel && <Text fontSize="xs" color="fg.muted">{item.sublabel}</Text>}
        </Box>
    );

    return (
        <Dialog.Root open={open} onOpenChange={(e) => { if (!e.open) onCancel?.(); }} size="lg" scrollBehavior="inside">
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content maxW="720px">
                        <Dialog.Header>
                            <Dialog.Title>{contract ? `Договор ${contract.number}` : 'Новый договор'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={5}>
                                <Section title="Документ">
                                    <SimpleGrid columns={{ base: 1, sm: 2 }} gap={3}>
                                        {field('number', 'Номер договора', (
                                            <Input size="sm" value={form.number} onChange={(e) => patch({ number: e.target.value })} placeholder="№ 12-Т/2024" autoFocus />
                                        ), { required: true })}
                                        {field('date', 'Дата договора', dateInput('date'))}
                                        {field('category_id', 'Категория', select('category_id', categories.map((item) => (
                                            <option key={item.id} value={item.id}>{item.name}{item.is_active === false ? ' (отключена)' : ''}</option>
                                        ))))}
                                        {field('responsible_manager_id', 'Ответственный', select(
                                            'responsible_manager_id',
                                            managers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>),
                                            'Не назначен',
                                        ))}
                                    </SimpleGrid>
                                </Section>

                                <Section title="Стороны">
                                    <VStack align="stretch" gap={3}>
                                        {field('company_id', 'Контрагент', (
                                            <EntitySelector
                                                value={company}
                                                onChange={setCompany}
                                                searchUrl={route('crm.contracts.entities')}
                                                searchParams={{ type: 'contractor' }}
                                                placeholder="Юрлицо из базы: название, ИНН…"
                                                renderItem={renderEntity}
                                            />
                                        ), { helperText: company ? 'Партнёр подтянется с контрагента' : null })}
                                        {!company && (
                                            <SimpleGrid columns={{ base: 1, sm: 2 }} gap={3}>
                                                {field('counterparty_name', 'Или сторона текстом', (
                                                    <Input
                                                        size="sm"
                                                        value={form.counterparty_name}
                                                        onChange={(e) => patch({ counterparty_name: e.target.value })}
                                                        placeholder="Loma Inc., Андрей-К ТОО…"
                                                    />
                                                ), { helperText: 'Если юрлица нет в базе' })}
                                                {field('client_id', 'Партнёр', (
                                                    <EntitySelector
                                                        value={client}
                                                        onChange={setClient}
                                                        searchUrl={route('crm.contracts.entities')}
                                                        searchParams={{ type: 'client' }}
                                                        placeholder="Имя, почта, телефон…"
                                                        renderItem={renderEntity}
                                                    />
                                                ))}
                                            </SimpleGrid>
                                        )}
                                    </VStack>
                                </Section>

                                <Section title="Подписание и оплата">
                                    <SimpleGrid columns={{ base: 1, sm: 2 }} gap={3}>
                                        {field('status', 'Статус', select('status', statuses.map((item) => (
                                            <option key={item.value} value={item.value}>{item.label}</option>
                                        ))))}
                                        {field('signed_at', 'Дата подписания', dateInput('signed_at'))}
                                        {field('payment_terms', 'Вариант оплаты', select(
                                            'payment_terms',
                                            paymentTerms.map((item) => <option key={item.value} value={item.value}>{item.label}</option>),
                                            'Не указан',
                                        ))}
                                        {field('form', 'Форма', select(
                                            'form',
                                            forms.map((item) => <option key={item.value} value={item.value}>{item.label}</option>),
                                            'Не указана',
                                        ))}
                                    </SimpleGrid>
                                </Section>

                                <Section title="Срок действия">
                                    <SimpleGrid columns={{ base: 1, sm: 2 }} gap={3}>
                                        {field('valid_from', 'Действует с', dateInput('valid_from'))}
                                        {field('valid_until', 'Действует до', dateInput('valid_until'), { helperText: 'Пусто — бессрочный' })}
                                    </SimpleGrid>
                                </Section>

                                <VStack align="stretch" gap={3}>
                                    {field('comment', 'Комментарий', (
                                        <Textarea
                                            size="sm"
                                            rows={2}
                                            value={form.comment}
                                            onChange={(e) => patch({ comment: e.target.value })}
                                            placeholder="Не работает ЭДО, организация закрыта…"
                                        />
                                    ))}
                                    <Checkbox
                                        size="sm"
                                        checked={!!form.is_visible_in_cabinet}
                                        onCheckedChange={(e) => patch({ is_visible_in_cabinet: !!e.checked })}
                                    >
                                        Показывать партнёру в личном кабинете
                                    </Checkbox>
                                </VStack>
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button size="sm" variant="ghost" onClick={onCancel} disabled={saving}>Отмена</Button>
                                <Button size="sm" onClick={save} loading={saving}>
                                    {contract ? 'Сохранить' : 'Завести договор'}
                                </Button>
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
