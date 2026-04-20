import React, { useState, useRef, useEffect, useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { Box, IconButton, Input, Textarea, Text, VStack, HStack, Button } from '@chakra-ui/react';
import { LuBug, LuX, LuPaperclip } from 'react-icons/lu';
import { toaster } from '@/components/ui/toaster';
import VoiceMicButton from '@/Admin/Pages/Kanban/Components/VoiceMicButton';

function detectBrowser() {
    const ua = navigator.userAgent;
    if (/Edg\//.test(ua)) return 'Microsoft Edge';
    if (/OPR\/|Opera/.test(ua)) return 'Opera';
    if (/Chrome\//.test(ua)) return 'Chrome';
    if (/Firefox\//.test(ua)) return 'Firefox';
    if (/Safari\//.test(ua)) return 'Safari';
    return ua.slice(0, 60);
}

function getResolution() {
    return `${window.screen.width}×${window.screen.height}`;
}

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' Б';
    if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' КБ';
    return (bytes / 1048576).toFixed(1) + ' МБ';
}

export default function BugReportWidget() {
    const { bugReportMode, auth } = usePage().props;

    const [isOpen, setIsOpen] = useState(false);
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [files, setFiles] = useState([]);
    const [loading, setLoading] = useState(false);
    const fileInputRef = useRef(null);

    const handlePaste = useCallback((e) => {
        if (!isOpen) return;
        const items = Array.from(e.clipboardData?.items || []);
        const imageItems = items.filter(i => i.type.startsWith('image/'));
        if (imageItems.length === 0) return;
        e.preventDefault();
        const newFiles = imageItems.map(item => {
            const blob = item.getAsFile();
            const ts = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
            return new File([blob], `paste-${ts}.png`, { type: blob.type });
        });
        setFiles(prev => [...prev, ...newFiles]);
    }, [isOpen]);

    useEffect(() => {
        document.addEventListener('paste', handlePaste);
        return () => document.removeEventListener('paste', handlePaste);
    }, [handlePaste]);

    if (!bugReportMode) return null;

    const handleOpen = async () => {
        setIsOpen(true);

        try {
            const html2canvas = (await import('html2canvas')).default;
            const canvas = await html2canvas(document.body, {
                scale: 0.5,
                useCORS: true,
                logging: false,
                allowTaint: true,
            });
            canvas.toBlob((blob) => {
                if (!blob) return;
                const ts = new Date().toISOString().slice(0, 19).replace(/[T:]/g, '-');
                const file = new File([blob], `screenshot-${ts}.png`, { type: 'image/png' });
                setFiles(prev => [file, ...prev]);
            }, 'image/png', 0.8);
        } catch (_) {}
    };

    const handleClose = () => {
        setIsOpen(false);
        setTitle('');
        setDescription('');
        setFiles([]);
    };

    const handleFileChange = (e) => {
        setFiles(prev => [...prev, ...Array.from(e.target.files)]);
        e.target.value = '';
    };

    const removeFile = (index) => {
        setFiles(prev => prev.filter((_, i) => i !== index));
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (!title.trim()) return;

        setLoading(true);
        const formData = new FormData();
        formData.append('title', title.trim());
        formData.append('description', description.trim());
        formData.append('browser', `${detectBrowser()} · ${getResolution()}`);
        formData.append('user_name', auth?.user?.name || '');
        files.forEach(f => formData.append('files[]', f));

        try {
            const res = await fetch(route('bug-report.store'), {
                method: 'POST',
                headers: {
                    'X-XSRF-TOKEN': getCsrfToken(),
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            if (res.ok) {
                toaster.create({ title: 'Ваше замечание принято', type: 'success', duration: 4000 });
                handleClose();
            } else {
                toaster.create({ title: 'Ошибка при отправке', type: 'error' });
            }
        } catch (_) {
            toaster.create({ title: 'Ошибка при отправке', type: 'error' });
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Box position="fixed" bottom={6} left={6} zIndex={1300}>
                <IconButton
                    colorPalette="red"
                    rounded="full"
                    size="lg"
                    shadow="lg"
                    onClick={handleOpen}
                    aria-label="Сообщить об ошибке"
                    title="Сообщить об ошибке"
                >
                    <LuBug />
                </IconButton>
            </Box>

            {isOpen && (
                <Box
                    position="fixed" top={0} left={0} w="100vw" h="100vh"
                    bg="black/60" zIndex={1400}
                    display="flex" alignItems="center" justifyContent="center"
                    onClick={handleClose}
                >
                    <Box
                        bg="white" _dark={{ bg: 'gray.800' }}
                        w="100%" maxW="560px" maxH="90vh"
                        rounded="lg" shadow="xl"
                        display="flex" flexDirection="column"
                        onClick={e => e.stopPropagation()}
                    >
                        <HStack p={4} borderBottomWidth="1px" borderColor="border.default" justify="space-between">
                            <Text fontWeight="bold" fontSize="lg">Сообщить об ошибке</Text>
                            <IconButton variant="ghost" size="sm" onClick={handleClose}><LuX /></IconButton>
                        </HStack>

                        <Box p={5} overflowY="auto" flex="1">
                            <form id="bug-report-form" onSubmit={handleSubmit}>
                                <VStack align="stretch" gap={4}>
                                    <Box>
                                        <HStack justify="space-between" mb={1}>
                                            <Text fontWeight="medium" fontSize="sm">Тема *</Text>
                                            <VoiceMicButton
                                                onText={(t) => setTitle(p => p ? p + ' ' + t : t)}
                                            />
                                        </HStack>
                                        <Input
                                            value={title}
                                            onChange={e => setTitle(e.target.value)}
                                            placeholder="Кратко опишите проблему"
                                            required
                                        />
                                    </Box>

                                    <Box>
                                        <HStack justify="space-between" mb={1}>
                                            <Text fontWeight="medium" fontSize="sm">Описание</Text>
                                            <VoiceMicButton
                                                onText={(t) => setDescription(p => p ? p + ' ' + t : t)}
                                            />
                                        </HStack>
                                        <Textarea
                                            value={description}
                                            onChange={e => setDescription(e.target.value)}
                                            placeholder="Подробнее опишите проблему..."
                                            rows={4}
                                        />
                                    </Box>

                                    <Box>
                                        <HStack justify="space-between" mb={1}>
                                            <Text fontWeight="medium" fontSize="sm">Файлы и скриншоты</Text>
                                            <Button
                                                size="xs"
                                                variant="outline"
                                                onClick={() => fileInputRef.current?.click()}
                                                type="button"
                                            >
                                                <LuPaperclip /> Добавить
                                            </Button>
                                        </HStack>
                                        <Text fontSize="xs" color="fg.muted" mb={2}>
                                            Вставьте скриншот через Ctrl+V или прикрепите файл
                                        </Text>
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            multiple
                                            style={{ display: 'none' }}
                                            onChange={handleFileChange}
                                        />
                                        {files.length > 0 && (
                                            <VStack align="stretch" gap={1}>
                                                {files.map((f, i) => (
                                                    <HStack
                                                        key={i}
                                                        fontSize="xs"
                                                        bg="bg.subtle"
                                                        px={2}
                                                        py={1}
                                                        rounded="md"
                                                        justify="space-between"
                                                    >
                                                        <Text flex={1} overflow="hidden" textOverflow="ellipsis" whiteSpace="nowrap">
                                                            {f.name}
                                                        </Text>
                                                        <Text color="fg.muted" flexShrink={0}>{formatSize(f.size)}</Text>
                                                        <IconButton
                                                            size="2xs"
                                                            variant="ghost"
                                                            type="button"
                                                            onClick={() => removeFile(i)}
                                                        >
                                                            <LuX />
                                                        </IconButton>
                                                    </HStack>
                                                ))}
                                            </VStack>
                                        )}
                                    </Box>
                                </VStack>
                            </form>
                        </Box>

                        <HStack p={4} borderTopWidth="1px" borderColor="border.default" justify="flex-end">
                            <Button variant="outline" type="button" onClick={handleClose}>Отмена</Button>
                            <Button
                                type="submit"
                                form="bug-report-form"
                                colorPalette="red"
                                loading={loading}
                            >
                                Отправить
                            </Button>
                        </HStack>
                    </Box>
                </Box>
            )}
        </>
    );
}
