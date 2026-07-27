import { router, Link } from '@inertiajs/react';
import { Card, HStack, VStack, Text, Button, Badge, Box } from '@chakra-ui/react';
import { LuPlus, LuPencil } from 'react-icons/lu';
import { usePermission } from '@/Admin/hooks/usePermission';

const STATUS_COLORS = {
    active: 'green',
    disabled: 'gray',
    scheduled: 'blue',
    finished: 'orange',
};

/**
 * Правила акции на странице акции.
 *
 * Рендерится ВНЕ формы с картинками: форма акции уходит как FormData, и любая
 * ошибка валидации механики роняла бы загрузку изображений и галереи.
 */
export default function PromotionRulesBlock({ promotionId, rules = [] }) {
    const { can } = usePermission();

    if (!can('promotion-rules.view')) {
        return null;
    }

    return (
        <Card.Root mt={6}>
            <Card.Header>
                <HStack justify="space-between" wrap="wrap" gap={3}>
                    <VStack align="start" gap={0}>
                        <Text fontWeight="semibold">Правила акции</Text>
                        <Text fontSize="sm" color="fg.muted">
                            Механика: условие срабатывания и промо-позиция в награду
                        </Text>
                    </VStack>

                    {can('promotion-rules.create') && (
                        <Button
                            size="sm"
                            colorPalette="blue"
                            variant="outline"
                            onClick={() => router.visit(route('admin.promotion-rules.create', { promotion_id: promotionId }))}
                        >
                            <LuPlus /> Добавить правило
                        </Button>
                    )}
                </HStack>
            </Card.Header>

            <Card.Body>
                {rules.length === 0 ? (
                    <Text color="fg.muted" fontSize="sm">
                        У акции пока нет правил — она работает как обычная контентная страница.
                    </Text>
                ) : (
                    <VStack align="stretch" gap={3}>
                        {rules.map((rule) => (
                            <Box key={rule.id} p={3} borderWidth="1px" borderRadius="md">
                                <HStack justify="space-between" align="start" wrap="wrap" gap={3}>
                                    <VStack align="start" gap={1} flex="1" minW="240px">
                                        <HStack gap={2} wrap="wrap">
                                            <Text fontWeight="medium">{rule.name}</Text>
                                            <Badge colorPalette={STATUS_COLORS[rule.status] || 'gray'} variant="subtle">
                                                {rule.status_label}
                                            </Badge>
                                            <Badge colorPalette="gray" variant="subtle">
                                                {rule.mode_label}
                                            </Badge>
                                        </HStack>
                                        <Text fontSize="sm" color="fg.muted">{rule.condition_summary}</Text>
                                        <Text fontSize="sm" color="fg.muted">→ {rule.reward_summary}</Text>
                                    </VStack>

                                    {can('promotion-rules.edit') && (
                                        <Link href={route('admin.promotion-rules.edit', rule.id)}>
                                            <Button size="sm" variant="ghost">
                                                <LuPencil /> Изменить
                                            </Button>
                                        </Link>
                                    )}
                                </HStack>
                            </Box>
                        ))}
                    </VStack>
                )}
            </Card.Body>
        </Card.Root>
    );
}
