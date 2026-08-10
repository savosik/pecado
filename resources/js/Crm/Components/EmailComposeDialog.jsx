import { useEffect, useState } from 'react';
import axios from 'axios';
import DOMPurify from 'dompurify';
import {
    Box,
    Dialog,
    HStack,
    Input,
    NativeSelectField,
    NativeSelectRoot,
    Portal,
    Text,
    VStack,
} from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { Field } from '@/components/ui/field';
import { Alert } from '@/components/ui/alert';
import SimpleWysiwyg from '@/Admin/Components/SimpleWysiwyg';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import { usePermission } from '@/shared/Panel/usePermission';
import { toastError, toastSuccess } from '@/utils/toast';

const EMPTY = { to: '', cc: '', subject: '', body_html: '' };

/**
 * Составление письма партнёру.
 *
 * Письмо сохраняется черновиком до отправки — иначе к нему нельзя приложить файл:
 * MediaService не умеет загрузку «в никуда», вложение нужно вешать на сохранённую запись.
 *
 * @param {object|null} email — правим черновик или пишем новое
 * @param {{type: string, id: number}|null} entity — привязка нового письма
 * @param {string|null} defaultTo — адрес, подставляемый в поле «Кому»
 */
export default function EmailComposeDialog({ open, onClose, email = null, entity = null, defaultTo = null, onSaved }) {
    const { can } = usePermission();
    const [form, setForm] = useState(EMPTY);
    const [draft, setDraft] = useState(email);
    const [options, setOptions] = useState(null);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);
    const [preview, setPreview] = useState(false);

    const readOnly = draft ? !draft.can?.update : false;

    useEffect(() => {
        if (!open) {
            return;
        }

        setErrors({});
        setPreview(false);
        setDraft(email);
        setForm(email
            ? {
                to: (email.to || []).join(', '),
                cc: (email.cc || []).join(', '),
                subject: email.subject || '',
                body_html: email.body_html || '',
            }
            : { ...EMPTY, to: defaultTo || '' });

        axios.get('/crm/emails/options').then((res) => setOptions(res.data)).catch(() => {});
    }, [open, email, defaultTo]);

    const set = (field, value) => setForm((prev) => ({ ...prev, [field]: value }));

    const addresses = (raw) => raw.split(/[,;\s]+/).map((item) => item.trim()).filter(Boolean);

    const applyTemplate = async (templateId) => {
        if (!templateId) {
            return;
        }

        try {
            const res = await axios.get(`/crm/email-templates/${templateId}`, {
                params: { client_id: entity?.type === 'client' ? entity.id : draft?.client_id },
            });
            setForm((prev) => ({ ...prev, subject: res.data.subject, body_html: res.data.body_html }));
        } catch {
            toastError('Заготовка не подставилась', 'Попробуйте ещё раз.');
        }
    };

    /**
     * Сохранить черновик. Возвращает сохранённую запись — она нужна и для вложений,
     * и для отправки.
     */
    const save = async () => {
        setBusy(true);
        setErrors({});

        try {
            const payload = {
                to: addresses(form.to),
                cc: addresses(form.cc),
                subject: form.subject,
                body_html: form.body_html,
            };

            let res;

            if (draft) {
                res = await axios.patch(`/crm/emails/${draft.id}`, payload);
            } else {
                if (entity) {
                    payload.entity_type = entity.type;
                    payload.entity_id = entity.id;
                }
                res = await axios.post('/crm/emails', payload);
            }

            setDraft(res.data);
            onSaved?.(res.data);
            toastSuccess('Черновик сохранён');

            return res.data;
        } catch (e) {
            if (e?.response?.status === 422) {
                setErrors(e.response.data.errors || {});
            } else {
                toastError('Черновик не сохранён', e?.response?.data?.message || 'Попробуйте ещё раз.');
            }

            return null;
        } finally {
            setBusy(false);
        }
    };

    const send = async () => {
        const saved = await save();

        if (!saved) {
            return;
        }

        setBusy(true);
        try {
            const res = await axios.post(`/crm/emails/${saved.id}/send`);
            toastSuccess('Письмо отправлено');
            onSaved?.(res.data);
            onClose();
        } catch (e) {
            toastError('Письмо не отправлено', e?.response?.data?.message || 'Попробуйте ещё раз.');
        } finally {
            setBusy(false);
        }
    };

    const error = (field) => errors[field]?.[0] || errors[`${field}.0`]?.[0];
    const outboundEnabled = options?.outbound_enabled ?? false;

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && onClose()}
            size="xl"
            scrollBehavior="inside"
            closeOnInteractOutside={false}
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content>
                        <Dialog.Header>
                            <Dialog.Title>{draft ? 'Письмо' : 'Новое письмо'}</Dialog.Title>
                        </Dialog.Header>

                        <Dialog.Body>
                            <VStack align="stretch" gap={4}>
                                {!outboundEnabled && (
                                    <Alert status="warning" title="Отправка писем выключена">
                                        Составить и сохранить письмо можно, но отправка заблокирована администратором.
                                    </Alert>
                                )}

                                {draft?.status === 'failed' && (
                                    <Alert status="error" title="Прошлая отправка не удалась">
                                        {draft.error}
                                    </Alert>
                                )}

                                {readOnly ? (
                                    <SentEmailView email={draft} />
                                ) : (
                                    <>
                                        <Field label="Кому" required errorText={error('to')} invalid={!!error('to')}>
                                            <Input
                                                value={form.to}
                                                onChange={(e) => set('to', e.target.value)}
                                                placeholder="Несколько адресов — через запятую"
                                            />
                                        </Field>

                                        <Field label="Копия" errorText={error('cc')} invalid={!!error('cc')}>
                                            <Input
                                                value={form.cc}
                                                onChange={(e) => set('cc', e.target.value)}
                                                placeholder="Необязательно"
                                            />
                                        </Field>

                                        {options?.templates?.length > 0 && (
                                            <Field label="Заготовка" helperText="Подставит тему и текст, их можно править">
                                                <NativeSelectRoot>
                                                    <NativeSelectField
                                                        defaultValue=""
                                                        onChange={(e) => applyTemplate(e.target.value)}
                                                    >
                                                        <option value="">Без заготовки</option>
                                                        {options.templates.map((template) => (
                                                            <option key={template.id} value={template.id}>{template.name}</option>
                                                        ))}
                                                    </NativeSelectField>
                                                </NativeSelectRoot>
                                            </Field>
                                        )}

                                        <Field label="Тема" required errorText={error('subject')} invalid={!!error('subject')}>
                                            <Input
                                                value={form.subject}
                                                onChange={(e) => set('subject', e.target.value)}
                                            />
                                        </Field>

                                        <Field label="Письмо" required errorText={error('body_html')} invalid={!!error('body_html')}>
                                            {preview
                                                ? <EmailPreview html={form.body_html} />
                                                : (
                                                    <SimpleWysiwyg
                                                        value={form.body_html}
                                                        onChange={(html) => set('body_html', html)}
                                                        placeholder="Текст письма"
                                                        minHeight="220px"
                                                    />
                                                )}
                                        </Field>

                                        <HStack justify="space-between" flexWrap="wrap" gap={2}>
                                            <Text fontSize="xs" color="fg.muted">
                                                Ответ партнёра придёт на {options?.reply_to || 'вашу почту'}.
                                            </Text>
                                            <Button size="xs" variant="ghost" onClick={() => setPreview((v) => !v)}>
                                                {preview ? 'Вернуться к редактированию' : 'Предпросмотр'}
                                            </Button>
                                        </HStack>
                                    </>
                                )}

                                {/* Вложения — только у сохранённого письма: прикрепить файл
                                    к несуществующей записи нельзя. */}
                                {draft && can('crm-attachments.view') && (
                                    <Box pt={2} borderTopWidth="1px">
                                        <AttachmentPanel
                                            entityType="email"
                                            entityId={draft.id}
                                            canUpload={!readOnly && can('crm-attachments.create')}
                                            label="Вложения письма"
                                        />
                                    </Box>
                                )}

                                {!draft && (
                                    <Text fontSize="xs" color="fg.muted">
                                        Чтобы приложить файл, сохраните черновик.
                                    </Text>
                                )}
                            </VStack>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <HStack gap={2}>
                                <Button variant="outline" onClick={onClose} disabled={busy}>Закрыть</Button>
                                {!readOnly && (
                                    <>
                                        <Button variant="subtle" onClick={save} loading={busy}>
                                            Сохранить черновик
                                        </Button>
                                        <Button
                                            colorPalette="blue"
                                            onClick={send}
                                            loading={busy}
                                            disabled={!outboundEnabled}
                                            title={outboundEnabled ? undefined : 'Отправка выключена администратором'}
                                        >
                                            Отправить
                                        </Button>
                                    </>
                                )}
                            </HStack>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/**
 * Показ HTML письма.
 *
 * Через dompurify: тело письма — произвольный HTML, и вставлять его в нашу страницу
 * без очистки означало бы получить XSS в собственной CRM.
 */
export function EmailPreview({ html }) {
    return (
        <Box
            borderWidth="1px"
            borderRadius="md"
            p={4}
            bg="bg.subtle"
            maxH="400px"
            overflowY="auto"
            css={{ '& img': { maxWidth: '100%' } }}
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(html || '') }}
        />
    );
}

function SentEmailView({ email }) {
    return (
        <VStack align="stretch" gap={3}>
            <Text fontSize="sm"><b>Кому:</b> {(email.to || []).join(', ')}</Text>
            {email.cc?.length > 0 && <Text fontSize="sm"><b>Копия:</b> {email.cc.join(', ')}</Text>}
            <Text fontSize="sm"><b>Тема:</b> {email.subject}</Text>
            {email.sent_at_label && <Text fontSize="sm"><b>Отправлено:</b> {email.sent_at_label}</Text>}
            {email.message_id && (
                <Text fontSize="xs" color="fg.muted">Message-ID: {email.message_id}</Text>
            )}
            <EmailPreview html={email.body_html} />
        </VStack>
    );
}
