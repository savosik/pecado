import { Button, Badge, HStack } from '@chakra-ui/react';
import { LuTrash2, LuList } from 'react-icons/lu';

/**
 * TrashedFilter — переключатель между активными и удалёнными записями.
 */
export const TrashedFilter = ({ trashed, trashedCount, onToggle }) => {
    if (trashed) {
        return (
            <Button size="sm" variant="solid" colorPalette="red" onClick={onToggle}>
                <LuTrash2 />
                Удалённые
                {trashedCount > 0 && (
                    <Badge colorPalette="red" variant="solid" size="sm" ms={1}>{trashedCount}</Badge>
                )}
            </Button>
        );
    }

    return (
        <Button size="sm" variant="outline" colorPalette="red" onClick={onToggle}>
            <LuTrash2 />
            Удалённые
            {trashedCount > 0 && (
                <Badge colorPalette="red" variant="subtle" size="sm" ms={1}>{trashedCount}</Badge>
            )}
        </Button>
    );
};
