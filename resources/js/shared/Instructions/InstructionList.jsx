import { HStack, SimpleGrid, Text } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuBookOpen } from 'react-icons/lu';
import EmptyState from '@/components/common/EmptyState';
import { Button } from '@/components/ui/button';
import InstructionCard from './InstructionCard';

/**
 * Сетка инструкций с постраничной навигацией — общая для кабинета, CRM и WMS.
 *
 * @param {{instructions: {data: Array, prev_page_url?: string, next_page_url?: string, current_page?: number, last_page?: number}, columns?: object}} props
 */
export default function InstructionList({ instructions, columns = { base: 1, sm: 2, lg: 3 } }) {
    const items = instructions?.data ?? [];

    if (items.length === 0) {
        return (
            <EmptyState
                icon={LuBookOpen}
                title="Инструкций пока нет"
                description="Как только появятся — они будут здесь."
            />
        );
    }

    const hasPages = (instructions.last_page ?? 1) > 1;

    return (
        <>
            <SimpleGrid columns={columns} gap="5">
                {items.map((item) => <InstructionCard key={item.id} item={item} />)}
            </SimpleGrid>

            {hasPages && (
                <HStack justify="center" gap="3" mt="6">
                    <Link href={instructions.prev_page_url || '#'} preserveScroll>
                        <Button size="sm" variant="outline" disabled={!instructions.prev_page_url}>← Назад</Button>
                    </Link>
                    <Text fontSize="sm" color="fg.muted">
                        Страница {instructions.current_page} из {instructions.last_page}
                    </Text>
                    <Link href={instructions.next_page_url || '#'} preserveScroll>
                        <Button size="sm" variant="outline" disabled={!instructions.next_page_url}>Вперёд →</Button>
                    </Link>
                </HStack>
            )}
        </>
    );
}
