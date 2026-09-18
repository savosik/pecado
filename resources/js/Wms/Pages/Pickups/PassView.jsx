import { useState } from 'react';
import { Badge, Box, Card, HStack, Input, Text, VStack } from '@chakra-ui/react';
import { LuArrowLeft, LuCircleCheck, LuPackageCheck, LuScanBarcode } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { placesText } from './pickupUtils';

const STATE_COLORS = { ready: 'green', picking: 'orange', handed: 'gray', cancelled: 'red', unavailable: 'gray' };

/**
 * Экран пропуска (pick-11): кому отдаём и что именно. Отдаём только то, что в пропуске, —
 * остальное заберёт другой курьер по своему пропуску.
 */
export default function PassView({ pass, via, verified, canIssue, busyId, onIssue, onIssueAll, onScanBox, onBack }) {
    const [recipient, setRecipient] = useState(pass.courier_name || '');
    const toIssue = pass.items.filter((item) => item.can_issue);

    return (
        <VStack align="stretch" gap="3">
            <HStack justify="space-between">
                <Button variant="ghost" onClick={onBack}><LuArrowLeft /> К списку</Button>
                <Badge size="lg" colorPalette={pass.is_usable ? 'green' : 'red'}>{pass.status_label}</Badge>
            </HStack>

            <Card.Root size="sm" bg={pass.is_usable ? 'green.subtle' : 'red.subtle'}>
                <Card.Body gap="1">
                    <Text fontSize="sm" color="fg.muted">Пропуск {pass.code}</Text>
                    <Text fontSize="xl" fontWeight="700">{pass.client}</Text>
                    {pass.is_usable
                        ? <Text>Отдать: {toIssue.length} компл.{pass.packages_to_issue > 0 ? ` · ${placesText(pass.packages_to_issue)}` : ''}. Подпись не нужна — пропуск и есть подтверждение.</Text>
                        : <Text fontWeight="600">Не выдавать. Попросите магазин прислать курьеру новый пропуск.</Text>}
                    {pass.note && <Text fontSize="sm">Комментарий клиента: {pass.note}</Text>}
                </Card.Body>
            </Card.Root>

            {pass.is_usable && pass.items.map((item) => (
                <Card.Root key={item.goods_issue_id} size="sm" variant="outline" opacity={item.can_issue ? 1 : 0.65}>
                    <Card.Body gap="2">
                        <HStack justify="space-between" align="flex-start">
                            <Box minW="0">
                                <Text fontWeight="700">{item.orders.map((o) => o.number).join(', ') || `Ордер ${item.number}`}</Text>
                                <Text fontSize="sm" color="fg.muted">ордер {item.number} · {placesText(item.packages_count)}</Text>
                            </Box>
                            {verified.includes(item.goods_issue_id)
                                ? <Badge colorPalette="green"><LuCircleCheck /> коробка проверена</Badge>
                                : <Badge colorPalette={STATE_COLORS[item.state] || 'gray'}>{item.state_label}</Badge>}
                        </HStack>
                        {item.can_issue && canIssue && (
                            <Button size="lg" colorPalette="green" variant="outline" loading={busyId === item.goods_issue_id}
                                onClick={() => onIssue(item, { recipient_name: recipient, via, box_verified: verified.includes(item.goods_issue_id) })}>
                                <LuPackageCheck /> Выдать
                            </Button>
                        )}
                    </Card.Body>
                </Card.Root>
            ))}

            {pass.is_usable && toIssue.length > 0 && canIssue && (
                <VStack align="stretch" gap="2" position="sticky" bottom="0" bg="bg" py="2">
                    <Input size="lg" placeholder="Имя курьера (необязательно)" value={recipient} onChange={(e) => setRecipient(e.target.value)} />
                    <HStack>
                        <Button size="lg" variant="outline" onClick={onScanBox}><LuScanBarcode /> Лист</Button>
                        <Button flex="1" size="lg" colorPalette="green" loading={busyId === 'all'}
                            onClick={() => onIssueAll({ recipient_name: recipient, via, verified })}>
                            Выдать всё{pass.packages_to_issue > 0 ? ` · ${placesText(pass.packages_to_issue)}` : ` · ${toIssue.length} компл.`}
                        </Button>
                    </HStack>
                </VStack>
            )}

            {pass.is_usable && toIssue.length === 0 && (
                <Card.Root size="sm"><Card.Body><Text>По этому пропуску сейчас выдавать нечего.</Text></Card.Body></Card.Root>
            )}
        </VStack>
    );
}
