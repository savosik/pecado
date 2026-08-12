import { HStack } from '@chakra-ui/react';
import { LuUser, LuUsers } from 'react-icons/lu';
import { Checkbox } from '@/components/ui/checkbox';
import { Tooltip } from '@/components/ui/tooltip';
import { useCrmScope } from '@/Crm/hooks/useCrmScope';

/**
 * Галочка «Только мои» — фокус раздела, запоминаемый отдельно по разделам.
 *
 * Показывается лишь тому, кому есть что расфокусировать: у менеджера без права
 * на отдел она всегда была бы включённой и неактивной, то есть занимала бы место
 * и ничего не сообщала.
 *
 * @param {{section: string, scope: string, available?: boolean, label?: string}} props
 */
export default function ScopeToggle({ section, scope, available = false, label = 'Только мои' }) {
    const { isMine, toggle } = useCrmScope(section, scope, available);

    if (! available) {
        return null;
    }

    const Icon = isMine ? LuUser : LuUsers;

    return (
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
    );
}
