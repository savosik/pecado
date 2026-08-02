import { useState } from 'react';
import { Box, HStack, Text, Textarea, VStack, Badge } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';
import { LuPin, LuPinOff, LuPencil, LuTrash2, LuPaperclip } from 'react-icons/lu';
import { usePermission } from '@/shared/Panel/usePermission';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';

/**
 * Одна запись ленты. Вынесена отдельно, потому что используется и в сквозной
 * ленте клиента, и во врезке конкретной сущности — с одинаковым поведением.
 *
 * @param {object} entry — запись из ClientTimelineService
 * @param {boolean} showEntity — показывать ли бейдж сущности (в сквозной ленте — да,
 *        во врезке карточки заказа он был бы бессмысленным повтором)
 */
export default function CommentEntry({ entry, showEntity = false, onUpdate, onDelete, busy = false }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(entry.body);
    // Файлы подгружаются по клику, а не при рендере ленты: иначе двадцать записей
    // на экране дали бы двадцать запросов ради обычно пустых списков.
    const [filesOpen, setFilesOpen] = useState(false);
    const { can } = usePermission();
    const canSeeFiles = can('crm-attachments.view');

    const save = async () => {
        const body = draft.trim();
        if (!body || body === entry.body) {
            setEditing(false);
            return;
        }
        await onUpdate(entry.id, { body });
        setEditing(false);
    };

    return (
        <Box
            borderWidth="1px"
            borderColor={entry.is_pinned ? 'yellow.400' : 'border'}
            borderRadius="md"
            p={3}
            bg={entry.is_pinned ? 'yellow.50' : undefined}
            _dark={{ bg: entry.is_pinned ? 'yellow.950' : undefined }}
        >
            <VStack align="stretch" gap={2}>
                <HStack justify="space-between" align="start" gap={2} flexWrap="wrap">
                    <HStack gap={2} flexWrap="wrap">
                        <Text fontSize="sm" fontWeight="600">{entry.author?.name}</Text>
                        <Text fontSize="xs" color="fg.muted">{entry.happened_at_label}</Text>
                        {entry.edited && <Text fontSize="xs" color="fg.muted">(изменён)</Text>}
                        {entry.is_pinned && (
                            <Badge colorPalette="yellow" variant="subtle" size="sm">Закреплён</Badge>
                        )}
                        {showEntity && entry.entity && (
                            <Badge colorPalette="gray" variant="subtle" size="sm">
                                {entry.entity.url
                                    ? <a href={entry.entity.url}>{entry.entity.title}</a>
                                    : entry.entity.title}
                            </Badge>
                        )}
                        {showEntity && !entry.entity && (
                            <Badge colorPalette="gray" variant="subtle" size="sm">Запись удалена</Badge>
                        )}
                    </HStack>

                    <HStack gap={1}>
                        {canSeeFiles && !editing && (
                            <Button
                                size="xs"
                                variant={filesOpen ? 'subtle' : 'ghost'}
                                onClick={() => setFilesOpen((v) => !v)}
                                title="Файлы комментария"
                            >
                                <LuPaperclip />
                            </Button>
                        )}
                        {entry.can?.update && !editing && (
                            <>
                                <Button
                                    size="xs"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => onUpdate(entry.id, { is_pinned: !entry.is_pinned })}
                                    title={entry.is_pinned ? 'Открепить' : 'Закрепить вверху'}
                                >
                                    {entry.is_pinned ? <LuPinOff /> : <LuPin />}
                                </Button>
                                <Button
                                    size="xs"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => { setDraft(entry.body); setEditing(true); }}
                                    title="Редактировать"
                                >
                                    <LuPencil />
                                </Button>
                            </>
                        )}
                        {entry.can?.delete && !editing && (
                            <Button
                                size="xs"
                                variant="ghost"
                                colorPalette="red"
                                disabled={busy}
                                onClick={() => onDelete(entry.id)}
                                title="Удалить"
                            >
                                <LuTrash2 />
                            </Button>
                        )}
                    </HStack>
                </HStack>

                {editing ? (
                    <VStack align="stretch" gap={2}>
                        <Textarea
                            value={draft}
                            onChange={(e) => setDraft(e.target.value)}
                            rows={3}
                            autoFocus
                        />
                        <HStack gap={2}>
                            <Button size="xs" onClick={save} loading={busy}>Сохранить</Button>
                            <Button size="xs" variant="ghost" onClick={() => setEditing(false)}>Отмена</Button>
                        </HStack>
                    </VStack>
                ) : (
                    <Text fontSize="sm" whiteSpace="pre-wrap">{entry.body}</Text>
                )}

                {filesOpen && canSeeFiles && (
                    <Box pt={2} borderTopWidth="1px">
                        <AttachmentPanel
                            entityType="comment"
                            entityId={entry.id}
                            canUpload={!!entry.can?.update && can('crm-attachments.create')}
                            label="Файлы комментария"
                        />
                    </Box>
                )}
            </VStack>
        </Box>
    );
}
