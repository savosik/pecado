import { Box, Flex } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';

/**
 * StatusQuickFilters — быстрые фильтры по статусу над списком раздела кабинета.
 *
 * Чипы окрашены той же палитрой, что и бейджи статусов в списке, и показывают
 * количество документов. Выбор множественный: клик переключает статус,
 * выбранные подсвечиваются заливкой. Чип «Все» сбрасывает выбор.
 *
 * Статусы с нулевым счётчиком скрываются — кроме уже выбранных, иначе снять
 * такой фильтр можно было бы только через расширенные фильтры.
 *
 * @param {{
 *   items: Array<{ value: string, label: string, count: number, colorPalette: string }>,
 *   selected?: Array<string>,
 *   total?: number,
 *   onToggle: (value: string) => void,
 *   onReset: () => void,
 *   allLabel?: string,
 * }} props
 */
export default function StatusQuickFilters({
    items = [],
    selected = [],
    total = 0,
    onToggle,
    onReset,
    allLabel = 'Все',
}) {
    const isSelected = (value) => selected.includes(value);
    const visible = items.filter((item) => item.count > 0 || isSelected(item.value));

    if (visible.length === 0) return null;

    return (
        <Flex gap="2" flexWrap="wrap" align="center" mb="4">
            <Chip
                label={allLabel}
                count={total}
                colorPalette="pecado"
                selected={selected.length === 0}
                onClick={onReset}
            />

            {visible.map((item) => (
                <Chip
                    key={item.value}
                    label={item.label}
                    count={item.count}
                    colorPalette={item.colorPalette}
                    selected={isSelected(item.value)}
                    onClick={() => onToggle(item.value)}
                />
            ))}
        </Flex>
    );
}

function Chip({ label, count, colorPalette, selected, onClick }) {
    return (
        <Button
            type="button"
            size="xs"
            variant={selected ? 'solid' : 'subtle'}
            colorPalette={colorPalette}
            borderRadius="full"
            px="3"
            gap="1.5"
            fontWeight="600"
            aria-pressed={selected}
            onClick={onClick}
        >
            {label}
            <Box
                as="span"
                fontSize="2xs"
                lineHeight="1"
                px="1.5"
                py="0.5"
                borderRadius="full"
                bg={selected ? 'white/25' : 'colorPalette.emphasized'}
            >
                {count}
            </Box>
        </Button>
    );
}
