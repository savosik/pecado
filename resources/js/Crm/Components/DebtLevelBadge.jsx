import { Badge, HStack } from '@chakra-ui/react';
import { LuLockOpen } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Ступень лестницы долга — бейдж с «почему» в подсказке.
 *
 * @param {{level: string, label: string, color: string, reason?: string, dry_run?: boolean}} debt
 * @param {{until: string}|null} pause — действующая разблокировка
 */
export default function DebtLevelBadge({ debt, pause = null, size = 'sm' }) {
    if (!debt || debt.level === 'clean') return null;

    const hint = [
        debt.reason,
        debt.dry_run ? 'Теневой расчёт: действий не было.' : null,
        pause ? `Разблокировано до ${pause.until}.` : null,
    ].filter(Boolean).join(' ');

    return (
        <Tooltip content={hint || debt.label} openDelay={300}>
            <HStack gap={1}>
                <Badge colorPalette={debt.color || 'red'} variant={debt.dry_run ? 'outline' : 'subtle'} size={size}>
                    {debt.label}
                </Badge>
                {pause && (
                    <Badge colorPalette="green" variant="subtle" size={size}>
                        <LuLockOpen size={11} /> до {pause.until}
                    </Badge>
                )}
            </HStack>
        </Tooltip>
    );
}
