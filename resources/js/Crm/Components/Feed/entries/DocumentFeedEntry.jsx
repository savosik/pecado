import { Badge, HStack, Text } from '@chakra-ui/react';
import { LuExternalLink } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import FeedEntryShell from '../FeedEntryShell';

/**
 * Заказ или реализация в ленте.
 *
 * Одной формой на оба типа: для менеджера это события одной природы — «клиент
 * заказал» и «мы отгрузили». Различает их цвет полосы и иконка, а не отдельная
 * вёрстка.
 *
 * Документ пришёл из 1С: автора нет, править нечего — поэтому и действий, кроме
 * перехода в карточку документа, у записи не бывает.
 */
export default function DocumentFeedEntry({ entry }) {
    const badges = entry.status_label
        ? (
            <Badge colorPalette={entry.status_color || 'gray'} variant="outline" size="sm">
                {entry.status_label}
            </Badge>
        )
        : null;

    return (
        <FeedEntryShell
            type={entry.type}
            author={null}
            time={entry.happened_at_label}
            title={entry.title}
            badges={badges}
            actions={entry.entity?.url ? (
                <Button size="xs" variant="ghost" asChild title="Открыть документ">
                    <a href={entry.entity.url}><LuExternalLink /></a>
                </Button>
            ) : null}
        >
            <HStack gap={3} flexWrap="wrap">
                <Text fontSize="sm" fontWeight="700">{entry.amount_label}</Text>
                {entry.items_count > 0 && (
                    <Text fontSize="xs" color="fg.muted">позиций: {entry.items_count}</Text>
                )}
                {/* Организация и склад приходят, только когда показ включён флагом:
                    гейт стоит на сервере, поэтому здесь достаточно проверки на наличие. */}
                {entry.organization && (
                    <Text fontSize="xs" color="fg.muted">
                        {entry.organization.name}
                        {entry.organization.is_stub ? ' (юрлицо не заведено)' : ''}
                    </Text>
                )}
                {entry.warehouse && (
                    <Text fontSize="xs" color="fg.muted">склад: {entry.warehouse}</Text>
                )}
            </HStack>

            {entry.excerpt && (
                <Text fontSize="xs" color="fg.muted" whiteSpace="pre-wrap">{entry.excerpt}</Text>
            )}
        </FeedEntryShell>
    );
}
