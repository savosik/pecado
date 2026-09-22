import { useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Card, HStack, Image, Input, Text, VStack } from '@chakra-ui/react';
import { LuClipboardList, LuCopy, LuLink, LuQrCode, LuRefreshCw, LuShare2 } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/shared/Panel/usePermission';
import RowActions from '@/shared/Panel/RowActions';
import { ConfirmDialog } from '@/shared/Panel/ConfirmDialog';

/**
 * Ссылки для кладовщиков (pick-17): «как в Google Docs». Начальник склада выпускает ссылку,
 * пересылает в мессенджер — кладовщик открывает её на телефоне и работает без логина и пароля.
 * Перевыпуск отключает старую ссылку и все телефоны, вошедшие по ней.
 */
export default function AccessLinksIndex() {
    const { can } = usePermission();
    const canEdit = can('wms-access.edit');
    const [links, setLinks] = useState(usePage().props.links || []);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(null);
    const [qrFor, setQrFor] = useState(null);
    const [confirm, setConfirm] = useState(null); // { kind: 'regenerate' | 'revoke', link }
    const [journal, setJournal] = useState({}); // link.id → { rows, days, loading }

    const loadJournal = async (link, days = 30) => {
        if (journal[link.id] && !journal[link.id].loading && journal[link.id].days === days) { setJournal((j) => ({ ...j, [link.id]: null })); return; }
        setJournal((j) => ({ ...j, [link.id]: { rows: [], days, loading: true } }));
        try {
            const { data } = await window.axios.get(`/wms/access-links/${link.id}/handovers`, { params: { days } });
            setJournal((j) => ({ ...j, [link.id]: { rows: data.rows, days: data.days, loading: false } }));
        } catch {
            toaster.create({ description: 'Журнал не загрузился', type: 'error' });
            setJournal((j) => ({ ...j, [link.id]: null }));
        }
    };

    const timeText = (iso) => {
        const d = new Date(iso);
        return `${d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit' })} ${d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}`;
    };

    /** Полоски по часам 9–20 за 30 дней: видно, когда стойка работает, а когда простаивает. */
    const HoursBar = ({ hours }) => {
        const entries = Object.entries(hours || {});
        const max = Math.max(1, ...entries.map(([, v]) => v));
        if (entries.every(([, v]) => v === 0)) return null;
        return (
            <HStack gap="0.5" align="flex-end" h="28px" title="Выдачи по часам за 30 дней">
                {entries.map(([h, v]) => (
                    <VStack key={h} gap="0" w="14px" align="center">
                        <Box w="10px" h={`${Math.max(2, Math.round((v / max) * 20))}px`} bg={v > 0 ? 'green.solid' : 'bg.emphasized'} borderRadius="1px" title={`${h}:00 — ${v}`} />
                        <Text fontSize="8px" color="fg.muted">{h}</Text>
                    </VStack>
                ))}
            </HStack>
        );
    };

    const post = async (url, payload, key) => {
        setBusy(key);
        try {
            const { data } = await window.axios.post(url, payload);
            setLinks(data.links);
            toaster.create({ description: data.message, type: 'success' });
            return true;
        } catch (error) {
            const data = error?.response?.data;
            const first = data?.errors && Object.values(data.errors)[0];
            toaster.create({ description: data?.message || (Array.isArray(first) ? first[0] : 'Не получилось'), type: 'error' });
            return false;
        } finally {
            setBusy(null);
        }
    };

    const share = async (link) => {
        const text = `Ссылка для входа на склад Pecado («${link.name}»). Откройте на телефоне — логин и пароль не нужны.`;
        if (navigator.share) {
            try { await navigator.share({ title: 'Вход на склад', text, url: link.url }); return; } catch { /* отменили */ }
        }
        try {
            await navigator.clipboard.writeText(`${text} ${link.url}`);
            toaster.create({ description: 'Ссылка скопирована — вставьте её в сообщение кладовщику', type: 'success' });
        } catch {
            toaster.create({ description: link.url, type: 'info' });
        }
    };

    return (
        <WmsLayout breadcrumbs={[{ label: 'Ссылки для кладовщиков' }]}>
            <Head title="Ссылки для кладовщиков" />
            <PageHeader title="Ссылки для кладовщиков"
                description="Кладовщик открывает ссылку на телефоне и сразу работает: логин и пароль не нужны. Одна ссылка — одна учётная запись, в журнале выдачи видно, с какого телефона выдали." />

            <ConfirmDialog open={!!confirm} onClose={() => setConfirm(null)} isLoading={busy !== null}
                colorPalette={confirm?.kind === 'revoke' ? 'red' : 'orange'}
                title={confirm?.kind === 'revoke' ? 'Отключить ссылку?' : 'Перевыпустить ссылку?'}
                confirmLabel={confirm?.kind === 'revoke' ? 'Отключить' : 'Перевыпустить'} cancelLabel="Отмена"
                description={confirm?.kind === 'revoke'
                    ? `«${confirm?.link.name}»: ссылка перестанет входить, все телефоны с ней выйдут из кабинета.`
                    : `«${confirm?.link.name}»: старая ссылка перестанет работать, телефоны с ней выйдут. Новую нужно будет переслать заново.`}
                onConfirm={async () => {
                    const ok = await post(`/wms/access-links/${confirm.link.id}/${confirm.kind}`, {}, confirm.link.id);
                    if (ok) setConfirm(null);
                }} />

            {canEdit && (
                <Card.Root mb="4"><Card.Body>
                    <Text fontWeight="600" mb="2">Новая ссылка</Text>
                    <HStack>
                        <Input size="lg" placeholder="Название: стойка выдачи, смена Б, Иванов" value={name} maxLength={120}
                            onChange={(e) => setName(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') post('/wms/access-links', { name }, 'new').then((ok) => ok && setName('')); }} />
                        <Button size="lg" colorPalette="green" loading={busy === 'new'} disabled={name.trim().length < 2}
                            onClick={() => post('/wms/access-links', { name }, 'new').then((ok) => ok && setName(''))}>
                            <LuLink /> Выпустить
                        </Button>
                    </HStack>
                </Card.Body></Card.Root>
            )}

            <VStack align="stretch" gap="3">
                {links.length === 0 && <Card.Root><Card.Body><Text color="fg.muted">Ссылок пока нет. Выпустите первую и перешлите её кладовщику.</Text></Card.Body></Card.Root>}
                {links.map((link) => (
                    <Card.Root key={link.id} variant="outline" opacity={link.is_active ? 1 : 0.6}>
                        <Card.Body gap="2">
                            <HStack justify="space-between" align="flex-start" gap="3">
                                <Box minW="0">
                                    <HStack gap="2">
                                        <Text fontWeight="700" fontSize="lg">{link.name}</Text>
                                        <Badge colorPalette={link.is_active ? 'green' : 'gray'}>{link.is_active ? 'действует' : 'отключена'}</Badge>
                                    </HStack>
                                    <Text fontSize="sm" color="fg.muted">
                                        Учётная запись «{link.account}» · входов: {link.uses_count}
                                        {link.last_used_at ? ` · последний ${link.last_used_at}` : ''}
                                        {link.rotated_at ? ` · перевыпущена ${link.rotated_at}` : ` · выпущена ${link.created_at}${link.created_by ? ` (${link.created_by})` : ''}`}
                                    </Text>
                                </Box>
                                {canEdit && link.is_active && (
                                    <RowActions
                                        extra={[{ icon: LuRefreshCw, label: 'Перевыпустить', onClick: () => setConfirm({ kind: 'regenerate', link }) }]}
                                        delete={{ label: 'Отключить', onClick: () => setConfirm({ kind: 'revoke', link }) }} />
                                )}
                            </HStack>
                            {/* Ответственность и загрузка: выдачи с этого телефона */}
                            <HStack wrap="wrap" gap="4" align="center">
                                <HStack gap="3" fontSize="sm">
                                    <Text><b>{link.stats.today}</b> <Text as="span" color="fg.muted">сегодня</Text></Text>
                                    <Text><b>{link.stats.week}</b> <Text as="span" color="fg.muted">за 7 дн.</Text></Text>
                                    <Text><b>{link.stats.month}</b> <Text as="span" color="fg.muted">за 30 дн.</Text></Text>
                                    <Text><b>{link.stats.total}</b> <Text as="span" color="fg.muted">всего</Text></Text>
                                    {link.stats.cancelled > 0 && <Text color="fg.error">{link.stats.cancelled} отменено</Text>}
                                    {link.stats.last_at && <Text color="fg.muted">последняя {timeText(link.stats.last_at)}</Text>}
                                </HStack>
                                <HoursBar hours={link.stats.hours} />
                            </HStack>

                            {link.is_active && (
                                <HStack wrap="wrap" gap="2">
                                    <Button colorPalette="green" onClick={() => share(link)}><LuShare2 /> Переслать кладовщику</Button>
                                    <Button variant="outline" onClick={() => navigator.clipboard?.writeText(link.url).then(() => toaster.create({ description: 'Ссылка скопирована', type: 'success' }))}><LuCopy /> Скопировать</Button>
                                    <Button variant="outline" onClick={() => setQrFor(qrFor === link.id ? null : link.id)}><LuQrCode /> QR-код</Button>
                                    <Button variant="outline" onClick={() => loadJournal(link)} loading={journal[link.id]?.loading}><LuClipboardList /> Журнал выдач</Button>
                                </HStack>
                            )}
                            {!link.is_active && link.stats.total > 0 && (
                                <Button variant="outline" size="sm" alignSelf="flex-start" onClick={() => loadJournal(link)} loading={journal[link.id]?.loading}><LuClipboardList /> Журнал выдач</Button>
                            )}
                            {journal[link.id] && !journal[link.id].loading && (
                                <Box borderTopWidth="1px" pt="2">
                                    <HStack justify="space-between" mb="1">
                                        <Text fontSize="sm" fontWeight="600">Выдачи за {journal[link.id].days} дн.: {journal[link.id].rows.filter((r) => r.kind !== 'pause').length}</Text>
                                        <HStack gap="1">
                                            {[7, 30, 90].map((d) => <Button key={d} size="xs" variant={journal[link.id].days === d ? 'solid' : 'ghost'} onClick={() => loadJournal(link, d)}>{d} дн.</Button>)}
                                        </HStack>
                                    </HStack>
                                    {journal[link.id].rows.length === 0 && <Text fontSize="sm" color="fg.muted">Выдач с этого телефона не было.</Text>}
                                    <VStack align="stretch" gap="1">
                                        {journal[link.id].rows.map((h) => h.kind === 'pause' ? (
                                            <HStack key={h.id} fontSize="sm" gap="3" align="flex-start" color="fg.muted">
                                                <Text flexShrink={0} w="88px">{timeText(h.issued_at)}</Text>
                                                <Text lineClamp="1">
                                                    Отошёл: {h.reason}, до {h.until}{h.ended_at ? ` · вернулся в ${h.ended_at}` : h.is_active ? ' · ещё не вернулся' : ''}
                                                </Text>
                                            </HStack>
                                        ) : (
                                            <HStack key={h.id} justify="space-between" fontSize="sm" gap="3" opacity={h.is_cancelled ? 0.6 : 1} align="flex-start">
                                                <Text color="fg.muted" flexShrink={0} w="88px">{timeText(h.issued_at)}</Text>
                                                <Box flex="1" minW="0">
                                                    <Text lineClamp="1"><b>{h.client}</b>{h.company && h.company !== h.client ? ` · ${h.company}` : ''}</Text>
                                                    <Text color="fg.muted" lineClamp="1">
                                                        {h.orders.join(', ') || `ордер ${h.goods_issue}`}{h.packages_count > 0 ? ` · ${h.packages_count} мест` : ''}
                                                        {h.recipient_name ? ` · курьер ${h.recipient_name}` : ''} · {h.method_label}{h.pass_code ? ` ${h.pass_code}` : ''}
                                                        {h.is_cancelled ? ` · отменена (${h.cancelled_by}: ${h.cancel_reason})` : ''}{h.needs_review ? ' · разбор' : ''}
                                                    </Text>
                                                </Box>
                                            </HStack>
                                        ))}
                                    </VStack>
                                </Box>
                            )}
                            {qrFor === link.id && link.qr && (
                                <VStack gap="1" align="flex-start">
                                    <Image src={link.qr} alt="QR-код ссылки" boxSize="220px" bg="white" />
                                    <Text fontSize="sm" color="fg.muted">Кладовщик наводит камеру телефона — и входит. Не показывайте QR посторонним: он равен паролю.</Text>
                                </VStack>
                            )}
                        </Card.Body>
                    </Card.Root>
                ))}
            </VStack>
        </WmsLayout>
    );
}
