import { useState } from 'react';
import { Box, HStack, Text } from '@chakra-ui/react';
import { EntitySelector } from '@/Admin/Components/EntitySelector';
import { Button } from '@/components/ui/button';

const controlStyle = {
    padding: '0.5rem',
    borderRadius: '0.375rem',
    border: '1px solid var(--chakra-colors-border)',
    minWidth: '170px',
};

/**
 * Выбор «к кому и кем привязать».
 *
 * Один и тот же блок в двух местах: при создании человека из справочника
 * и на его карточке. Иначе привязку можно было бы завести только с карточки
 * партнёра, а из справочника — никак.
 */
export default function ContactLinkPicker({
    types = [],
    roles = [],
    onSubmit,
    submitLabel = 'Привязать',
    busy = false,
    compact = false,
}) {
    const [type, setType] = useState(types[0]?.value || 'contractor');
    const [entity, setEntity] = useState(null);
    const [role, setRole] = useState(roles[0]?.value || 'manager');

    const submit = () => {
        if (!entity) {
            return;
        }

        onSubmit({ entity_type: type, entity_id: entity.id, role });
        setEntity(null);
    };

    return (
        <HStack gap={2} flexWrap="wrap" align="end">
            <Box>
                {!compact && <Text fontSize="xs" color="fg.muted" mb={1}>К кому</Text>}
                <select
                    value={type}
                    onChange={(e) => { setType(e.target.value); setEntity(null); }}
                    style={controlStyle}
                >
                    {types.map((item) => (
                        <option key={item.value} value={item.value}>{item.label}</option>
                    ))}
                </select>
            </Box>

            <Box minW="240px" flex="1">
                {!compact && <Text fontSize="xs" color="fg.muted" mb={1}>Кого именно</Text>}
                <EntitySelector
                    key={type}
                    value={entity}
                    onChange={setEntity}
                    searchUrl={route('crm.contacts.entities')}
                    searchParams={{ type }}
                    placeholder="Начните вводить название или номер..."
                />
            </Box>

            <Box>
                {!compact && <Text fontSize="xs" color="fg.muted" mb={1}>Кем приходится</Text>}
                <select value={role} onChange={(e) => setRole(e.target.value)} style={controlStyle}>
                    {roles.map((item) => (
                        <option key={item.value} value={item.value}>{item.label}</option>
                    ))}
                </select>
            </Box>

            <Button size="sm" onClick={submit} disabled={!entity || busy} loading={busy}>
                {submitLabel}
            </Button>
        </HStack>
    );
}
