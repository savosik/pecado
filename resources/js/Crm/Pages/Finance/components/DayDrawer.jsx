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
import MetricHint from '@/Crm/Components/MetricHint';
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
                        <Figure
                            label={isPast ? 'осталось' : 'ожидается'}
                            value={formatRub(planTotal)}
                            hint={isPast
                                ? 'Сколько из назначенного на этот день так и не закрыли. Строки, которые оплатили, отсюда убраны — требовать по ним нечего.'
                                : 'Сколько партнёры должны заплатить в этот день по графику из 1С. Уже оплаченные строки сюда не входят: это то, что ещё ждём.'}
                        />
                        <Figure
                            label="поступило"
                            value={formatRub(factTotal)}
                            hint="Сколько денег реально пришло в этот день. С суммой слева совпадать не обязано: платят и по старым долгам, и по документам с другими сроками. Это два разных числа, а не проверка одного другим."
                        />
                    </HStack>

                    {/* Исполнение — только на прошедших днях: у будущего срок ещё
                        не наступил, и «закрыто 0 %» читалось бы как тревога. */}
                    {isPast && scheduled > 0 && (
                        <HStack gap={4} mt={1} wrap="wrap">
                            <Figure
                                label="по графику было"
                                value={formatRub(scheduled)}
                                hint="Сколько всего 1С назначила к оплате на этот день — вместе с тем, что уже заплатили. Число не меняется со временем: это сам график."
                            />
                            <Figure
                                label="закрыто"
                                value={`${formatRub(settled)} (${executed}%)`}
                                tone={executed >= 80 ? 'green.fg' : 'orange.fg'}
                                hint="Какую часть графика этого дня 1С отметила оплаченной. Закрыть строку может не только платёж, но и зачёт аванса, — поэтому число не обязано совпадать с «поступило»."
                            />
                        </HStack>
                    )}
                </DrawerHeader>

                <DrawerBody>
                    {/* Пояснение внутри панели, а не в подсказках: тут рядом стоят
                        три величины разного смысла, и без пары фраз они читаются
                        как три варианта одного числа, которые почему-то не сошлись. */}
                    <Box borderWidth="1px" borderRadius="md" px={3} py={2} mb={4} bg="bg.subtle">
                        <Text fontSize="11px" color="fg.muted" lineHeight="1.5">
                            Ниже два списка. <b>Сверху</b> — кто и по каким реализациям должен
                            заплатить {isPast ? 'был в этот день, но ещё не заплатил' : 'в этот день'};
                            это график из 1С, а не наша оценка. <b>Снизу</b> — какие деньги в этот
                            день пришли и за какие документы. Списки не связаны: партнёр может
                            заплатить сегодня по документу с другим сроком, и наоборот.
                            Рядом с каждым партнёром — его общий долг, доля просрочки в нём
                            и последний платёж; наведите, чтобы увидеть расшифровку.
                        </Text>
                    </Box>

                    <Section
                        title={isPast ? 'Осталось незакрытым' : 'Ожидается по графику'}
                        hint={isPast
                            ? 'Строки графика с этой датой, по которым деньги так и не пришли. Оплаченные не показываем: по ним вопрос закрыт. Суммы — остаток по строке, а не вся её сумма.'
                            : 'Строки графика оплаты из 1С с этой датой. Суммы — непогашенный остаток по каждой реализации. Заказы сюда не попадают: обязательство создаёт отгрузка, и у реализации будет свой график.'}
                        groups={plan}
                        snapshots={snapshots}
                        empty={isPast
                            ? 'Всё, что ждали в этот день, уже закрыто'
                            : 'В этот день платежей по графику не назначено'}
                    />

                    <Box mt={6}>
                        <Section
                            title="Поступило"
                            hint="Платежи с этой датой. Под каждым — за какие документы 1С их разнесла: один платёж часто закрывает десяток реализаций сразу. Возврат денег показан со знаком минус."
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

/**
 * «по 7 документам» — из чего сложилась сумма партнёра.
 *
 * Отдельная форма нужна только единице («по 1 документу»); 11 и 21 ведут себя
 * по-разному, поэтому проверка идёт по остатку от сотни, а не от десятки.
 */
const documentsLabel = (count) => {
    const single = count % 100 < 11 || count % 100 > 19 ? count % 10 === 1 : false;

    return `по ${count} ${single ? 'документу' : 'документам'}`;
};

/** Число в шапке дня с подписью и подсказкой «что это». */
function Figure({ label, value, hint, tone }) {
    return (
        <HStack gap={1}>
            <Text fontSize="xs" color={tone ?? 'fg.muted'}>
                {label} <b>{value}</b>
            </Text>
            <MetricHint text={hint} label="Что это за число" />
        </HStack>
    );
}

function Section({ title, hint, groups, snapshots, empty }) {
    return (
        <>
            <HStack gap={1} mb={2}>
                <Text fontWeight="600" fontSize="sm">{title}</Text>
                {hint && <MetricHint text={hint} />}
            </HStack>

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
                                <VStack align="end" gap={0}>
                                    <Text fontSize="sm" fontWeight="700" whiteSpace="nowrap">
                                        {formatRub(group.amount)}
                                    </Text>
                                    <Text fontSize="10px" color="fg.muted" whiteSpace="nowrap">
                                        {documentsLabel(group.documents.length)}
                                    </Text>
                                </VStack>
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
