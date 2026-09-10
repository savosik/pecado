import { HStack } from '@chakra-ui/react';
import { LuUser, LuUserMinus, LuUsers } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';
import { useCrmScope } from '@/Crm/hooks/useCrmScope';
import { useShowUnassigned } from '@/Crm/hooks/useShowUnassigned';

/**
 * Галочка «Только мои» — фокус раздела, запоминаемый отдельно по разделам, —
 * и рядом «Нераспределённые»: партнёры без персонального менеджера.
 *
 * Показывается лишь тому, кому есть что расфокусировать: у менеджера без права
 * на отдел она всегда была бы включённой и неактивной, то есть занимала бы место
 * и ничего не сообщала. «Нераспределённые» — та же логика: в разрезе «только мои»
 * лидов нет по определению, поэтому галочка появляется только на весь отдел.
 * В отличие от разреза она серверная и действует сразу во всех разделах.
 *
 * @param {{section: string, scope: string, available?: boolean, label?: string}} props
 */
export default function ScopeToggle({ section, scope, available = false, label = 'Только мои' }) {
    const { isMine, toggle } = useCrmScope(section, scope, available);
    const unassigned = useShowUnassigned();

    if (! available) {
        return null;
    }

    const Icon = isMine ? LuUser : LuUsers;

    return (
        <HStack gap={4}>
            <Tooltip
                content={isMine
                    ? 'Показаны только ваши записи. Снимите, чтобы увидеть весь отдел.'
                    : 'Показан весь отдел. Поставьте, чтобы вернуться к своим.'}
                openDelay={400}
            >
                <HStack gap={1.5} color={isMine ? 'fg' : 'fg.muted'}>
                    <Icon size={14} style={{ flexShrink: 0 }} />
                    <Checkbox
                        size="sm"
                        checked={isMine}
                        onCheckedChange={toggle}
                        aria-label={label}
                    >
                        {label}
                    </Checkbox>
                </HStack>
            </Tooltip>

            {! isMine && (
                <Tooltip
                    content={unassigned.enabled
                        ? 'Партнёры без персонального менеджера показаны во всех разделах CRM. Снимите, чтобы скрыть их.'
                        : 'Показать партнёров без персонального менеджера (лидов) во всех разделах CRM.'}
                    openDelay={400}
                >
                    <HStack gap={1.5} color={unassigned.enabled ? 'fg' : 'fg.muted'}>
                        <LuUserMinus size={14} style={{ flexShrink: 0 }} />
                        <Checkbox
                            size="sm"
                            checked={unassigned.enabled}
                            disabled={unassigned.busy}
                            onCheckedChange={unassigned.toggle}
                            aria-label="Нераспределённые"
                        >
                            Нераспределённые
                        </Checkbox>
                    </HStack>
                </Tooltip>
            )}
        </HStack>
    );
}
