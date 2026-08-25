import { HStack, Text, VStack } from '@chakra-ui/react';
import { Badge } from '@chakra-ui/react';
import MetricHint from '@/Crm/Components/MetricHint';

/**
 * Вес просрочки — «рублёдни»: остаток, умноженный на число просроченных дней.
 *
 * Метрика отвечает ровно на тот вопрос, который не решают сумма и срок по
 * отдельности: 50 тысяч, висящие год, дороже 400 тысяч недельной задержки —
 * и первое надо разбирать раньше. Складывается по строкам и по узлам разреза,
 * поэтому одинаково работает на любом уровне дерева.
 *
 * Балл относительный, а не абсолютный: 100 — самая тяжёлая строка текущего
 * отбора, остальные к ней. Абсолютные пороги в рублёднях пришлось бы
 * пересматривать при каждом изменении оборота, и «критично» обесценилось бы.
 */
export const WEIGHT_LEVELS = [
    { from: 60, label: 'критично', palette: 'red' },
    { from: 30, label: 'высокий', palette: 'orange' },
    { from: 10, label: 'средний', palette: 'yellow' },
    { from: 0, label: 'низкий', palette: 'gray' },
];

export const weightScore = (weight, max) => (max > 0 ? Math.round((Number(weight || 0) / max) * 100) : 0);

export const weightLevel = (score) => WEIGHT_LEVELS.find((level) => score >= level.from) ?? WEIGHT_LEVELS[3];

/** Бейдж приоритета со средним возрастом долга под ним. */
export default function OverdueWeight({ weight, max, age }) {
    const score = weightScore(weight, max);
    const level = weightLevel(score);

    return (
        <VStack align="start" gap={0}>
            <Badge size="xs" colorPalette={level.palette} variant="subtle">
                {level.label} · {score}
            </Badge>
            {age > 0 && (
                <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                    в среднем {age} дн.
                </Text>
            )}
        </VStack>
    );
}

/** Заголовок колонки веса — с объяснением, откуда берётся балл. */
export function WeightHeader() {
    return (
        <HStack gap={1}>
            <Text fontSize="xs">Вес</Text>
            <MetricHint text="Приоритет разбора, а не оценка клиента. Считается как «рублёдни»: остаток строки, умноженный на число просроченных дней, — 50 тысяч, висящие год, весят больше, чем 400 тысяч недельной задержки. Балл относительный: 100 — самая тяжёлая строка текущего отбора. Под баллом — средневзвешенный возраст долга." />
        </HStack>
    );
}
