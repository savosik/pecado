import React, { useState } from 'react';
import {
    Box,
    Button,
    Popover,
    Textarea,
    VStack,
    Text,
    HStack,
    Collapsible,
    IconButton,
    Portal
} from '@chakra-ui/react';
import { LuSparkles, LuCheck, LuX, LuWand, LuLoader } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import axios from 'axios';

export const AiAssistant = ({ onGenerate, context = '' }) => {
    const [isOpen, setIsOpen] = useState(false);
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [generatedText, setGeneratedText] = useState('');

    const handleGenerate = async () => {
        if (!prompt.trim()) {
            toaster.create({
                title: 'Введите запрос',
                type: 'warning',
            });
            return;
        }

        setLoading(true);
        setGeneratedText('');

        try {
            const response = await axios.post(route('admin.ai.generate'), {
                prompt,
                context,
            });

            setGeneratedText(response.data.content);
        } catch (error) {
            console.error('AI Generation error:', error);
            toaster.create({
                title: 'Ошибка генерации',
                description: error.response?.data?.message || 'Что-то пошло не так',
                type: 'error',
            });
        } finally {
            setLoading(false);
        }
    };

    const handleApply = () => {
        onGenerate(generatedText);
        setIsOpen(false);
        setGeneratedText('');
        setPrompt('');
    };

    return (
        <Popover.Root open={isOpen} onOpenChange={(e) => setIsOpen(e.open)}>
            <Popover.Trigger asChild>
                <Button
                    size="sm"
                    variant="ghost"
                    colorPalette="purple"
                    title="AI Помощник"
                >
                    <LuSparkles /> AI
                </Button>
            </Popover.Trigger>
            <Portal>
                <Popover.Positioner>
                    <Popover.Content width="350px">
                        <Popover.Arrow />
                        <Popover.Header>
                            <HStack justify="space-between">
                                <HStack gap={2} color="purple.500">
                                    <LuWand />
                                    <Text fontWeight="bold">AI Копирайтер</Text>
                                </HStack>
                            </HStack>
                        </Popover.Header>
                        <Popover.Body>
                            <VStack gap={3} align="stretch">
                                <Text fontSize="sm" color="fg.muted">
                                    Опишите, о чем нужно написать, и AI сгенерирует текст для вас.
                                </Text>
                                <Textarea
                                    placeholder="Например: Опиши черное вечернее платье из шелка..."
                                    value={prompt}
                                    onChange={(e) => setPrompt(e.target.value)}
                                    rows={3}
                                    autoFocus
                                />

                                {generatedText && (
                                    <Box
                                        p={3}
                                        bg="bg.subtle"
                                        borderRadius="md"
                                        borderLeftWidth="4px"
                                        borderLeftColor="purple.500"
                                        fontSize="sm"
                                        maxH="200px"
                                        overflowY="auto"
                                    >
                                        <div dangerouslySetInnerHTML={{ __html: generatedText }} />
                                    </Box>
                                )}

                                <HStack justify="flex-end" gap={2}>
                                    {!generatedText ? (
                                        <Button
                                            size="sm"
                                            colorPalette="purple"
                                            onClick={handleGenerate}
                                            loading={loading}
                                            loadingText="Думаю..."
                                        >
                                            <LuSparkles /> Генерировать
                                        </Button>
                                    ) : (
                                        <>
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => setGeneratedText('')}
                                                disabled={loading}
                                            >
                                                Отмена
                                            </Button>
                                            <Button
                                                size="sm"
                                                colorPalette="green"
                                                onClick={handleApply}
                                            >
                                                <LuCheck /> Применить
                                            </Button>
                                        </>
                                    )}
                                </HStack>
                            </VStack>
                        </Popover.Body>
                    </Popover.Content>
                </Popover.Positioner>
            </Portal>
        </Popover.Root>
    );
};
