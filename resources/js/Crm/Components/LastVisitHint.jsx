import { HStack, Text } from '@chakra-ui/react';
import { LuClock, LuUserX } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Мелкая строка «последний визит на сайт» — рядом с именем партнёра.
 *
 * Отвечает на вопрос, которого не видно по заказам: пользуется ли партнёр
 * кабинетом вообще. Заказы приезжают из 1С и при пустом кабинете тоже.
 *
 * Состояние приходит с бэкенда (`state`), а не считается здесь из даты:
 * тот же payload отдают карточка, список и агентское API, и «давно» должно
 * означать одно и то же во всех трёх.
 *
 * @param {{state: 'never'|'stale'|'recent', label: string, at: string|null, days: number|null}} visit
 */
export default function LastVisitHint({ visit }) {
    if (!visit) return null;

    const never = visit.state === 'never';
    const Icon = never ? LuUserX : LuClock;

    // Тот, кто не заходил ни разу, — повод для звонка, а не фоновая справка:
    // отсюда цвет. «Давно не был» приглушаем сильнее, чем свежий визит.
    const color = never ? 'orange.fg' : 'fg.muted';

    const tooltip = never
        ? 'Визитов не зафиксировано: партнёр не заходил в кабинет и не оформлял заказы через сайт. Возможно, у него просто нет доступа'
        : `Последний визит на сайт: ${visit.at}`;

    return (
        <Tooltip content={tooltip} openDelay={400}>
            <HStack gap={1} color={color}>
                <Icon size={11} style={{ flexShrink: 0 }} />
                {/* Не «на сайте: …» — этой подписью в списке уже помечено имя
                    партнёра из кабинета, и две одинаковые строки путали бы. */}
                <Text fontSize="11px" lineClamp={1}>
                    {never ? 'не заходил на сайт' : `был ${visit.label}`}
                </Text>
            </HStack>
        </Tooltip>
    );
}
