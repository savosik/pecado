import { Box, Flex, Text, Badge } from '@chakra-ui/react';
import { Checkbox } from '@/components/ui/checkbox';

/**
 * Мультиселект регионов для админки.
 *
 * Props:
 *   regions  — список всех доступных регионов [{id, name}]
 *   value    — массив выбранных id регионов [1, 3, ...]
 *   onChange — callback при изменении выбора
 */
export default function RegionSelector({ regions = [], value = [], onChange }) {
    const handleToggle = (regionId) => {
        const isSelected = value.includes(regionId);
        if (isSelected) {
            onChange(value.filter((id) => id !== regionId));
        } else {
            onChange([...value, regionId]);
        }
    };

    if (!regions || regions.length === 0) {
        return (
            <Text color="fg.muted" fontSize="sm">
                Регионы не настроены
            </Text>
        );
    }

    return (
        <Box>
            <Flex gap={2} mb={2} flexWrap="wrap">
                {value.length === 0 && (
                    <Badge colorPalette="green" variant="subtle" size="sm">
                        Все регионы
                    </Badge>
                )}
                {value.length > 0 &&
                    regions
                        .filter((r) => value.includes(r.id))
                        .map((r) => (
                            <Badge key={r.id} colorPalette="blue" variant="subtle" size="sm">
                                {r.name}
                            </Badge>
                        ))}
            </Flex>
            <Flex direction="column" gap={1}>
                {regions.map((region) => (
                    <Checkbox
                        key={region.id}
                        checked={value.includes(region.id)}
                        onCheckedChange={() => handleToggle(region.id)}
                    >
                        {region.name}
                    </Checkbox>
                ))}
            </Flex>
        </Box>
    );
}
