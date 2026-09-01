import { Box, HStack, Text, Wrap } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';

const money = (value) =>
    new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 }).format(value || 0);

/**
 * Быстрые фильтры по категориям причин: количество и сумма в самом чипе.
 *
 * Числа стоят на кнопке намеренно — иначе, чтобы понять, велика ли категория,
 * пришлось бы кликнуть в неё и посмотреть итог, а потом вернуться. Здесь весь
 * расклад периода читается одной строкой, а клик уже сужает журнал.
 *
 * Считаются чипы без учёта выбранной категории: соседние цифры не должны
 * обнуляться от того, что один чип нажат, — иначе из отбора не выбраться.
 *
 * Пустые категории скрыты: показывать шесть нулей ради полноты незачем,
 * расшифровка всех категорий есть в легенде. «Без причины» остаётся всегда —
 * это рабочая очередь менеджера, и её ноль тоже новость.
 */
export default function CategoryChips({ chips = [], active = '', onSelect }) {
    const visible = chips.filter(
        (chip) => chip.lines_count > 0 || chip.value === active || chip.value === 'none',
    );

    if (visible.length === 0) {
        return null;
    }

    return (
        <Wrap gap={2}>
            {visible.map((chip) => {
                const isActive = active === chip.value;

                return (
                    <Tooltip key={chip.value} content={chip.description} openDelay={400} showArrow>
                        <Box
                            as="button"
                            type="button"
                            onClick={() => onSelect?.(isActive ? '' : chip.value)}
                            borderWidth="1px"
                            borderRadius="lg"
                            px={3}
                            py={1.5}
                            textAlign="left"
                            cursor="pointer"
                            borderColor={isActive ? `${chip.color}.solid` : 'border'}
                            bg={isActive ? `${chip.color}.subtle` : 'bg.panel'}
                            _hover={{ borderColor: `${chip.color}.solid` }}
                            aria-pressed={isActive}
                        >
                            <HStack gap={2} align="baseline">
                                <Box w="8px" h="8px" borderRadius="full" bg={`${chip.color}.solid`} flexShrink={0} />
                                <Text fontSize="sm" fontWeight={isActive ? 'semibold' : 'medium'}>
                                    {chip.label}
                                </Text>
                                <Text fontSize="sm" fontWeight="bold">{chip.lines_count}</Text>
                            </HStack>
                            <Text fontSize="xs" color="fg.muted">
                                {money(chip.amount)} ₽ · {chip.quantity} шт
                            </Text>
                        </Box>
                    </Tooltip>
                );
            })}
        </Wrap>
    );
}
