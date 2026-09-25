import { Badge, Box, Card, HStack, Image, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuBookOpen, LuFileText, LuPlay } from 'react-icons/lu';
import { formatDate } from '@/utils/formatDate';

const TYPE_ICONS = { text: LuBookOpen, pdf: LuFileText, video: LuPlay };

/**
 * Карточка инструкции в списке — тот же каркас, что у новостей: обложка,
 * дата, заголовок, краткое описание. Вместо тегов — формат (текст / PDF /
 * видео), чтобы читатель до клика знал, что откроется.
 *
 * Без обложки рисуем плашку с иконкой формата: серый прямоугольник-заглушка
 * «нет фото» на инструкции выглядел бы как ошибка загрузки.
 */
export default function InstructionCard({ item }) {
    const Icon = TYPE_ICONS[item.type] || LuBookOpen;
    const date = item.was_updated ? item.updated_at : item.created_at;
    const dateLabel = item.was_updated ? 'Обновлена' : 'Добавлена';

    return (
        <Card.Root
            overflow="hidden"
            bg="bg"
            border="1px solid"
            borderColor="border.muted"
            borderRadius="xl"
            transition="all 0.2s"
            _hover={{ borderColor: 'pecado.200', shadow: 'sm', transform: 'translateY(-2px)' }}
        >
            <Link href={item.url} style={{ textDecoration: 'none' }}>
                <Box position="relative" overflow="hidden" css={{ aspectRatio: '3 / 2' }} bg="bg.subtle">
                    {item.cover
                        ? <Image src={item.cover} alt={item.title} w="100%" h="100%" objectFit="cover" loading="lazy" />
                        : (
                            <Box w="100%" h="100%" display="flex" alignItems="center" justifyContent="center" color="fg.muted">
                                <Icon size={40} />
                            </Box>
                        )}
                    <Badge
                        position="absolute"
                        top="2"
                        left="2"
                        colorPalette={item.type_color}
                        variant="solid"
                        size="sm"
                    >
                        <Icon size={12} /> {item.type_label}
                    </Badge>
                </Box>
            </Link>

            <Card.Body p={{ base: '4', md: '5' }} gap="2">
                {date && (
                    <Text fontSize="xs" color="fg.muted" fontWeight="medium">
                        {dateLabel} {formatDate(date, 'long')}
                    </Text>
                )}
                <Link href={item.url} style={{ textDecoration: 'none' }}>
                    <Text
                        fontWeight="600"
                        fontSize={{ base: 'md', md: 'lg' }}
                        lineHeight="short"
                        color="fg"
                        lineClamp={2}
                        _hover={{ color: 'pecado.500' }}
                    >
                        {item.title}
                    </Text>
                </Link>
                {item.short_description && (
                    <Text color="fg.muted" fontSize="sm" lineHeight="tall" lineClamp={3}>
                        {item.short_description}
                    </Text>
                )}
                <HStack mt="1">
                    <Link href={item.url}>
                        <Text fontSize="sm" color="pecado.500" fontWeight="500">
                            {item.type === 'video' ? 'Смотреть' : 'Открыть'} →
                        </Text>
                    </Link>
                </HStack>
            </Card.Body>
        </Card.Root>
    );
}
