import React, { useState } from 'react';
import { Box, Text, VStack, HStack, Badge, Link, Icon } from '@chakra-ui/react';
import { LuExternalLink, LuMessageSquare, LuGlobe, LuUser, LuPaperclip } from 'react-icons/lu';

export default function TaskCard({ task, onDragStart, onTaskClick }) {
    const commentCount = task.comments ? task.comments.length : 0;
    const attachmentCount = task.attachments ? task.attachments.length : 0;

    const statusColors = {
        backlog: 'purple',
        todo: 'gray',
        in_progress: 'blue',
        testing: 'orange',
        done: 'green',
        reopen: 'red',
    };

    return (
        <Box
            p={4}
            bg="bg.panel"
            shadow="sm"
            rounded="md"
            borderWidth="1px"
            borderColor="border.default"
            cursor="grab"
            draggable
            onDragStart={(e) => onDragStart(e, task)}
            onClick={() => onTaskClick(task)}
            _hover={{ shadow: 'md', borderColor: 'blue.400' }}
            _active={{ cursor: 'grabbing' }}
            transition="all 0.2s"
        >
            <HStack justify="space-between" mb={2}>
                <Badge colorPalette={statusColors[task.status] || 'gray'} size="sm">
                    {task.status.replace('_', ' ').toUpperCase()}
                </Badge>
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
                </HStack>
            </HStack>

            <Text fontWeight="semibold" mb={2} lineClamp={2}>
                {task.title}
            </Text>

            <VStack align="start" gap={1} fontSize="xs" color="fg.muted">
                {task.user_name && (
                    <HStack gap={1}>
                        <Icon as={LuUser} />
                        <Text noOfLines={1}>{task.user_name}</Text>
                    </HStack>
                )}
                {task.browser && (
                    <HStack gap={1}>
                        <Icon as={LuGlobe} />
                        <Text noOfLines={1}>{task.browser}</Text>
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
