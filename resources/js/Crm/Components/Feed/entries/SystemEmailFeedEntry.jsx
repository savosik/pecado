import { Text } from '@chakra-ui/react';
import FeedEntryShell from '../FeedEntryShell';

/**
 * Письмо, отправленное сайтом, а не менеджером.
 *
 * Автора нет и открыть нечего: журнал хранит факт отправки, а не текст письма.
 * Показываем тему и получателя — этого хватает, чтобы ответить на вопрос
 * «клиенту вообще уходило подтверждение заказа и куда именно».
 */
export default function SystemEmailFeedEntry({ entry }) {
    return (
        <FeedEntryShell
            type="system_email"
            time={entry.happened_at_label}
            title={entry.title}
        >
            <Text fontSize="xs" color="fg.muted">{entry.excerpt}</Text>
        </FeedEntryShell>
    );
}
