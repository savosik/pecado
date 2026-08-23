import { useState } from 'react';
import axios from 'axios';
import { Box, HStack, Input, SimpleGrid, Text, Textarea, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import ContactLinkPicker from '@/Crm/Components/ContactLinkPicker';
import { Checkbox } from '@/components/ui/checkbox';
import { toastError, toastSuccess } from '@/utils/toast';

const controlStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    width: '100%',
};

/**
 * Форма человека.
 *
 * Одна на создание и правку: поля те же, разница только в маршруте. Роль
 * и привязка здесь не задаются при правке — для них отдельный блок на карточке,
 * потому что «поправить телефон» и «сменить роль» это разные действия.
 */
export default function ContactForm({
    contact = null,
    channels = [],
    roles = [],
    linkableTypes = [],
    clientId = null,
    entity = null,
    onSaved,
    onCancel,
}) {
    const [form, setForm] = useState(() => ({
        full_name: contact?.full_name || '',
        greeting_name: contact?.greeting_name || '',
        position: contact?.position || '',
        email: contact?.email || '',
        phone: contact?.phone || '',
        phone_extra: contact?.phone_extra || '',
        telegram: contact?.telegram || '',
        whatsapp: contact?.whatsapp || '',
        instagram: contact?.instagram || '',
        website: contact?.website || '',
        preferred_channel: contact?.preferred_channel || '',
        birthday: contact?.birthday || '',
        birthday_has_year: contact?.birthday_has_year ?? true,
        is_active: contact?.is_active ?? true,
        notes: contact?.notes || '',
        role: roles[0]?.value || 'manager',
    }));
    const [errors, setErrors] = useState({});
    const [saving, setSaving] = useState(false);
    // Человек без привязки виден только автору, и заводить его так — почти всегда
    // недосмотр. Поэтому выбор «к кому» стоит прямо в форме создания.
    const [link, setLink] = useState(null);

    const patch = (changes) => setForm((prev) => ({ ...prev, ...changes }));
    const errorOf = (key) => (errors[key] ? errors[key][0] : null);

    const save = async () => {
        setSaving(true);
        setErrors({});

        const payload = {
            ...form,
            birthday: form.birthday || null,
            preferred_channel: form.preferred_channel || null,
            client_id: clientId || contact?.client?.id || null,
        };

        if (!contact && entity) {
            payload.entity_type = entity.type;
            payload.entity_id = entity.id;
        } else if (!contact && link) {
            payload.entity_type = link.entity_type;
            payload.entity_id = link.entity_id;
            payload.role = link.role;
        } else {
            delete payload.role;
        }

        try {
            const res = contact
                ? await axios.patch(route('crm.contacts.update', contact.id), payload)
                : await axios.post(route('crm.contacts.store'), payload);

            toastSuccess(contact ? 'Контакт обновлён' : 'Контакт заведён');
            onSaved?.(res.data);
        } catch (e) {
            setErrors(e?.response?.data?.errors || {});

            if (!e?.response?.data?.errors) {
                toastError('Не удалось сохранить', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }
        } finally {
            setSaving(false);
        }
    };

    const field = (key, label, props = {}) => (
        <Box>
            <Text fontSize="sm" fontWeight="600" mb={1}>{label}</Text>
            <Input
                value={form[key]}
                onChange={(e) => patch({ [key]: e.target.value })}
                size="sm"
                {...props}
            />
            {errorOf(key) && <Text fontSize="xs" color="red.500" mt={1}>{errorOf(key)}</Text>}
        </Box>
    );

    return (
        <VStack align="stretch" gap={4}>
            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                {field('full_name', 'ФИО', { placeholder: 'Афонина Мария Петровна' })}
                {field('greeting_name', 'Как обращаться', { placeholder: 'Мария Петровна' })}
                {field('position', 'Должность', { placeholder: 'Главный бухгалтер' })}
                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>Предпочитает</Text>
                    <select
                        value={form.preferred_channel}
                        onChange={(e) => patch({ preferred_channel: e.target.value })}
                        style={controlStyle}
                    >
                        <option value="">Не указано</option>
                        {channels.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>
                </Box>
            </SimpleGrid>

            <SimpleGrid columns={{ base: 1, md: 2 }} gap={3}>
                {field('phone', 'Телефон', { placeholder: '+7 912 345-67-89' })}
                {field('phone_extra', 'Ещё телефон')}
                {field('email', 'Почта', { placeholder: 'buh@romashka.ru' })}
                {field('telegram', 'Telegram', { placeholder: '@username' })}
                {field('whatsapp', 'WhatsApp')}
                {field('instagram', 'Instagram', { placeholder: '@username' })}
                {field('website', 'Сайт')}
                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>День рождения</Text>
                    <Input
                        type="date"
                        value={form.birthday}
                        onChange={(e) => patch({ birthday: e.target.value })}
                        size="sm"
                    />
                    <Checkbox
                        mt={2}
                        checked={!form.birthday_has_year}
                        onCheckedChange={(e) => patch({ birthday_has_year: !e.checked })}
                    >
                        Год неизвестен
                    </Checkbox>
                </Box>
            </SimpleGrid>

            {!contact && !entity && linkableTypes.length > 0 && (
                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>Кому приходится</Text>
                    <ContactLinkPicker
                        types={linkableTypes}
                        roles={roles}
                        compact
                        submitLabel={link ? 'Выбрано' : 'Выбрать'}
                        onSubmit={setLink}
                    />
                    <Text fontSize="xs" color="fg.muted" mt={1}>
                        Без привязки человек будет виден только вам. Привязать можно и позже,
                        с его карточки.
                    </Text>
                </Box>
            )}

            {!contact && entity && (
                <Box>
                    <Text fontSize="sm" fontWeight="600" mb={1}>Кем приходится</Text>
                    <select
                        value={form.role}
                        onChange={(e) => patch({ role: e.target.value })}
                        style={{ ...controlStyle, maxWidth: '260px' }}
                    >
                        {roles.map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>
                </Box>
            )}

            <Box>
                <Text fontSize="sm" fontWeight="600" mb={1}>Заметка</Text>
                <Textarea
                    value={form.notes}
                    onChange={(e) => patch({ notes: e.target.value })}
                    rows={2}
                    size="sm"
                />
                <Text fontSize="xs" color="fg.muted" mt={1}>
                    Партнёру в кабинете не видна.
                </Text>
            </Box>

            <Checkbox
                checked={form.is_active}
                onCheckedChange={(e) => patch({ is_active: !!e.checked })}
            >
                Работает — показывать в рабочих списках
            </Checkbox>

            <HStack gap={2}>
                <Button size="sm" onClick={save} loading={saving}>Сохранить</Button>
                {onCancel && <Button size="sm" variant="ghost" onClick={onCancel}>Отмена</Button>}
            </HStack>
        </VStack>
    );
}
