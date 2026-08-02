import { useCallback, useEffect, useState } from 'react';
import axios from 'axios';
import { Box, HStack, Spinner, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { FileUploader } from '@/Admin/Components/FileUploader';
import { ConfirmDialog } from '@/Admin/Components/ConfirmDialog';
import { toastError, toastSuccess } from '@/utils/toast';

const MAX_MB = 20;

const ACCEPTED = [
    'image/jpeg', 'image/png', 'image/webp', 'image/gif',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv', 'text/plain',
];

/**
 * Вложения сущности CRM.
 *
 * Загрузчик не свой — обёртка над `Admin/Components/FileUploader`: drag&drop, превью
 * и лимиты там уже есть. Импорт прямым путём, а не через бочку `@/Admin/Components`,
 * которая тянет EditorJS и tiptap.
 *
 * @param {string} entityType — 'client' | 'order' | 'shipment' | 'comment'
 * @param {number} entityId
 * @param {boolean} canUpload
 */
export default function AttachmentPanel({
    entityType,
    entityId,
    canUpload = true,
    label = 'Вложения',
    onCountChange,
}) {
    const [files, setFiles] = useState([]);
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [pendingDelete, setPendingDelete] = useState(null);

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await axios.get('/crm/attachments', {
                params: { entity_type: entityType, entity_id: entityId },
            });
            setFiles(res.data.data);
        } catch (e) {
            if (![403, 404].includes(e?.response?.status)) {
                toastError('Не удалось загрузить вложения', 'Попробуйте обновить страницу.');
            }
        } finally {
            setLoading(false);
        }
    }, [entityType, entityId]);

    useEffect(() => { load(); }, [load]);

    // Сообщаем владельцу актуальное число файлов — по нему рисуется бейдж скрепки.
    useEffect(() => {
        if (!loading) {
            onCountChange?.(files.length);
        }
    }, [files.length, loading, onCountChange]);

    // Файлы уходят по одному: так частичная неудача видна пофайлово,
    // а не отменяет всю пачку целиком.
    const upload = async (selected) => {
        if (!selected?.length) return;
        setBusy(true);

        for (const file of selected) {
            const form = new FormData();
            form.append('entity_type', entityType);
            form.append('entity_id', entityId);
            form.append('file', file);

            try {
                const res = await axios.post('/crm/attachments', form, {
                    headers: { 'Content-Type': 'multipart/form-data' },
                });
                setFiles((prev) => [...prev, res.data]);
            } catch (e) {
                const message = e?.response?.data?.message
                    || e?.response?.data?.errors?.file?.[0]
                    || 'Попробуйте ещё раз.';
                toastError(`Файл «${file.name}» не загружен`, message);
            }
        }

        setBusy(false);
    };

    const remove = async (id) => {
        setBusy(true);
        try {
            await axios.delete(`/crm/attachments/${id}`);
            setFiles((prev) => prev.filter((f) => f.id !== id));
            toastSuccess('Файл удалён');
        } catch (e) {
            const message = e?.response?.data?.message || 'Попробуйте ещё раз.';
            toastError('Не удалось удалить файл', message);
        } finally {
            setBusy(false);
        }
    };

    if (loading) {
        return <HStack justify="center" py={4}><Spinner size="sm" /></HStack>;
    }

    return (
        <VStack align="stretch" gap={3}>
            {canUpload ? (
                <FileUploader
                    name="crm-attachment"
                    value={[]}
                    onChange={upload}
                    existingFiles={files.map((f) => ({
                        id: f.id,
                        url: f.url,
                        name: f.file_name,
                        mime_type: f.mime_type,
                        size: f.size,
                    }))}
                    onRemoveExisting={(id) => {
                        const target = files.find((f) => f.id === id);
                        if (target?.can_delete) {
                            setPendingDelete(id);
                        } else {
                            toastError('Удалить нельзя', 'Файл загружен другим сотрудником.');
                        }
                    }}
                    label={label}
                    maxFiles={20}
                    maxSize={MAX_MB}
                    acceptedTypes={ACCEPTED}
                />
            ) : files.length === 0 ? (
                <Text fontSize="sm" color="fg.muted">Вложений нет.</Text>
            ) : (
                <VStack align="stretch" gap={1}>
                    {files.map((f) => (
                        <Box key={f.id} borderWidth="1px" borderRadius="md" p={2}>
                            <a href={f.url} target="_blank" rel="noreferrer">
                                <Text fontSize="sm">{f.file_name}</Text>
                            </a>
                            <Text fontSize="xs" color="fg.muted">
                                {f.size_label} · {f.uploaded_by || 'Сотрудник'} · {f.uploaded_at}
                            </Text>
                        </Box>
                    ))}
                </VStack>
            )}

            {busy && (
                <HStack gap={2}>
                    <Spinner size="xs" />
                    <Text fontSize="xs" color="fg.muted">Загрузка…</Text>
                </HStack>
            )}

            {canUpload && files.length > 0 && (
                <Button size="xs" variant="ghost" onClick={load} disabled={busy}>
                    Обновить список
                </Button>
            )}

            <ConfirmDialog
                open={pendingDelete !== null}
                onClose={() => setPendingDelete(null)}
                onConfirm={() => remove(pendingDelete)}
                title="Удалить файл?"
                description="Файл будет удалён безвозвратно."
                confirmLabel="Удалить"
                cancelLabel="Отмена"
                isLoading={busy}
            />
        </VStack>
    );
}
