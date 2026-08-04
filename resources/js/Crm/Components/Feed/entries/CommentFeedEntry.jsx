import { useState } from 'react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuPaperclip, LuPencil, LuPin, LuPinOff, LuTrash2 } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import VoiceTextarea from '@/shared/voice/VoiceTextarea';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';
import { usePermission } from '@/shared/Panel/usePermission';
import FeedEntryShell from '../FeedEntryShell';

/**
 * Комментарий в ленте.
 *
 * Отдельно от `CommentEntry`, который живёт во врезках админских карточек: там
 * запись рисует себя сама, здесь — внутри общей оболочки ленты. Свести их в один
 * компонент с флагом «без рамки» означало бы, что любая правка вёрстки ленты
 * задевает карточки заказа и реализации.
 */
export default function CommentFeedEntry({ entry, busy = false, onUpdate, onDelete }) {
    const [editing, setEditing] = useState(false);
    const [draft, setDraft] = useState(entry.body);
    // Файлы подгружаются по клику, а не при рендере ленты: иначе двадцать записей
    // на экране дали бы двадцать запросов ради обычно пустых списков.
    const [filesOpen, setFilesOpen] = useState(false);
    const [filesCount, setFilesCount] = useState(entry.attachments_count ?? 0);
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

    const actions = (
        <>
            {canSeeFiles && !editing && (
                <Button
                    size="xs"
                    variant={filesOpen ? 'subtle' : 'ghost'}
                    colorPalette={filesCount > 0 ? 'blue' : undefined}
                    onClick={() => setFilesOpen((v) => !v)}
                    title={filesCount > 0 ? `Файлов: ${filesCount}` : 'Прикрепить файл'}
                >
                    <LuPaperclip />
                    {filesCount > 0 && <Text as="span" fontSize="xs" ml={1}>{filesCount}</Text>}
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
        </>
    );

    const badges = (
        <>
            {entry.edited && <Text fontSize="xs" color="fg.muted">(изменён)</Text>}
            {entry.entity && (
                <Badge colorPalette="gray" variant="outline" size="sm">
                    {entry.entity.url
                        ? <a href={entry.entity.url}>{entry.entity.title}</a>
                        : entry.entity.title}
                </Badge>
            )}
            {!entry.entity && (
                <Badge colorPalette="gray" variant="outline" size="sm">Запись удалена</Badge>
            )}
        </>
    );

    return (
        <FeedEntryShell
            type="comment"
            author={entry.author?.name}
            time={entry.happened_at_label}
            badges={badges}
            actions={actions}
            pinned={entry.is_pinned}
        >
            {editing ? (
                <VStack align="stretch" gap={2}>
                    <VoiceTextarea value={draft} onChange={setDraft} rows={3} autoFocus />
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
                        onCountChange={setFilesCount}
                    />
                </Box>
            )}
        </FeedEntryShell>
    );
}
