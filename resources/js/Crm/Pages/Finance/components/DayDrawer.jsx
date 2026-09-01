import { Box, HStack, Text, VStack } from '@chakra-ui/react';
import {
    DrawerBackdrop,
    DrawerBody,
    DrawerCloseTrigger,
    DrawerContent,
    DrawerHeader,
    DrawerRoot,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Tooltip } from '@/components/ui/tooltip';
import PartnerFinanceCell from './PartnerFinanceCell';
import { formatRub } from './format';

/**
 * День календаря: кто заплатит и кто заплатил.
 *
 * Строки сгруппированы «партнёр → документы», а не выложены плоским списком:
 * 1С разносит один платёж на десяток реализаций, и в денежный день это сотня
 * строк от десятка партнёров. Вопрос «кто платит» важнее вопроса «по какой
 * строке», поэтому сначала партнёр, а документы — под ним.
 */
export default function DayDrawer({ date, plan = [], facts = [], snapshots = {}, totals = null, isPast = false, onClose }) {
    const planTotal = plan.reduce((sum, group) => sum + group.amount, 0);
    const factTotal = facts.reduce((sum, group) => sum + group.amount, 0);
    const scheduled = totals?.scheduled ?? 0;
    const settled = totals?.settled ?? 0;
    const executed = scheduled > 0 ? Math.round((settled / scheduled) * 100) : null;

    return (
        <DrawerRoot open={Boolean(date)} onOpenChange={(event) => { if (!event.open) onClose(); }} size="lg">
            <DrawerBackdrop />
            <DrawerContent>
                <DrawerHeader>
                    <DrawerTitle>{date ? date.split('-').reverse().join('.') : ''}</DrawerTitle>
                    <HStack gap={4} mt={1} wrap="wrap">
                        <Text fontSize="xs" color="fg.muted">
                            {isPast ? 'осталось' : 'ожидается'} <b>{formatRub(planTotal)}</b>
                        </Text>
                        <Text fontSize="xs" color="fg.muted">
                            поступило <b>{formatRub(factTotal)}</b>
                        </Text>
                    </HStack>

                    {/* Исполнение — только на прошедших днях: у будущего срок ещё
                        не наступил, и «закрыто 0 %» читалось бы как тревога. */}
                    {isPast && scheduled > 0 && (
                        <HStack gap={4} mt={1} wrap="wrap">
                            <Text fontSize="xs" color="fg.muted">
                                по графику было <b>{formatRub(scheduled)}</b>
                            </Text>
                            <Text fontSize="xs" color={executed >= 80 ? 'green.fg' : 'orange.fg'}>
                                закрыто <b>{formatRub(settled)}</b> ({executed}%)
                            </Text>
                        </HStack>
                    )}
                </DrawerHeader>

                <DrawerBody>
                    <Section
                        title={isPast ? 'Осталось незакрытым' : 'Ожидается по графику'}
                        groups={plan}
                        snapshots={snapshots}
                        empty={isPast
                            ? 'Всё, что ждали в этот день, уже закрыто'
                            : 'В этот день платежей по графику не назначено'}
                    />

                    <Box mt={6}>
                        <Section
                            title="Поступило"
                            groups={facts}
                            snapshots={snapshots}
                            empty="В этот день денег не поступало"
                        />
                    </Box>
                </DrawerBody>

                <DrawerCloseTrigger />
            </DrawerContent>
        </DrawerRoot>
    );
}

function Section({ title, groups, snapshots, empty }) {
    return (
        <>
            <Text fontWeight="600" fontSize="sm" mb={2}>{title}</Text>

            {groups.length === 0 && (
                <Text fontSize="sm" color="fg.muted" py={4}>{empty}</Text>
            )}

            <VStack align="stretch" gap={3}>
                {groups.map((group) => (
                    <Box key={group.user_id} borderWidth="1px" borderColor="border.muted" borderRadius="md" p={3}>
                        <HStack justify="space-between" align="start" gap={3} mb={2} wrap="wrap">
                            <VStack align="start" gap={0}>
                                <Box
                                    as="a"
                                    href={group.url}
                                    fontSize="sm"
                                    fontWeight="600"
                                    _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
                                >
                                    {group.title}
                                </Box>
                                {group.manager_name && (
                                    <Text fontSize="10px" color="fg.muted">{group.manager_name}</Text>
                                )}
                            </VStack>

                            <HStack gap={3} align="start">
                                <PartnerFinanceCell finance={snapshots[group.user_id]} compact />
                                <Text fontSize="sm" fontWeight="700" whiteSpace="nowrap">
                                    {formatRub(group.amount)}
                                </Text>
                            </HStack>
                        </HStack>

                        <VStack align="stretch" gap={1}>
                            {group.documents.map((doc, index) => (
                                <HStack key={`${doc.number}-${index}`} justify="space-between" gap={3}>
                                    <HStack gap={1} minW={0}>
                                        <Text fontSize="xs" color="fg.muted" whiteSpace="nowrap">
                                            {doc.kind_label}
                                        </Text>
                                        <DocumentNumber doc={doc} />
                                        {doc.date && (
                                            <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                                                от {doc.date}
                                            </Text>
                                        )}
                                        {doc.is_return && (
                                            <Text fontSize="10px" color="red.fg">возврат</Text>
                                        )}
                                    </HStack>

                                    <Text
                                        fontSize="xs"
                                        whiteSpace="nowrap"
                                        color={doc.amount < 0 ? 'red.fg' : undefined}
                                    >
                                        {formatRub(doc.amount)}
                                    </Text>
                                </HStack>
                            ))}
                        </VStack>
                    </Box>
                ))}
            </VStack>
        </>
    );
}

/**
 * Номер документа: ссылка, если карточка нашлась, иначе — текст с пояснением.
 *
 * Молчать о ненайденном документе нельзя: за деньгами всегда стоит документ,
 * и «—» на его месте выглядит потерей данных, а не отсутствием карточки.
 */
function DocumentNumber({ doc }) {
    if (doc.url) {
        return (
            <Box
                as="a"
                href={doc.url}
                fontSize="xs"
                fontWeight="600"
                _hover={{ color: 'blue.fg', textDecoration: 'underline' }}
            >
                {doc.number}
            </Box>
        );
    }

    if (!doc.unmatched_hint) {
        return <Text fontSize="xs" fontWeight="600">{doc.number}</Text>;
    }

    return (
        <Tooltip content={doc.unmatched_hint}>
            <Text fontSize="xs" fontWeight="600" borderBottomWidth="1px" borderStyle="dotted" cursor="help">
                {doc.number}
            </Text>
        </Tooltip>
    );
}
