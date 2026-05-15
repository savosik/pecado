import { Box, HStack, Stack, Text, Badge } from '@chakra-ui/react';
import { LuClock, LuFileText, LuDatabase, LuTimer, LuTriangleAlert } from 'react-icons/lu';

/**
 * Панель статистики последнего запуска выгрузки.
 *
 * Why: чтобы видеть, на что уходит время большой выгрузки. Разбивка приходит
 * в product_export_runs.steps_json от StepTimer:
 *   - chunks_total (общее время цикла chunk'ов — query+eager+price+stock+map+write)
 *   - price_map / stock_map / map_rows / write_format (компоненты chunks_total)
 *   - other (всё, что вне chunk'ов: создание Run-записи, fsync, rename)
 *
 * На stacked-bar показываем «компоненты»: chunks_load = chunks_total минус
 * замеренные подкомпоненты — это и есть «загрузка чанков из БД» (query+eager).
 *
 * Цвета палитры Chakra (token names) — не hex, чтобы автоматически попадать
 * в светлую/тёмную тему.
 */

const STEP_LABELS = {
    chunks_load: 'Загрузка из БД (query + eager-load)',
    price_map: 'Карта цен',
    stock_map: 'Карта остатков',
    map_rows: 'Маппинг полей',
    write_format: 'Сериализация в формат',
    other: 'Прочее (rename, моделька, fsync)',
};

const STEP_COLORS = {
    chunks_load: 'blue.500',
    price_map: 'purple.500',
    stock_map: 'orange.500',
    map_rows: 'green.500',
    write_format: 'teal.500',
    other: 'gray.400',
};

function formatMs(ms) {
    if (ms == null) return '—';
    if (ms < 1000) return `${ms} мс`;
    return `${(ms / 1000).toFixed(ms < 10000 ? 2 : 1)} с`;
}

