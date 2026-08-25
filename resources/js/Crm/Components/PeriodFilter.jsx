import { HStack, Input, Text } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';

/** Дата в формате поля <input type="date"> — без часового пояса и без UTC-сдвига. */
const iso = (date) => [
    date.getFullYear(),
    String(date.getMonth() + 1).padStart(2, '0'),
    String(date.getDate()).padStart(2, '0'),
].join('-');

const shift = (days) => {
    const date = new Date();
    date.setDate(date.getDate() + days);

    return date;
};

const monthStart = (offset = 0) => {
    const date = new Date();

    return new Date(date.getFullYear(), date.getMonth() + offset, 1);
};

const monthEnd = (offset = 0) => {
    const date = new Date();

    return new Date(date.getFullYear(), date.getMonth() + offset + 1, 0);
};

/**
 * Пресеты периода. Считаются в момент клика, а не при отрисовке: страница
 * может быть открыта со вчера, и «сегодня» тогда означало бы вчера.
 */
const PRESETS = [
    { key: 'today', label: 'Сегодня', range: () => [iso(new Date()), iso(new Date())] },
    { key: 'week', label: '7 дней', range: () => [iso(shift(-6)), iso(new Date())] },
    { key: 'month30', label: '30 дней', range: () => [iso(shift(-29)), iso(new Date())] },
    { key: 'thisMonth', label: 'Этот месяц', range: () => [iso(monthStart()), iso(monthEnd())] },
    { key: 'prevMonth', label: 'Прошлый месяц', range: () => [iso(monthStart(-1)), iso(monthEnd(-1))] },
    {
        key: 'year',
        label: 'С начала года',
        range: () => [iso(new Date(new Date().getFullYear(), 0, 1)), iso(new Date())],
    },
];

/**
 * Период отбора: пресеты и две даты.
 *
 * Пресеты закрывают почти все обращения к фильтру — «что пришло сегодня»
 * и «сколько за месяц» спрашивают ежедневно, а руками это четыре клика
 * по календарю в каждом из двух полей.
 *
 * Набор пресетов задаётся вызывающим: журналу нужны короткие окна, а акту
 * сверки — месяцы и год, «сегодняшний акт» никто не запрашивает.
 *
 * @param {{from?: string, to?: string, onChange: Function, presets?: string[], clearable?: boolean}} props
 */
export default function PeriodFilter({ from = '', to = '', onChange, presets = null, clearable = true }) {
    const available = presets === null
        ? PRESETS
        : presets.map((key) => PRESETS.find((preset) => preset.key === key)).filter(Boolean);

    const activePreset = available.find((preset) => {
        const [presetFrom, presetTo] = preset.range();

        return presetFrom === from && presetTo === to;
    });

    const applyPreset = (preset) => {
        const [presetFrom, presetTo] = preset.range();

        // Повторный клик по активному пресету снимает период: иначе кнопка
        // становится ловушкой — нажал и вернуться к «за всё время» нечем.
        // Там, где период обязателен (акт сверки), снимать нечего.
        if (clearable && activePreset?.key === preset.key) {
            onChange({ date_from: undefined, date_to: undefined });

            return;
        }

        onChange({ date_from: presetFrom, date_to: presetTo });
    };

    return (
        <HStack gap={2} wrap="wrap" align="center">
            <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">Период</Text>

            {available.map((preset) => (
                <Button
                    key={preset.key}
                    size="xs"
                    variant={activePreset?.key === preset.key ? 'solid' : 'outline'}
                    colorPalette={activePreset?.key === preset.key ? 'pecado' : 'gray'}
                    onClick={() => applyPreset(preset)}
                >
                    {preset.label}
                </Button>
            ))}

            <Input
                size="sm"
                type="date"
                width="150px"
                aria-label="Период с"
                value={from ?? ''}
                onChange={(event) => onChange({ date_from: event.target.value || undefined })}
            />
            <Text fontSize="xs" color="fg.muted">по</Text>
            <Input
                size="sm"
                type="date"
                width="150px"
                aria-label="Период по"
                value={to ?? ''}
                onChange={(event) => onChange({ date_to: event.target.value || undefined })}
            />
        </HStack>
    );
}
