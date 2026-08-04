import { router } from '@inertiajs/react';
import { Badge, HStack, Text } from '@chakra-ui/react';
import { LuExternalLink } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import FeedEntryShell from '../FeedEntryShell';

/**
 * Письмо в ленте.
 *
 * Тело не показываем — только тему и получателей: HTML целиком раздувал бы страницу
 * и требовал бы санитайзинга на каждой записи. Открывается письмо в журнале.
 */
export default function EmailFeedEntry({ entry }) {
    const email = entry.email;

    if (!email) return null;

    const badges = (
        <Badge colorPalette={email.status_color} variant="subtle" size="sm">
            {email.status_label}
        </Badge>
    );

    return (
        <FeedEntryShell
            type="email"
            author={entry.author?.name}
            time={entry.happened_at_label}
            title={email.subject}
            badges={badges}
            accent={email.status === 'failed' ? 'red' : null}
            actions={(
                <Button
                    size="xs"
                    variant="ghost"
                    onClick={() => router.visit(route('crm.emails.index', { email: email.id }))}
                    title="Открыть письмо"
                >
                    <LuExternalLink />
                </Button>
            )}
        >
            <HStack gap={3} flexWrap="wrap">
                <Text fontSize="xs" color="fg.muted">{entry.excerpt}</Text>
            </HStack>

            {email.error && <Text fontSize="xs" color="red.500">{email.error}</Text>}
        </FeedEntryShell>
    );
}