function formatBytes(bytes) {
    if (bytes == null) return '—';
    if (bytes < 1024) return `${bytes} Б`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} КБ`;
    return `${(bytes / 1024 / 1024).toFixed(2)} МБ`;
}

function formatNumber(n) {
    if (n == null) return '—';
    return n.toLocaleString('ru-RU');
}

function statusBadge(status) {
    const map = {
        ready: { palette: 'green', label: 'Готово' },
        generating: { palette: 'blue', label: 'Генерируется' },
        queued: { palette: 'gray', label: 'В очереди' },
        failed: { palette: 'red', label: 'Ошибка' },
    };
    const cfg = map[status] || { palette: 'gray', label: status };
    return <Badge colorPalette={cfg.palette} variant="subtle">{cfg.label}</Badge>;
}

/**
 * Превратить steps_json в список сегментов для stacked bar.
 * chunks_total «раскладываем» на chunks_load + компоненты, чтобы избежать
 * двойного счёта. Если данных нет — возвращаем [].
 */
function buildSegments(steps, totalMs) {
    if (!steps || typeof steps !== 'object') return [];

    const priceMap = steps.price_map ?? 0;
    const stockMap = steps.stock_map ?? 0;
    const mapRows = steps.map_rows ?? 0;
    const writeFormat = steps.write_format ?? 0;
    const other = steps.other ?? 0;
    const chunksTotal = steps.chunks_total ?? 0;

    // chunks_load = время загрузки чанков (query+eager) внутри chunk-цикла.
    // Может быть отрицательным при погрешности измерений (например, XLS write
    // выполняется и внутри, и снаружи chunk'а) — клипуем к 0.
    const chunksLoad = Math.max(0, chunksTotal - (priceMap + stockMap + mapRows + writeFormat));

    const segments = [
        { key: 'chunks_load', ms: chunksLoad },
        { key: 'price_map', ms: priceMap },
        { key: 'stock_map', ms: stockMap },
        { key: 'map_rows', ms: mapRows },
        { key: 'write_format', ms: writeFormat },
        { key: 'other', ms: other },
    ].filter((s) => s.ms > 0);

    const sum = segments.reduce((acc, s) => acc + s.ms, 0);
    // База для процентов — большее из (сумма сегментов, общая длительность).
    // Если duration_ms сильно больше суммы — добавим визуальный «зазор» как other.
    const denom = Math.max(sum, totalMs || 0, 1);

    return segments.map((s) => ({
        ...s,
        percent: (s.ms / denom) * 100,
        label: STEP_LABELS[s.key] || s.key,
        color: STEP_COLORS[s.key] || 'gray.500',
    }));
}

export default function RunStatsPanel({ run }) {
    if (!run) {
        return (
            <Box p={4} bg="bg.subtle" borderRadius="md" border="1px solid" borderColor="border.muted">
                <Text fontSize="sm" color="fg.muted">
                    Этот файл ещё ни разу не генерировался. Статистика появится после первого скачивания.
                </Text>
            </Box>
        );
    }

    const segments = buildSegments(run.steps_json, run.duration_ms);

    return (
        <Box p={4} bg="bg.subtle" borderRadius="md" border="1px solid" borderColor="border.muted">
            <Stack gap={4}>
                <HStack justify="space-between" wrap="wrap" gap={2}>
                    <HStack gap={2}>
                        <Text fontWeight="bold" fontSize="sm">Последний запуск</Text>
                        {statusBadge(run.status)}
                    </HStack>
                    {run.started_at && (
                        <Text fontSize="xs" color="fg.muted">
                            {new Date(run.started_at).toLocaleString('ru-RU')}
                        </Text>
                    )}
                </HStack>

                {/* Сводка цифр */}
                <HStack gap={6} wrap="wrap">
                    <HStack gap={2}>
                        <LuClock size={14} />
                        <Box>
                            <Text fontSize="xs" color="fg.muted">Длительность</Text>
                            <Text fontSize="sm" fontWeight="bold">{formatMs(run.duration_ms)}</Text>
                        </Box>
                    </HStack>
                    <HStack gap={2}>
                        <LuTimer size={14} />
                        <Box>
                            <Text fontSize="xs" color="fg.muted">Висел в очереди</Text>
                            <Text fontSize="sm" fontWeight="bold">{formatMs(run.queued_for_ms)}</Text>
                        </Box>
                    </HStack>
                    <HStack gap={2}>
                        <LuFileText size={14} />
                        <Box>
                            <Text fontSize="xs" color="fg.muted">Строк товаров</Text>
                            <Text fontSize="sm" fontWeight="bold">{formatNumber(run.rows_count)}</Text>
                        </Box>
                    </HStack>
                    <HStack gap={2}>
                        <LuDatabase size={14} />
                        <Box>
                            <Text fontSize="xs" color="fg.muted">Размер файла</Text>
                            <Text fontSize="sm" fontWeight="bold">{formatBytes(run.bytes)}</Text>
                        </Box>
                    </HStack>
                </HStack>

                {/* Stacked bar по этапам */}
                {segments.length > 0 && (
                    <Stack gap={2}>
                        <Text fontSize="xs" color="fg.muted" fontWeight="bold">
                            Разбивка по этапам ({formatMs(run.duration_ms)} ≈ сумма)
                        </Text>
                        <Box
                            h="14px"
                            borderRadius="md"
                            overflow="hidden"
                            display="flex"
                            border="1px solid"
                            borderColor="border.muted"
                            bg="bg"
                        >
                            {segments.map((seg) => (
                                <Box
                                    key={seg.key}
                                    bg={seg.color}
                                    style={{ width: `${seg.percent}%` }}
                                    title={`${seg.label}: ${formatMs(seg.ms)}`}
                                />
                            ))}
                        </Box>
                        <HStack gap={4} wrap="wrap">
                            {segments.map((seg) => (
                                <HStack key={seg.key} gap={2}>
                                    <Box w="10px" h="10px" borderRadius="sm" bg={seg.color} />
                                    <Text fontSize="xs" color="fg.muted">
                                        {seg.label}: <Text as="span" fontWeight="bold" color="fg">{formatMs(seg.ms)}</Text>
                                    </Text>
                                </HStack>
                            ))}
                        </HStack>
                    </Stack>
                )}

                {/* Топ полей-виновников (из per-field sampling) */}
                {Array.isArray(run.steps_json?.field_breakdown) && run.steps_json.field_breakdown.length > 0 && (
                    <Stack gap={2}>
                        <Text fontSize="xs" color="fg.muted" fontWeight="bold">
                            Топ полей по вкладу в map_rows (сэмплированный замер)
                        </Text>
                        <Box overflowX="auto">
                            <Box as="table" w="100%" fontSize="xs" style={{ borderCollapse: 'collapse' }}>
                                <Box as="thead" color="fg.muted">
                                    <Box as="tr">
                                        <Box as="th" textAlign="left" px={2} py={1}>Ключ поля</Box>
                                        <Box as="th" textAlign="right" px={2} py={1}>Среднее, мс</Box>
                                        <Box as="th" textAlign="right" px={2} py={1}>Сэмплов</Box>
                                        <Box as="th" textAlign="right" px={2} py={1}>Проекция на все строки</Box>
                                    </Box>
                                </Box>
                                <Box as="tbody">
                                    {run.steps_json.field_breakdown.map((f) => (
                                        <Box as="tr" key={f.key} _odd={{ bg: 'bg' }}>
                                            <Box as="td" px={2} py={1} fontFamily="mono">{f.key}</Box>
                                            <Box as="td" px={2} py={1} textAlign="right">{Number(f.avg_ms).toFixed(2)}</Box>
                                            <Box as="td" px={2} py={1} textAlign="right">{f.samples}</Box>
                                            <Box as="td" px={2} py={1} textAlign="right" fontWeight="bold">
                                                {formatMs(f.projected_total_ms)}
                                            </Box>
                                        </Box>
                                    ))}
                                </Box>
                            </Box>
                        </Box>
                    </Stack>
                )}

                {/* Ошибка, если упал */}
                {run.status === 'failed' && run.error_message && (
                    <Box p={3} bg="red.50" borderRadius="md" border="1px solid" borderColor="red.200">
                        <HStack gap={2} mb={1}>
                            <LuTriangleAlert size={14} color="var(--chakra-colors-red-600)" />
                            <Text fontWeight="bold" fontSize="sm" color="red.700">
                                Ошибка генерации
                            </Text>
                        </HStack>
                        <Text fontSize="xs" color="red.700" fontFamily="mono" whiteSpace="pre-wrap">
                            {run.error_message}
                        </Text>
                    </Box>
                )}
            </Stack>
        </Box>
    );
}
