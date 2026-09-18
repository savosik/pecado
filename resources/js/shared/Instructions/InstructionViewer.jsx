import { Badge, Box, HStack, Image, Text, VStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuArrowLeft, LuDownload, LuExternalLink } from 'react-icons/lu';
import ContentRenderer from '@/components/content/ContentRenderer';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/utils/formatDate';

const formatSize = (bytes) => {
    if (!bytes) return '';
    const units = ['Б', 'КБ', 'МБ', 'ГБ'];
    let n = bytes;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i += 1;
    }
    return `${n.toFixed(i ? 1 : 0)} ${units[i]}`;
};

/**
 * Полная страница инструкции — общая для кабинета, CRM и WMS.
 *
 * Шапка одна на все форматы: обложка, формат, даты создания и обновления,
 * краткое описание. Тело зависит от формата: блоки текста, PDF во фрейме
 * с кнопкой скачивания или ролик (встраивание площадки либо загруженный файл).
 *
 * @param {{instruction: object, backUrl?: string, showTitle?: boolean}} props
 *   showTitle — false там, где заголовок уже рисует лэйаут (кабинет клиента).
 */
export default function InstructionViewer({ instruction, backUrl, showTitle = true }) {
    return (
        <VStack align="stretch" gap="4">
            {backUrl && (
                <HStack>
                    <Link href={backUrl}>
                        <Button size="sm" variant="ghost"><LuArrowLeft /> Ко всем инструкциям</Button>
                    </Link>
                </HStack>
            )}

            <Box bg="bg" border="1px solid" borderColor="border.muted" borderRadius="xl" overflow="hidden">
                {instruction.cover && instruction.type !== 'video' && (
                    <Box overflow="hidden" css={{ aspectRatio: { base: '3 / 2', md: '8 / 3' } }}>
                        <Image src={instruction.cover} alt={instruction.title} w="100%" h="100%" objectFit="cover" />
                    </Box>
                )}

                <Box p={{ base: '5', md: '8' }}>
                    <HStack gap="3" mb="3" flexWrap="wrap">
                        <Badge colorPalette={instruction.type_color} variant="subtle">{instruction.type_label}</Badge>
                        {instruction.created_at && (
                            <Text fontSize="sm" color="fg.muted">Добавлена {formatDate(instruction.created_at, 'long')}</Text>
                        )}
                        {instruction.was_updated && instruction.updated_at && (
                            <Text fontSize="sm" color="fg.muted">· Обновлена {formatDate(instruction.updated_at, 'long')}</Text>
                        )}
                    </HStack>

                    {showTitle && (
                        <Text as="h1" fontSize={{ base: '2xl', md: '3xl' }} fontWeight="700" lineHeight="short" mb="2">
                            {instruction.title}
                        </Text>
                    )}

                    {instruction.short_description && (
                        <Text color="fg.muted" fontSize="md" mb="6">{instruction.short_description}</Text>
                    )}

                    {instruction.type === 'text' && <ContentRenderer content={instruction.content} />}
                    {instruction.type === 'pdf' && <PdfBody file={instruction.file} title={instruction.title} />}
                    {instruction.type === 'video' && <VideoBody video={instruction.video} title={instruction.title} />}
                </Box>
            </Box>
        </VStack>
    );
}

function PdfBody({ file, title }) {
    if (!file) {
        return <Text color="fg.muted">Файл инструкции ещё не загружен.</Text>;
    }

    return (
        <VStack align="stretch" gap="3">
            <HStack gap="2" flexWrap="wrap">
                <a href={file.url} target="_blank" rel="noopener noreferrer">
                    <Button size="sm" variant="outline"><LuExternalLink /> Открыть в новой вкладке</Button>
                </a>
                <a href={file.url} download={file.name}>
                    <Button size="sm" variant="outline"><LuDownload /> Скачать{file.size ? ` (${formatSize(file.size)})` : ''}</Button>
                </a>
            </HStack>
            <Box border="1px solid" borderColor="border.muted" borderRadius="md" overflow="hidden" bg="bg.subtle">
                <iframe
                    src={file.url}
                    title={title}
                    style={{ width: '100%', height: '80vh', border: 0, display: 'block' }}
                />
            </Box>
            <Text fontSize="xs" color="fg.muted">
                Если документ не отобразился, откройте его в новой вкладке или скачайте.
            </Text>
        </VStack>
    );
}

function VideoBody({ video, title }) {
    if (!video || (!video.embed_url && !video.file_url)) {
        return <Text color="fg.muted">Видео ещё не загружено.</Text>;
    }

    return (
        <Box borderRadius="md" overflow="hidden" bg="black" css={{ aspectRatio: '16 / 9' }}>
            {video.embed_url
                ? (
                    <iframe
                        src={video.embed_url}
                        title={title}
                        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; fullscreen"
                        allowFullScreen
                        style={{ width: '100%', height: '100%', border: 0, display: 'block' }}
                    />
                )
                : (
                    <video controls preload="metadata" style={{ width: '100%', height: '100%', display: 'block' }}>
                        <source src={video.file_url} type={video.mime_type || undefined} />
                        Ваш браузер не воспроизводит видео —{' '}
                        <a href={video.file_url}>скачайте файл</a>.
                    </video>
                )}
        </Box>
    );
}
