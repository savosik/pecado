import { Badge, Box, Card, HStack, Text, VStack } from '@chakra-ui/react';
import { Tooltip } from '@/components/ui/tooltip';

const RUB = new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 0 });

const hours = (value) => {
    if (value === null || value === undefined) return '—';
    if (value < 24) return `${value} ч`;

    return `${Math.round(value / 24)} дн.`;
};

/**
 * Воронка: сколько лидов и денег на стадии, сколько этап занимает.
 *
 * Горизонтальные полосы, а не «настоящая» воронка-трапеция: ширина полосы
 * читается точнее, чем площадь фигуры, а на стадиях со схожим числом лидов
 * трапеция вырождается в прямоугольник и перестаёт что-либо сообщать.
 *
 * Средняя длительность показывается рядом с медианой и числом наблюдений:
 * один зависший на полгода лид перекашивает среднее так, что цифрой нельзя
 * пользоваться. Ниже порога наблюдений метрика скрыта совсем — по двум
 * переходам решения принимать нельзя, а выглядят они одинаково убедительно.
 */
export default function FunnelPanel({ funnel }) {
    if (! funnel?.stages?.length) {
        return null;
    }

    const max = Math.max(...funnel.stages.map((stage) => stage.leads), 1);
    const { conversion } = funnel;

    return (
        <Card.Root>
            <Card.Body>
                <HStack justify="space-between" mb={3} wrap="wrap" gap={3}>
                    <Text fontSize="sm" fontWeight="600">Воронка</Text>
                    <HStack gap={4}>
                        <Text fontSize="xs" color="fg.muted">Всего лидов: {funnel.total}</Text>
                        <Text fontSize="xs" color="fg.muted">
                            В работе: {conversion.open}
                        </Text>
                        <Tooltip
                            content="Доля выигранных среди тех, кто дошёл до конца воронки. Считается по флагам стадий, а не по последней колонке."
                            openDelay={400}
                        >
                            <Text fontSize="xs" color="fg.muted">
                                Конверсия: {conversion.percent === null
                                    ? 'пока не считается'
                                    : `${conversion.percent} %`}
                            </Text>
                        </Tooltip>
                    </HStack>
                </HStack>

                <VStack align="stretch" gap={2}>
                    {funnel.stages.map((stage) => (
                        <HStack key={stage.stage_id} gap={3} align="center">
                            <Text fontSize="xs" minW="150px" lineClamp={1}>{stage.name}</Text>

                            <Box flex="1" bg="bg.muted" borderRadius="sm" h="18px" position="relative">
                                <Box
                                    h="100%"
                                    borderRadius="sm"
                                    bg={`${stage.color}.solid`}
                                    w={`${Math.round((stage.leads / max) * 100)}%`}
                                    minW={stage.leads > 0 ? '2px' : '0'}
                                />
                            </Box>

                            <Text fontSize="xs" minW="40px" textAlign="right" fontWeight="medium">
                                {stage.leads}
                            </Text>

                            <Text fontSize="xs" minW="90px" textAlign="right" color="fg.muted">
                                {stage.amount > 0 ? `${RUB.format(stage.amount)} ₽` : '—'}
                            </Text>

                            <Box minW="150px">
                                {stage.reliable ? (
                                    <Tooltip
                                        content={`Среднее ${hours(stage.avg_hours)}, медиана ${hours(stage.median_hours)} по ${stage.observations} переходам. Медиана рядом со средним не случайно: один зависший лид перекашивает среднее.`}
                                        openDelay={400}
                                    >
                                        <Text fontSize="11px" color="fg.muted">
                                            ср. {hours(stage.avg_hours)} · мед. {hours(stage.median_hours)}
                                        </Text>
                                    </Tooltip>
                                ) : (
                                    <Tooltip
                                        content={`Наблюдений: ${stage.observations}. Нужно не меньше ${funnel.min_observations}, иначе средняя длительность — шум.`}
                                        openDelay={400}
                                    >
                                        <Badge size="sm" variant="subtle" colorPalette="gray">
                                            мало данных
                                        </Badge>
                                    </Tooltip>
                                )}
                            </Box>
                        </HStack>
                    ))}
                </VStack>
            </Card.Body>
        </Card.Root>
    );
}
