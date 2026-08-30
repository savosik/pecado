import { useEffect, useState } from 'react';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import { LuBanknote, LuChevronRight } from 'react-icons/lu';
import { usePermission } from '@/shared/Panel/usePermission';

const CACHE_KEY = 'crm.salary.snippet';
const CACHE_TTL = 5 * 60 * 1000;

const fmt = (value) => `${Math.round(Number(value ?? 0)).toLocaleString('ru-RU')} ₽`;

/**
 * Зарплата в подвале меню CRM: своя цифра менеджеру, отдел — руководителю.
 *
 * Раздел «Моя зарплата» отвечает на вопрос, который менеджер задаёт себе каждый
 * день, — но заходить ради одной цифры в отдельный экран он не будет. Поэтому
 * сумма стоит в меню, а страница остаётся местом, где разбираются почему.
 *
 * Данные берутся из готовых снимков (сервер ничего не пересчитывает) и
 * кешируются в сессии: сайдбар монтируется на каждой навигации, и без кеша
 * цифра моргала бы при каждом переходе.
 */
export default function SalarySnippet() {
    const { can } = usePermission();
    const canSee = can('crm-salary.view');
    const [data, setData] = useState(() => readCache());

    useEffect(() => {
        if (!canSee) {
            return undefined;
        }

        let alive = true;
        const cached = readCache();

        if (cached && Date.now() - cached.at < CACHE_TTL) {
            return undefined;
        }

        axios.get('/crm/salary/snippet')
            .then((res) => {
                if (!alive) return;
                setData(res.data);
                try {
                    sessionStorage.setItem(CACHE_KEY, JSON.stringify({ ...res.data, at: Date.now() }));
                } catch {
                    // приватный режим — обойдёмся без кеша
                }
            })
            .catch(() => {});

        return () => { alive = false; };
    }, [canSee]);

    if (!canSee || !data || (data.rows ?? []).length === 0) {
        return null;
    }

    const team = data.mode === 'team';
    const rows = data.rows ?? [];
    const own = rows.find((r) => r.id === data.own_id) ?? rows[0];
    const total = team ? rows.reduce((acc, r) => acc + Number(r.total ?? 0), 0) : Number(own?.total ?? 0);
    const previous = team
        ? rows.reduce((acc, r) => acc + Number(r.previous_total ?? 0), 0)
        : Number(own?.previous_total ?? 0);
    const delta = previous > 0 ? (total - previous) / previous : null;

    return (
        <Box
            as={Link}
            href={team ? '/crm/salary/team' : '/crm/salary'}
            display="block"
            borderWidth="1px"
            borderColor="border"
            borderRadius="lg"
            overflow="hidden"
            bg="bg.subtle"
            _hover={{ borderColor: 'pecado.solid', bg: 'bg.panel' }}
            transition="border-color 0.15s, background 0.15s"
        >
            <Box px={3} pt={2.5} pb={2}>
                <HStack justify="space-between" gap={2} mb={1}>
                    <HStack gap={1.5} minW={0}>
                        <Box color="pecado.fg" flexShrink={0}><LuBanknote size={14} /></Box>
                        <Text fontSize="xs" fontWeight="600" lineClamp={1}>
                            {team ? 'Зарплата отдела' : 'Моя зарплата'}
                        </Text>
                    </HStack>
                    <Box color="fg.subtle" flexShrink={0}><LuChevronRight size={14} /></Box>
                </HStack>

                <HStack align="baseline" gap={2} flexWrap="wrap">
                    <Text fontSize="lg" fontWeight="800" lineHeight="1.15" fontVariantNumeric="tabular-nums">
                        {fmt(total)}
                    </Text>
                    {delta !== null && Math.abs(delta) >= 0.01 && (
                        <Text fontSize="10px" fontWeight="600" color={delta > 0 ? 'green.fg' : 'fg.muted'} fontVariantNumeric="tabular-nums">
                            {delta > 0 ? '+' : '−'}{Math.round(Math.abs(delta) * 100)} % к прошлому
                        </Text>
                    )}
                </HStack>

                <Text fontSize="10px" color="fg.subtle" mt="1px">
                    {data.month_label}{!team && own?.status === 'draft' ? ' · черновик' : ''}
                </Text>
            </Box>

            {/* Руководителю — кто сколько заработал: ради этого он и открывает раздел. */}
            {team && rows.length > 1 && (
                <VStack align="stretch" gap={0} borderTopWidth="1px" borderColor="border" px={3} py={1.5}>
                    {rows.slice(0, 5).map((row) => (
                        <HStack key={row.id} justify="space-between" gap={2} py="2px">
                            <Text fontSize="10px" color="fg.muted" lineClamp={1}>{shortName(row.name)}</Text>
                            <Text fontSize="10px" fontWeight="600" fontVariantNumeric="tabular-nums" whiteSpace="nowrap">
                                {row.total === null ? '—' : fmt(row.total)}
                            </Text>
                        </HStack>
                    ))}
                    {rows.length > 5 && (
                        <Text fontSize="10px" color="fg.subtle" pt="2px">и ещё {rows.length - 5}</Text>
                    )}
                </VStack>
            )}
        </Box>
    );
}

/** «Курочкина Елена Валерьевна» → «Курочкина Е. В.»: в колонке 240 px иначе не помещается. */
function shortName(name) {
    const parts = String(name ?? '').trim().split(/\s+/);

    if (parts.length < 2) {
        return name;
    }

    return `${parts[0]} ${parts.slice(1).map((p) => `${p[0]}.`).join(' ')}`;
}

function readCache() {
    try {
        const raw = sessionStorage.getItem(CACHE_KEY);

        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}
