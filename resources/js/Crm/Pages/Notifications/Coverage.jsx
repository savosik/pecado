import { Head, Link } from '@inertiajs/react';
import { Badge, Box, Card, Flex, HStack, Heading, Progress, Table, Text, VStack } from '@chakra-ui/react';
import { LuTriangleAlert } from 'react-icons/lu';
import CrmLayout from '@/Crm/Layouts/CrmLayout';
import SectionHeader from './components/SectionHeader';

/**
 * Покрытие адресной книги.
 *
 * Главный риск домена — правило «бухгалтеру этого контрагента» молчит,
 * если бухгалтеров не завели. Движок при этом исправно работает, и дыра
 * выясняется через жалобу клиента. Экран делает её видимой цифрой.
 */
export default function Coverage({ rows, contactsTotal, companiesTotal }) {
    const percent = (row) => (row.total === 0 ? 0 : Math.round((row.covered / row.total) * 100));

    return (
        <CrmLayout>
            <Head title="Уведомления — кому некому писать" />

            <VStack align="stretch" gap={5}>
                <SectionHeader
                    title="Кому некому писать"
                    purpose="Правило вида «бухгалтеру этого контрагента» работает только там, где бухгалтер заведён. Здесь видно, у скольких контрагентов такого контакта нет."
                    control="Находите дыры в адресной книге. Пустая строка означает: правило есть, оно исправно работает, но письмо уходить некому — и без этого экрана вы узнали бы об этом от клиента."
                />

                <Flex gap={4} wrap="wrap">
                    <Card.Root flex="1 1 220px">
                        <Card.Body>
                            <Text fontSize="xs" color="fg.muted">Контрагентов</Text>
                            <Heading size="lg">{companiesTotal}</Heading>
                        </Card.Body>
                    </Card.Root>
                    <Card.Root flex="1 1 220px">
                        <Card.Body>
                            <Text fontSize="xs" color="fg.muted">Контактов с почтой</Text>
                            <Heading size="lg">{contactsTotal}</Heading>
                        </Card.Body>
                    </Card.Root>
                </Flex>

                {rows.length === 0 ? (
                    <Card.Root>
                        <Card.Body>
                            <Text color="fg.muted">
                                Правил-политик с адресатом-ролью пока нет. Такое правило заводится
                                один раз и покрывает всех партнёров:{' '}
                                <Link href="/crm/notifications/rules" style={{ textDecoration: 'underline' }}>
                                    создать правило
                                </Link>.
                            </Text>
                        </Card.Body>
                    </Card.Root>
                ) : (
                    <Card.Root>
                        <Card.Body>
                            <Table.Root size="sm" variant="outline">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeader>Правило</Table.ColumnHeader>
                                        <Table.ColumnHeader>Событие</Table.ColumnHeader>
                                        <Table.ColumnHeader>Кому</Table.ColumnHeader>
                                        <Table.ColumnHeader>Покрыто</Table.ColumnHeader>
                                        <Table.ColumnHeader>Без адресата</Table.ColumnHeader>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {rows.map((row) => (
                                        <Table.Row key={row.rule_id}>
                                            <Table.Cell fontSize="sm">{row.rule_name}</Table.Cell>
                                            <Table.Cell fontSize="sm" color="fg.muted">{row.event_label}</Table.Cell>
                                            <Table.Cell>
                                                <HStack gap={1} wrap="wrap">
                                                    {row.roles.map((role) => (
                                                        <Badge key={role} variant="subtle">{role}</Badge>
                                                    ))}
                                                </HStack>
                                            </Table.Cell>
                                            <Table.Cell minW="180px">
                                                <Text fontSize="sm" mb={1}>
                                                    {row.covered} из {row.total}
                                                </Text>
                                                <Progress.Root
                                                    value={percent(row)}
                                                    size="sm"
                                                    colorPalette={percent(row) > 70 ? 'green' : percent(row) > 30 ? 'orange' : 'red'}
                                                >
                                                    <Progress.Track>
                                                        <Progress.Range />
                                                    </Progress.Track>
                                                </Progress.Root>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {row.uncovered > 0 ? (
                                                    <HStack gap={1}>
                                                        <LuTriangleAlert size={14} color="orange" />
                                                        <Text fontSize="sm">{row.uncovered}</Text>
                                                    </HStack>
                                                ) : (
                                                    <Text fontSize="sm" color="fg.muted">—</Text>
                                                )}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>

                            <Box mt={4} borderWidth="1px" borderRadius="md" p={3} bg="bg.subtle">
                                <Text fontSize="sm">
                                    Контакты добавляются на вкладке «Контакты» карточки партнёра или
                                    контрагента. Там же есть распознавание из профиля CRM — оно создаёт
                                    черновики, которые остаётся проверить и включить.
                                </Text>
                            </Box>
                        </Card.Body>
                    </Card.Root>
                )}
            </VStack>
        </CrmLayout>
    );
}
