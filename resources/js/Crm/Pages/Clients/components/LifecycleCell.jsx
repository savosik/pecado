import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Badge, HStack } from '@chakra-ui/react';
import { MenuContent, MenuItem, MenuItemGroup, MenuRoot, MenuSeparator, MenuTrigger } from '@/components/ui/menu';
import { Tooltip } from '@/components/ui/tooltip';
import { toastSuccess } from '@/utils/toast';

/**
 * Стадии по группам («Работаем с партнёром» / «Больше не покупает») в порядке
 * бэкенда: порядок вариантов задаёт перечисление на сервере, и сортировать их
 * здесь заново значило бы завести вторую лестницу стадий.
 *
 * @param {Array<{value: string, label: string, group_label?: string}>} options
 * @returns {Array<[string, Array<object>]>}
 */
function groupOptions(options = []) {
    const groups = [];

    options.forEach((option) => {
        const label = option.group_label || 'Стадии';
        const found = groups.find(([name]) => name === label);

        found ? found[1].push(option) : groups.push([label, [option]]);
    });

    return groups;
}

/**
 * Стадия партнёра прямо в таблице.
 *
 * Меняется тем же эндпоинтом, что и в карточке (`crm.clients.lifecycle.update`),
 * поэтому журнал смен ведётся одинаково независимо от того, откуда нажали.
 * Причину из таблицы не спрашиваем: диалог ради одного поля на каждый клик
 * убил бы весь смысл быстрой смены — развёрнутая смена с причиной осталась
 * в карточке.
 *
 * @param {number} clientId
 * @param {{status: string, label: string, color: string, hint: object|null}} lifecycle
 * @param {Array<{value: string, label: string, color: string}>} options
 * @param {boolean} canEdit — право crm-profile.edit
 * @param {Array<string>} reloadOnly — какие props перечитать после смены. По
 *   умолчанию список партнёров; в карточке стадия живёт в других props, и
 *   зашитый `clients` возвращал бы пустой ответ — бейдж застывал бы на старом
 *   значении при уже изменённом статусе.
 */
export default function LifecycleCell({
    clientId,
    lifecycle,
    options = [],
    canEdit,
    reloadOnly = ['clients'],
}) {
    const [busy, setBusy] = useState(false);

    if (!lifecycle) return null;

    const badge = (
        <HStack gap={1}>
            <Badge colorPalette={lifecycle.color || 'gray'} variant="subtle">
                {lifecycle.label || '—'}
            </Badge>
            {lifecycle.hint && (
                <Badge colorPalette="orange" variant="outline" size="sm">
                    !
                </Badge>
            )}
        </HStack>
    );

    if (!canEdit || options.length === 0) {
        return lifecycle.hint
            ? <Tooltip content={`Система предлагает стадию «${lifecycle.hint.label}»`} openDelay={300}>{badge}</Tooltip>
            : badge;
    }

    const change = (status, reason) => {
        if (status === lifecycle.status) return;

        setBusy(true);
        router.put(route('crm.clients.lifecycle.update', clientId), {
            lifecycle_status: status,
            reason: reason || '',
        }, {
            preserveScroll: true,
            preserveState: true,
            only: reloadOnly,
            onSuccess: () => toastSuccess('Стадия изменена'),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <MenuRoot>
            <MenuTrigger asChild disabled={busy}>
                <HStack
                    gap={1}
                    cursor="pointer"
                    borderRadius="md"
                    px={1}
                    py={0.5}
                    _hover={{ bg: 'bg.muted' }}
                    opacity={busy ? 0.5 : 1}
                    title="Сменить стадию"
                >
                    {badge}
                </HStack>
            </MenuTrigger>
            <MenuContent>
                {lifecycle.hint && (
                    <>
                        <MenuItem
                            value="hint"
                            onClick={() => change(lifecycle.hint.status, 'по подсказке системы')}
                        >
                            Применить подсказку: {lifecycle.hint.label}
                        </MenuItem>
                        <MenuSeparator />
                    </>
                )}
                {groupOptions(options).map(([groupLabel, items]) => (
                    <MenuItemGroup key={groupLabel} title={groupLabel}>
                        {items.map((option) => (
                            <MenuItem
                                key={option.value}
                                value={option.value}
                                disabled={option.value === lifecycle.status}
                                onClick={() => change(option.value)}
                                title={option.description || undefined}
                            >
                                {option.label}
                            </MenuItem>
                        ))}
                    </MenuItemGroup>
                ))}
            </MenuContent>
        </MenuRoot>
    );
}
