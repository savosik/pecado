import { Box, Card, Stack, Text } from '@chakra-ui/react';
import { LuClock, LuUser, LuMessageSquare } from 'react-icons/lu';

export function StatusHistoryTimeline({ histories = [] }) {
    if (histories.length === 0) {
        return (
            <Card.Root>
                <Card.Header>
                    <Text fontWeight="semibold">История статусов</Text>
                </Card.Header>
                <Card.Body>
                    <Text color="gray.500">История изменения статусов пуста</Text>
                </Card.Body>
            </Card.Root>
        );
    }

    return (
        <Card.Root>
            <Card.Header>
                <Text fontWeight="semibold" fontSize="lg">
                    <LuClock style={{ display: 'inline', marginRight: '8px' }} />
                    История изменения статусов
                </Text>
            </Card.Header>
            <Card.Body>
                <Box position="relative">
                    {/* Вертикальная линия */}
                    <Box
                        position="absolute"
                        left="18px"
                        top="20px"
                        bottom="20px"
                        width="2px"
                        bg="gray.200"
                    />

                    <Stack gap={6}>
                        {histories.map((history, index) => (
                            <Box key={history.id} position="relative" pl="50px">
                                {/* Индикатор */}
                                <Box
                                    position="absolute"
                                    left="10px"
                                    top="2px"
                                    width="18px"
                                    height="18px"
                                    borderRadius="full"
                                    bg={index === 0 ? "blue.500" : "gray.300"}
                                    border="3px solid"
                                    borderColor="white"
                                    zIndex={1}
                                    display="flex"
                                    alignItems="center"
                                    justifyContent="center"
                                    fontSize="xs"
                                >
                                    {index === 0 && <Box as="span">•</Box>}
                                </Box>

                                <Stack gap={2}>
                                    <Box>
                                        <Text fontWeight="medium">
                                            {history.old_status ? (
                                                <>
                                                    <Box as="span" color="orange.600">
                                                        {history.old_status_label}
                                                    </Box>
                                                    {' → '}
                                                    <Box as="span" color="green.600">
                                                        {history.new_status_label}
                                                    </Box>
                                                </>
                                            ) : (
                                                <>
                                                    Создан со статусом{' '}
                                                    <Box as="span" color="blue.600" fontWeight="semibold">
                                                        {history.new_status_label}
                                                    </Box>
                                                </>
                                            )}
                                        </Text>
                                    </Box>

                                    <Box fontSize="sm" color="gray.600">
                                        <LuUser style={{ display: 'inline', marginRight: '4px' }} />
                                        {history.user_name} • {history.created_at_human}
                                    </Box>

                                    {history.comment && (
                                        <Box
                                            fontSize="sm"
                                            bg="gray.50"
                                            p={3}
                                            borderRadius="md"
                                            borderLeftWidth="3px"
                                            borderLeftColor="blue.400"
                                            mt={1}
                                        >
                                            <Stack direction="row" align="flex-start" gap={2}>
                                                <LuMessageSquare style={{ marginTop: '2px', flexShrink: 0 }} />
                                                <Text>{history.comment}</Text>
                                            </Stack>
                                        </Box>
                                    )}
                                </Stack>
                            </Box>
                        ))}
                    </Stack>
                </Box>
            </Card.Body>
        </Card.Root>
    );
}
