import { useState } from 'react';
import axios from 'axios';
import { Box, HStack, Input, SimpleGrid, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
import { toastError, toastSuccess } from '@/utils/toast';

const controlStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    width: '100%',
};

/**
 * Форма договора — одна на создание и правку.
 *
 * Контрагент выбирается из базы; если юрлица в базе нет (иностранный
 * поставщик), сторону вписывают текстом. Партнёр подтягивается с контрагента
 * сам, выбирать его нужно только для договора без юрлица.
 */
export default function ContractForm({
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

    const field = (key, label, control) => (
        <Box>
            <Text fontSize="xs" color="fg.muted" mb={1}>{label}</Text>
            {control}
            {errorOf(key) && <Text fontSize="xs" color="red.500" mt={1}>{errorOf(key)}</Text>}
        </Box>
    );

    const renderEntity = (item) => (
        <Box>
            <Text fontSize="sm">{item.label}</Text>
            {item.sublabel && <Text fontSize="xs" color="fg.muted">{item.sublabel}</Text>}
        </Box>
    );

    return (
        <VStack align="stretch" gap={4}>
            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                {field('category_id', 'Категория (вкладка)', (
                    <select
                        value={form.category_id}
                        onChange={(e) => patch({ category_id: e.target.value })}
                        style={controlStyle}
                    >
                        {categories.map((item) => (
                            <option key={item.id} value={item.id}>{item.name}{item.is_active === false ? ' (отключена)' : ''}</option>
                        ))}
                    </select>
                ))}
                {field('number', 'Номер договора', (
                    <Input size="sm" value={form.number} onChange={(e) => patch({ number: e.target.value })} placeholder="№ 12-Т/2024" />
                ))}
                {field('date', 'Дата договора', (
                    <Input size="sm" type="date" value={form.date} onChange={(e) => patch({ date: e.target.value })} />
                ))}
            </SimpleGrid>

            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                {field('company_id', 'Контрагент (юрлицо из базы)', (
                    <EntitySelector
                        value={company}
                        onChange={setCompany}
                        searchUrl={route('crm.contracts.entities')}
                        searchParams={{ type: 'contractor' }}
                        placeholder="Название, ИНН…"
                        renderItem={renderEntity}
                    />
                ))}
                {company
                    ? field('client_id', 'Партнёр', (
                        <Text fontSize="sm" py={2} color="fg.muted">Подтянется с контрагента</Text>
                    ))
                    : field('client_id', 'Партнёр (если договор без юрлица)', (
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

            {!company && field('counterparty_name', 'Название стороны текстом (если юрлица нет в базе)', (
                <Input
                    size="sm"
                    value={form.counterparty_name}
                    onChange={(e) => patch({ counterparty_name: e.target.value })}
                    placeholder="Loma Inc., Андрей-К ТОО…"
                />
            ))}

            <SimpleGrid columns={{ base: 1, md: 4 }} gap={3}>
                {field('status', 'Статус подписания', (
                    <select value={form.status} onChange={(e) => patch({ status: e.target.value })} style={controlStyle}>
                        {statuses.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>
                ))}
                {field('signed_at', 'Дата подписания', (
                    <Input size="sm" type="date" value={form.signed_at} onChange={(e) => patch({ signed_at: e.target.value })} />
                ))}
                {field('payment_terms', 'Вариант оплаты', (
                    <select value={form.payment_terms} onChange={(e) => patch({ payment_terms: e.target.value })} style={controlStyle}>
                        <option value="">Не указан</option>
                        {paymentTerms.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>
                ))}
                {field('form', 'Форма (скан / оригинал / ЭДО)', (
                    <select value={form.form} onChange={(e) => patch({ form: e.target.value })} style={controlStyle}>
                        <option value="">Не указана</option>
                        {forms.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>
                ))}
            </SimpleGrid>

            <SimpleGrid columns={{ base: 1, md: 3 }} gap={3}>
                {field('valid_from', 'Действует с', (
                    <Input size="sm" type="date" value={form.valid_from} onChange={(e) => patch({ valid_from: e.target.value })} />
                ))}
                {field('valid_until', 'Действует до (пусто — бессрочный)', (
                    <Input size="sm" type="date" value={form.valid_until} onChange={(e) => patch({ valid_until: e.target.value })} />
                ))}
                {field('responsible_manager_id', 'Ответственный менеджер', (
                    <select
                        value={form.responsible_manager_id}
                        onChange={(e) => patch({ responsible_manager_id: e.target.value })}
                        style={controlStyle}
                    >
                        <option value="">Не назначен</option>
                        {managers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
                    </select>
                ))}
            </SimpleGrid>

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
                checked={!!form.is_visible_in_cabinet}
                onCheckedChange={(e) => patch({ is_visible_in_cabinet: !!e.checked })}
            >
                Показывать партнёру в личном кабинете
            </Checkbox>

            <HStack gap={2} justify="flex-end">
                {onCancel && <Button size="sm" variant="ghost" onClick={onCancel} disabled={saving}>Отмена</Button>}
                <Button size="sm" onClick={save} loading={saving}>
                    {contract ? 'Сохранить' : 'Завести договор'}
                </Button>
            </HStack>
        </VStack>
    );
}
