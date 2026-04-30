import { Box, Text, VStack, HStack, Badge, Link, Icon, IconButton } from '@chakra-ui/react';
import { LuExternalLink, LuMessageSquare, LuGlobe, LuUser, LuPaperclip, LuTrash2 } from 'react-icons/lu';
import { router } from '@inertiajs/react';

const TYPE_LABELS = {
    bug: '🐛 Баг',
    unexpected: '🤔 Странное',
    ux: '😤 Неудобство',
    cosmetic: '💅 Косметика',
    feature: '✨ Хотелка',
    improvement: '⚡ Улучшение',
};

const TYPE_COLORS = {
    bug: 'red',
    unexpected: 'orange',
    ux: 'yellow',
    cosmetic: 'pink',
    feature: 'teal',
    improvement: 'cyan',
};

const SCOPE_LABELS = {
    '1c': '1С',
    site: 'Сайт',
    '1c_and_site': '1С+Сайт',
};


const DELETABLE_STATUSES = ['backlog', 'done'];

export default function TaskCard({ task, onDragStart, onTaskClick }) {
    const commentCount = task.comments?.length ?? 0;
    const attachmentCount = task.attachments?.length ?? 0;
    const canDelete = DELETABLE_STATUSES.includes(task.status);

    const handleDelete = (e) => {
        e.stopPropagation();
        if (!confirm(`Удалить задачу "${task.title}"?`)) return;
        router.delete(route('admin.kanban.destroy', task.id), { preserveScroll: true });
    };

    return (
        <Box
            p={4}
            bg="bg.panel"
            shadow="sm"
            rounded="md"
            borderWidth="1px"
            borderColor="border"
            cursor="grab"
            draggable
            onDragStart={(e) => onDragStart(e, task)}
            onClick={() => onTaskClick(task)}
            _hover={{ shadow: 'md', borderColor: 'blue.400' }}
            _active={{ cursor: 'grabbing' }}
            transition="all 0.2s"
        >
            <HStack justify="space-between" mb={2} flexWrap="wrap" gap={1}>
                <HStack gap={1} flexWrap="wrap">
                    {task.type && (
                        <Badge colorPalette={TYPE_COLORS[task.type] || 'gray'} size="sm">
                            {TYPE_LABELS[task.type] || task.type}
                        </Badge>
                    )}
                    {task.scope && (
                        <Badge variant="outline" colorPalette="gray" size="sm">
                            {SCOPE_LABELS[task.scope] || task.scope}
                        </Badge>
                    )}
                </HStack>
                <HStack gap={2}>
                    {commentCount > 0 && (
                        <HStack gap={1} color="fg.muted" fontSize="sm">
                            <Icon as={LuMessageSquare} />
                            <Text>{commentCount}</Text>
                        </HStack>
                    )}
                    {attachmentCount > 0 && (
                        <HStack gap={1} color="fg.muted" fontSize="sm">
                            <Icon as={LuPaperclip} />
                            <Text>{attachmentCount}</Text>
                        </HStack>
                    )}
                    {canDelete && (
                        <IconButton
                            size="2xs"
                            variant="ghost"
                            colorPalette="red"
                            aria-label="Удалить задачу"
                            onClick={handleDelete}
                        >
                            <LuTrash2 />
                        </IconButton>
                    )}
                </HStack>
            </HStack>

            <Text fontWeight="semibold" mb={2} lineClamp={2}>
                {task.title}
            </Text>

            <VStack align="start" gap={1} fontSize="xs" color="fg.muted">
                {task.user_name && (
                    <HStack gap={1}>
                        <Icon as={LuUser} />
                        <Text lineClamp={1}>{task.user_name}</Text>
                    </HStack>
                )}
                {task.browser && (
                    <HStack gap={1}>
                        <Icon as={LuGlobe} />
                        <Text lineClamp={1}>{task.browser}</Text>
                    </HStack>
                )}
                {task.page_url && (
                    <HStack gap={1}>
                        <Icon as={LuExternalLink} />
                        <Link href={task.page_url} target="_blank" color="blue.500" onClick={(e) => e.stopPropagation()}>
                            Ссылка на страницу
                        </Link>
                    </HStack>
                )}
            </VStack>
        </Box>
    );
}
