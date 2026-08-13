import { HStack } from '@chakra-ui/react';
import { LuFilterX } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { NativeSelectField, NativeSelectRoot } from '@/components/ui/native-select';

/**
 * Фильтры табличного режима.
 *
 * На доске их нет намеренно: канбан сам по себе разрез по стадиям, и вторая
 * ось фильтрации там только прячет карточки из колонок.
 */
export default function LeadsFilterBar({
    filters = {},
    stages = [],
    managers = [],
    sources = [],
    staleDays = 14,
    onChange,
    onReset,
}) {
    const dirty = Boolean(
        filters.manager_id || filters.stage_id || filters.source || filters.stale,
    );

    return (
        <HStack gap={2} wrap="wrap">
            {managers.length > 0 && (
                <NativeSelectRoot size="sm" width="auto" minW="180px">
                    <NativeSelectField
                        value={filters.manager_id ?? ''}
                        onChange={(event) => onChange({ manager_id: event.target.value || undefined })}
                    >
                        <option value="">Все менеджеры</option>
                        {managers.map((manager) => (
                            <option key={manager.id} value={manager.id}>{manager.name}</option>
                        ))}
                    </NativeSelectField>
                </NativeSelectRoot>
            )}

            <NativeSelectRoot size="sm" width="auto" minW="170px">
                <NativeSelectField
                    value={filters.stage_id ?? ''}
                    onChange={(event) => onChange({ stage_id: event.target.value || undefined })}
                >
                    <option value="">Все стадии</option>
                    {stages.map((stage) => (
                        <option key={stage.id} value={stage.id}>{stage.name}</option>
                    ))}
                </NativeSelectField>
            </NativeSelectRoot>

            {sources.length > 0 && (
                <NativeSelectRoot size="sm" width="auto" minW="170px">
                    <NativeSelectField
                        value={filters.source ?? ''}
                        onChange={(event) => onChange({ source: event.target.value || undefined })}
                    >
                        <option value="">Любой источник</option>
                        {sources.map((source) => (
                            <option key={source} value={source}>{source}</option>
                        ))}
                    </NativeSelectField>
                </NativeSelectRoot>
            )}

            <Button
                size="sm"
                variant={filters.stale ? 'solid' : 'outline'}
                colorPalette={filters.stale ? 'red' : 'gray'}
                onClick={() => onChange({ stale: filters.stale ? undefined : 1 })}
            >
                Залежались от {staleDays} дн.
            </Button>

            {dirty && (
                <Button size="sm" variant="ghost" onClick={onReset}>
                    <LuFilterX /> Сбросить
                </Button>
            )}
        </HStack>
    );
}
