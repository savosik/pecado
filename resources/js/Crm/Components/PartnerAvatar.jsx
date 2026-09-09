import { useState } from 'react';
import { Box, Text, VStack } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';
import {
    HoverCardArrow,
    HoverCardContent,
    HoverCardRoot,
    HoverCardTrigger,
} from '@/components/ui/hover-card';

/**
 * Аватарка партнёра в CRM.
 *
 * Картинка приходит с защищённого маршрута (`crm.clients.avatar`), а не из
 * публичного хранилища: её рисует отдел продаж для себя, и партнёр не должен
 * увидеть в кабинете, каким его изобразили.
 *
 * Пока аватарки нет — инициалы на цветном фоне. Цвет считается из имени, а не
 * случайно: строка партнёра не должна менять окраску при каждой перерисовке.
 *
 * Своя вёрстка вместо Chakra Avatar намеренно: нужен единственный сценарий —
 * квадрат со скруглением, ошибка загрузки уводит на инициалы, и никаких
 * групп, бейджей и колец, ради которых пришлось бы тянуть сниппет.
 */

const PALETTE = [
    { bg: 'blue.500', fg: 'white' },
    { bg: 'purple.500', fg: 'white' },
    { bg: 'teal.500', fg: 'white' },
    { bg: 'orange.500', fg: 'white' },
    { bg: 'pink.500', fg: 'white' },
    { bg: 'cyan.600', fg: 'white' },
    { bg: 'green.600', fg: 'white' },
];

/** Инициалы: две первые буквы значимых слов, без «ООО» и кавычек. */
function initials(name = '') {
    const skip = ['ооо', 'зао', 'оао', 'пао', 'ао', 'ип', 'тд', 'нко'];
    const words = String(name)
        .replace(/["«»(),.]/g, ' ')
        .split(/\s+/)
        .filter((word) => word && !skip.includes(word.toLowerCase()));

    if (words.length === 0) return '—';

    return words.slice(0, 2).map((word) => word[0].toUpperCase()).join('');
}

/** Устойчивый цвет по имени: одна и та же строка — один и тот же фон. */
function paletteFor(name = '') {
    let hash = 0;

    for (let i = 0; i < name.length; i += 1) {
        hash = (hash * 31 + name.charCodeAt(i)) % 100000;
    }

    return PALETTE[hash % PALETTE.length];
}

/**
 * @param {{url: string, source: string|null}|null} avatar
 * @param {string} name — для инициалов и цвета
 * @param {number} size — сторона квадрата в пикселях
 * @param {string|null} hint — подсказка при наведении
 * @param {boolean} preview — показывать увеличенную картинку при наведении.
 *   В списке аватарка 28 px: разобрать, что там нарисовано, без увеличения
 *   невозможно, а ради этого её и рисовали.
 */
export default function PartnerAvatar({ avatar, name, size = 28, hint = null, preview = true }) {
    // Файл мог исчезнуть с диска (переезд хранилища, ручная чистка). Тогда
    // показываем инициалы, а не сломанную картинку.
    const [failed, setFailed] = useState(false);
    const showImage = Boolean(avatar?.url) && !failed;
    const colors = paletteFor(name || '');

    const box = (
        <Box
            width={`${size}px`}
            height={`${size}px`}
            minWidth={`${size}px`}
            borderRadius="md"
            overflow="hidden"
            bg={showImage ? 'bg.muted' : colors.bg}
            display="flex"
            alignItems="center"
            justifyContent="center"
            flexShrink={0}
        >
            {showImage ? (
                <img
                    src={avatar.url}
                    alt=""
                    width={size}
                    height={size}
                    loading="lazy"
                    onError={() => setFailed(true)}
                    style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block' }}
                />
            ) : (
                <Text
                    color={colors.fg}
                    fontWeight="bold"
                    fontSize={`${Math.max(10, Math.round(size * 0.38))}px`}
                    lineHeight="1"
                    userSelect="none"
                >
                    {initials(name)}
                </Text>
            )}
        </Box>
    );

    // Без картинки увеличивать нечего: инициалы крупнее не станут понятнее,
    // и всплывающая карточка на пустом месте только мешала бы.
    if (!showImage || !preview) {
        return hint ? <Tooltip content={hint} openDelay={400}>{box}</Tooltip> : box;
    }

    return (
        <HoverCardRoot size="sm" openDelay={250} closeDelay={80} positioning={{ placement: 'right' }}>
            <HoverCardTrigger asChild>
                <Box cursor="help" display="inline-flex">{box}</Box>
            </HoverCardTrigger>
            <HoverCardContent maxW="260px">
                <HoverCardArrow />
                <VStack gap={2} align="stretch">
                    <Box borderRadius="md" overflow="hidden" bg="bg.muted">
                        <img
                            src={avatar.url}
                            alt=""
                            width={220}
                            height={220}
                            style={{ width: '220px', height: '220px', objectFit: 'cover', display: 'block' }}
                        />
                    </Box>
                    <Text fontSize="xs" color="fg.muted" lineHeight="1.3">
                        {name}
                        {hint ? ` · ${hint}` : ''}
                    </Text>
                </VStack>
            </HoverCardContent>
        </HoverCardRoot>
    );
}
