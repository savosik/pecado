import { useState, useCallback } from 'react';
import { Dialog, Portal, CloseButton, Text, Spinner, Box, Flex } from '@chakra-ui/react';
import ContentRenderer from '@/components/content/ContentRenderer';

/**
 * Открывает CMS-страницу (политику, согласие и т.п.) во встроенной модалке,
 * не уводя пользователя со страницы (важно в формах с конверсией).
 *
 * Контент грузится лениво через /api/pages/{slug} при первом открытии.
 *
 * @param {{
 *   slug: string,
 *   triggerLabel: import('react').ReactNode,
 *   triggerColor?: string,
 * }} props
 */
export default function PolicyDialog({ slug, triggerLabel, triggerColor = '#9e1b32' }) {
    const [open, setOpen] = useState(false);
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    const ensureLoaded = useCallback(() => {
        if (data || loading) return;
        setLoading(true);
        setError(null);
        fetch(`/api/pages/${slug}`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : Promise.reject(new Error('http_error'))))
            .then(setData)
            .catch(() => setError('Не удалось загрузить документ. Попробуйте позже.'))
            .finally(() => setLoading(false));
    }, [slug, data, loading]);

    const handleTriggerClick = useCallback(
        (e) => {
            e.preventDefault();
            e.stopPropagation();
            setOpen(true);
            ensureLoaded();
        },
        [ensureLoaded]
    );

    return (
        <>
            <Text
                as="span"
                role="button"
                tabIndex={0}
                onClick={handleTriggerClick}
                onKeyDown={(e) => {
                    if (e.key === 'Enter' || e.key === ' ') {
                        handleTriggerClick(e);
                    }
                }}
                cursor="pointer"
                color={triggerColor}
                textDecoration="underline"
                _hover={{ opacity: 0.8 }}
            >
                {triggerLabel}
            </Text>

            <Dialog.Root
                open={open}
                onOpenChange={({ open: isOpen }) => setOpen(isOpen)}
                size="xl"
                scrollBehavior="inside"
            >
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content maxW="820px">
                            <Dialog.Header>
                                <Dialog.Title>{data?.title || 'Документ'}</Dialog.Title>
                                <Dialog.CloseTrigger asChild>
                                    <CloseButton size="sm" />
                                </Dialog.CloseTrigger>
                            </Dialog.Header>
                            <Dialog.Body>
                                {loading && (
                                    <Flex justify="center" py="8">
                                        <Spinner />
                                    </Flex>
                                )}
                                {error && (
                                    <Text color="red.500" fontSize="sm">
                                        {error}
                                    </Text>
                                )}
                                {data && !loading && (
                                    <Box fontSize="sm">
                                        <ContentRenderer content={data.content} proseSize="md" />
                                    </Box>
                                )}
                            </Dialog.Body>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>
        </>
    );
}
