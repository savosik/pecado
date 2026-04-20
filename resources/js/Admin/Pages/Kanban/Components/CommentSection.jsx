import React, { useState } from 'react';
import { router } from '@inertiajs/react';
import { Box, VStack, HStack, Text, Button, IconButton, Textarea } from '@chakra-ui/react';
import { LuReply, LuSend } from 'react-icons/lu';
import VoiceMicButton from './VoiceMicButton';

export default function CommentSection({ task }) {
    const [content, setContent] = useState('');
    const [replyTo, setReplyTo] = useState(null);
    const [replyContent, setReplyContent] = useState('');

    const comments = task?.comments || [];

    const handleAddComment = (e) => {
        e.preventDefault();
        if (!content.trim()) return;

        router.post(route('admin.kanban.comments.store', task.id), {
            content: content,
            parent_id: null
        }, {
            preserveScroll: true,
            onSuccess: () => setContent('')
        });
    };

    const handleReply = (parent_id) => {
        if (!replyContent.trim()) return;

        router.post(route('admin.kanban.comments.store', task.id), {
            content: replyContent,
            parent_id: parent_id
        }, {
            preserveScroll: true,
            onSuccess: () => {
                setReplyContent('');
                setReplyTo(null);
            }
        });
    };

    const appendText = (setter) => (text) =>
        setter(prev => prev ? prev + ' ' + text : text);

    const renderComment = (comment, level = 0) => (
        <Box key={comment.id} w="100%" pl={level > 0 ? 8 : 0} mt={level > 0 ? 2 : 4}>
            <Box bg="white" _dark={{ bg: 'gray.700' }} p={3} rounded="md" shadow="sm" borderWidth="1px" borderColor="border.subtle">
                <HStack justify="space-between" mb={1} fontSize="xs" color="fg.muted">
                    <Text fontWeight="bold">ID: {comment.id}</Text>
                    <Text>{new Date(comment.created_at).toLocaleString('ru-RU')}</Text>
                </HStack>
                <Text fontSize="sm" mb={2} whiteSpace="pre-wrap">{comment.content}</Text>

                <HStack>
                    <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => setReplyTo(replyTo === comment.id ? null : comment.id)}
                    >
                        <LuReply /> Ответить
                    </Button>
                </HStack>

                {replyTo === comment.id && (
                    <Box mt={2}>
                        <Textarea
                            size="sm"
                            placeholder="Написать ответ..."
                            value={replyContent}
                            onChange={(e) => setReplyContent(e.target.value)}
                            mb={2}
                        />
                        <HStack justify="flex-end">
                            <VoiceMicButton onText={appendText(setReplyContent)} size="sm" />
                            <IconButton
                                size="sm"
                                colorPalette="blue"
                                onClick={() => handleReply(comment.id)}
                                aria-label="Отправить ответ"
                            >
                                <LuSend />
                            </IconButton>
                        </HStack>
                    </Box>
                )}
            </Box>

            {comment.replies && comment.replies.map(reply => renderComment(reply, level + 1))}
        </Box>
    );

    return (
        <Box mt={6} pt={6} borderTopWidth="1px" borderColor="border.default">
            <Text fontWeight="bold" mb={4}>Комментарии ({comments.length})</Text>

            <VStack align="stretch" gap={0} mb={6}>
                {comments.map(comment => renderComment(comment))}
            </VStack>

            <Box>
                <Textarea
                    placeholder="Добавить комментарий..."
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    mb={2}
                />
                <HStack justify="space-between">
                    <VoiceMicButton onText={appendText(setContent)} />
                    <Button colorPalette="blue" onClick={handleAddComment}>
                        Отправить
                    </Button>
                </HStack>
            </Box>
        </Box>
    );
}
