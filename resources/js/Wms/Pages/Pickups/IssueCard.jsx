import { useState } from 'react';
import { Badge, Box, Card, HStack, Input, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuPackageCheck } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import RowActions from '@/shared/Panel/RowActions';
import { placesText, waitingText } from './pickupUtils';

/**
 * Карточка расходного ордера в очереди выдачи. Кнопка «Выдать» — крупная: экран работает
 * с телефона у стойки. Детали (имя курьера, комментарий) раскрываются только для выдачи
 * без пропуска, где они и нужны.
 */
export default function IssueCard({ row, canIssue, onIssue, busy = false, extra = null, footer = null }) {
    const [open, setOpen] = useState(false);
    const [recipient, setRecipient] = useState('');
    const [comment, setComment] = useState('');

    const orders = row.orders || [];
    const orderText = orders.length > 3
        ? `${orders.slice(0, 2).map((o) => o.number).join(', ')} и ещё ${orders.length - 2}`
        : orders.map((o) => o.number).join(', ');

    return (
        <Card.Root size="sm" variant="outline">
            <Card.Body gap="2">
                <HStack justify="space-between" align="flex-start" gap="2">
                    <Box minW="0">
                        <Text fontWeight="700" fontSize="md" lineClamp="2">{row.client}</Text>
                        {row.company && <Text fontSize="sm" color="fg.muted" lineClamp="1">{row.company}</Text>}
                    </Box>
                    <HStack gap="1" flexShrink={0}>
                        {row.packages_count > 0 && <Badge size="lg" variant="subtle">{placesText(row.packages_count)}</Badge>}
                        <RowActions view={{ href: `/wms/goods-issues/${row.id}`, permission: 'wms-goods-issues.view', label: 'Открыть ордер' }} />
                    </HStack>
                </HStack>

                <Text fontSize="sm">
                    {orderText || 'Заказ к ордеру не привязан'}
                    <Text as="span" color="fg.muted"> · ордер {row.number}</Text>
                </Text>

                <HStack gap="2" wrap="wrap">
                    {row.waiting_since && <Text fontSize="sm" color="fg.muted">ждёт {waitingText(row.waiting_since)}</Text>}
                    {row.is_mixed && <Badge colorPalette="orange">часть заказов — доставка</Badge>}
                    {!row.has_orders && <Badge colorPalette="gray">без заказа</Badge>}
                    {extra}
                </HStack>

                {canIssue && onIssue && !open && (
                    <Button size="lg" colorPalette="green" onClick={() => setOpen(true)} disabled={busy}>
                        <LuPackageCheck /> Выдать без пропуска
                    </Button>
                )}

                {open && (
                    <VStack align="stretch" gap="2" p="3" bg="bg.muted" borderRadius="md">
                        <Text fontSize="sm">
                            Без пропуска сначала убедитесь, что курьер от этого клиента
                            {row.client_phone ? ` (телефон клиента ${row.client_phone})` : ''} — любым способом.
                            Затем попросите курьера <b>расписаться в расходном листе</b>.
                        </Text>
                        <Input size="lg" placeholder="Имя курьера" value={recipient} onChange={(e) => setRecipient(e.target.value)} />
                        <Textarea rows={2} placeholder="Комментарий (необязательно)" value={comment} onChange={(e) => setComment(e.target.value)} />
                        <HStack>
                            <Button flex="1" size="lg" colorPalette="green" loading={busy}
                                onClick={() => onIssue(row, { recipient_name: recipient, comment, via: 'manual' }).then((ok) => ok && setOpen(false))}>
                                Выдан
                            </Button>
                            <Button size="lg" variant="outline" onClick={() => setOpen(false)}>Отмена</Button>
                        </HStack>
                    </VStack>
                )}

                {footer}
            </Card.Body>
        </Card.Root>
    );
}
