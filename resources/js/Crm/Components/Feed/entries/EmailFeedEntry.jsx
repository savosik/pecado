import { router } from '@inertiajs/react';
import { Badge, Box, HStack, Text, VStack } from '@chakra-ui/react';
import { Button } from '@/components/ui/button';

/**
 * Письмо в ленте.
 *
 * Тело не показываем — только тему и получателей: HTML целиком раздувал бы
 * страницу и требовал бы санитайзинга на каждой записи. Открывается письмо
 * в журнале.
 */
export default function EmailFeedEntry({ entry }) {
    const email = entry.email;

    if (!email) return null;

    return (
        <Box borderWidth="1px" borderRadius="md" p={3}>
            <VStack align="stretch" gap={2}>
                <HStack gap={2} flexWrap="wrap">
                    <Badge colorPalette="teal" variant="subtle" size="sm">Письмо</Badge>
                    <Badge colorPalette={email.status_color} variant="subtle" size="sm">
                        {email.status_label}
                    </Badge>
                    <Text fontSize="xs" color="fg.muted">
                        {entry.author?.name}, {entry.happened_at_label}
                    </Text>
                </HStack>

                <Text fontSize="sm" fontWeight="600">{email.subject}</Text>
                <Text fontSize="xs" color="fg.muted">{entry.excerpt}</Text>
                {email.error && <Text fontSize="xs" color="red.500">{email.error}</Text>}

                <HStack>
                    <Button
                        size="xs"
                        variant="ghost"
                        onClick={() => router.visit(route('crm.emails.index', { email: email.id }))}
                    >
                        Открыть письмо
                    </Button>
                </HStack>
            </VStack>
        </Box>
    );
}
