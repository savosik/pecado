import { useCallback, useEffect, useRef, useState } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Card, HStack, Input, Text, Textarea, VStack } from '@chakra-ui/react';
import { LuCamera, LuScanBarcode, LuScanQrCode, LuSearch, LuX } from 'react-icons/lu';
import WmsLayout from '@/Wms/Layouts/WmsLayout';
import { PageHeader } from '@/Admin/Components/PageHeader';
import { Button } from '@/components/ui/button';
import { SegmentedControl } from '@/components/ui/segmented-control';
import { toaster } from '@/components/ui/toaster';
import { usePermission } from '@/shared/Panel/usePermission';
import BarcodeCameraView from '@/components/common/BarcodeCameraView';
import PwaInstallBanner from '@/components/PwaInstallBanner';
import DeskBar from './DeskBar';
import IssueCard from './IssueCard';
import PassView from './PassView';
import { beep, errorMessage, placesText, timeText } from './pickupUtils';

const REFRESH_MS = 30000;
const SCAN_MODE_KEY = 'wms.pickups.scanMode';

/**
 * Чем читать код по умолчанию: телефон (сенсорный экран без мыши) — камерой, компьютер — USB/Bluetooth-сканером.
 * Выбор кладовщика запоминается на устройстве и переживает перезагрузку страницы.
 */
function initialScanMode() {
    try {
        const saved = window.localStorage.getItem(SCAN_MODE_KEY);
        if (saved === 'camera' || saved === 'scanner') return saved;
    } catch { /* приватный режим — просто не запоминаем */ }
    const touchOnly = typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
    return touchOnly ? 'camera' : 'scanner';
}

/**
 * Выдача заказов самовывоза (эпик pick-00). Один экран под телефон: список → скан → пропуск.
 * Сканер — камера, USB/Bluetooth (печатает в поле и жмёт Enter) или ручной ввод.
 */
export default function PickupsIndex() {
    const initial = usePage().props;
    const { can } = usePermission();
    const canIssue = can('wms-pickups.issue');
    const canCancel = can('wms-pickups.cancel');

    const [data, setData] = useState(initial);
    const [tab, setTab] = useState('awaiting');
    const [view, setView] = useState('list'); // list | scan | pass
    const [pass, setPass] = useState(null);
    const [via, setVia] = useState('qr');
    const [verified, setVerified] = useState([]);
    const [busyId, setBusyId] = useState(null);
    const [resolving, setResolving] = useState(false);
    const [query, setQuery] = useState('');
    const [found, setFound] = useState(null);
    const [reasons, setReasons] = useState({});
    const [scanMode, setScanMode] = useState(initialScanMode); // camera | scanner
    const changeScanMode = (mode) => {
        setScanMode(mode);
        try { window.localStorage.setItem(SCAN_MODE_KEY, mode); } catch { /* см. initialScanMode */ }
    };

    const stateRef = useRef({ view, pass });
    stateRef.current = { view, pass };
    const scanInputRef = useRef(null);

    const reload = useCallback(async () => {
        try {
            const { data: fresh } = await window.axios.get('/wms/pickups/data');
            setData(fresh);
        } catch {
            /* тихо: следующая попытка через полминуты */
        }
    }, []);

    useEffect(() => {
        const timer = setInterval(() => { if (stateRef.current.view === 'list') reload(); }, REFRESH_MS);
        return () => clearInterval(timer);
    }, [reload]);

    // Экран работает и на стационарном компьютере с USB/Bluetooth-сканером: он печатает в активное поле
    // и жмёт Enter. Поэтому поле скана есть на каждом экране и держит фокус — возвращаем его после каждого
    // скана и действия, если кладовщик не печатает в другом поле (поиск, имя курьера, причина).
    const [scanValue, setScanValue] = useState('');
    // В режиме камеры фокус не держим: на телефоне фокус в поле поднимает клавиатуру поверх видоискателя.
    const focusScanner = useCallback(() => {
        if (scanMode !== 'scanner') return;
        const active = document.activeElement;
        const tag = active?.tagName;
        const busyElsewhere = active && active !== scanInputRef.current && (tag === 'INPUT' || tag === 'TEXTAREA' || active.isContentEditable);
        if (!busyElsewhere) scanInputRef.current?.focus();
    }, [scanMode]);
    useEffect(() => { focusScanner(); }, [view, pass, focusScanner]);
    useEffect(() => { if (!resolving && !busyId) focusScanner(); }, [resolving, busyId, focusScanner]);

    const submitScan = () => { const v = scanValue.trim(); setScanValue(''); if (v) handleScan(v); };

    const scannerField = (placeholder) => (
        <HStack>
            <Input ref={scanInputRef} size="lg" value={scanValue} placeholder={placeholder} autoComplete="off"
                onChange={(e) => setScanValue(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); submitScan(); } }} />
            <Button size="lg" variant="outline" loading={resolving} onClick={submitScan}>Найти</Button>
        </HStack>
    );

    const modeSwitch = (
        <SegmentedControl size="sm" value={scanMode} onValueChange={(e) => changeScanMode(e.value)}
            items={[
                { value: 'scanner', label: <HStack gap="1"><LuScanBarcode /> <span>Сканер</span></HStack> },
                { value: 'camera', label: <HStack gap="1"><LuCamera /> <span>Камера</span></HStack> },
            ]} />
    );

    const fail = (message) => { beep('error'); toaster.create({ description: message, type: 'error' }); };
    const done = (message) => { beep('ok'); toaster.create({ description: message, type: 'success' }); };

    const handleScan = useCallback(async (raw) => {
        const value = String(raw || '').trim();
        if (!value || resolving) return;

        setResolving(true);
        try {
            const { data: result } = await window.axios.post('/wms/pickups/resolve', { value });
            const current = stateRef.current;

            if (result.kind === 'pass') {
                beep('ok');
                setPass(result.pass); setVia(result.via); setVerified([]); setView('pass');
                return;
            }

            // Штрихкод расходного листа: внутри пропуска — проверка коробки, иначе — поиск ордера.
            const ids = result.rows.map((row) => row.id);
            if (current.pass) {
                const match = current.pass.items.find((item) => ids.includes(item.goods_issue_id) && item.can_issue);
                if (match) {
                    setVerified((list) => (list.includes(match.goods_issue_id) ? list : [...list, match.goods_issue_id]));
                    done('Коробка проверена');
                    setView('pass');
                } else {
                    fail('Этот комплект не для этого курьера');
                }
                return;
            }

            beep('ok');
            setFound(result.rows); setView('list');
        } catch (error) {
            fail(errorMessage(error, 'Не распознано'));
        } finally {
            setResolving(false);
        }
    }, [resolving]);

    // Видоискатель уже квадрата во всю ширину: на телефоне он занимал почти весь экран и список под ним не было видно.
    const cameraBox = (
        <Box borderRadius="lg" overflow="hidden" bg="black" w="100%" maxW="420px" mx="auto">
            <BarcodeCameraView onScan={handleScan} paused={resolving} aspectRatio={3 / 2} />
        </Box>
    );

    const issue = async (row, details) => {
        const id = row.goods_issue_id ?? row.id;
        setBusyId(id);
        try {
            const { data: result } = await window.axios.post(`/wms/pickups/${id}/issue`, { ...details, pass_id: pass?.id ?? null });
            done(result.message);
            if (result.pass) setPass(result.pass);
            setFound((rows) => rows?.filter((r) => r.id !== id) ?? null);
            reload();
            return true;
        } catch (error) {
            fail(errorMessage(error));
            return false;
        } finally {
            setBusyId(null);
        }
    };

    const issueAll = async (details) => {
        setBusyId('all');
        try {
            const { data: result } = await window.axios.post(`/wms/pickups/passes/${pass.id}/issue-all`, details);
            done(result.message);
            (result.errors || []).forEach((message) => toaster.create({ description: message, type: 'warning' }));
            setPass(null); setView('list'); reload();
        } catch (error) {
            fail(errorMessage(error));
            if (error?.response?.data?.pass) setPass(error.response.data.pass);
        } finally {
            setBusyId(null);
        }
    };

    const post = async (url, payload, key) => {
        setBusyId(key);
        try {
            const { data: result } = await window.axios.post(url, payload);
            toaster.create({ description: result.message, type: 'success' });
            setReasons((r) => ({ ...r, [key]: '' }));
            reload();
        } catch (error) {
            fail(errorMessage(error));
        } finally {
            setBusyId(null);
        }
    };

    const search = async () => {
        if (query.trim().length < 3) { toaster.create({ description: 'Введите не меньше трёх символов', type: 'info' }); return; }
        try {
            const { data: result } = await window.axios.get('/wms/pickups/search', { params: { q: query } });
            setFound(result.rows);
            if (result.rows.length === 0) toaster.create({ description: 'Собранных заказов по запросу нет', type: 'info' });
        } catch (error) {
            fail(errorMessage(error));
        }
    };

    const tabs = [
        ['awaiting', 'Ждут', data.awaiting.length],
        ['picking', 'Собираются', data.picking.length],
        ['issued', 'Выдано', data.issued.filter((h) => !h.is_cancelled).length],
        ['stale', 'Зависшие', data.stale.length],
        ...(data.review.length > 0 ? [['review', 'Разбор', data.review.length]] : []),
    ];

    const reasonBox = (key, placeholder, label, onSubmit, palette = 'red') => (
        <VStack align="stretch" gap="2">
            <Textarea rows={2} placeholder={placeholder} value={reasons[key] || ''} onChange={(e) => setReasons((r) => ({ ...r, [key]: e.target.value }))} />
            <Button variant="outline" colorPalette={palette} loading={busyId === key} disabled={(reasons[key] || '').trim().length < 3} onClick={() => onSubmit(reasons[key])}>
                {label}
            </Button>
        </VStack>
    );

    const handoverCard = (h, mode) => (
        <Card.Root key={h.handover_id} size="sm" variant="outline" opacity={h.is_cancelled ? 0.6 : 1}>
            <Card.Body gap="2">
                <HStack justify="space-between" align="flex-start">
                    <Box minW="0">
                        <Text fontWeight="700" lineClamp="2">{h.goods_issue?.client || 'Клиент не определён'}</Text>
                        <Text fontSize="sm">{(h.goods_issue?.orders || []).map((o) => o.number).join(', ')} · ордер {h.goods_issue?.number}</Text>
                    </Box>
                    <Badge colorPalette={h.is_cancelled ? 'red' : 'green'}>{h.is_cancelled ? 'отменена' : timeText(h.issued_at)}</Badge>
                </HStack>
                <Text fontSize="sm" color="fg.muted">
                    {h.issued_by || '—'} · {h.method_label}{h.pass_code ? ` ${h.pass_code}` : ''}
                    {h.recipient_name ? ` · курьер: ${h.recipient_name}` : ''}{h.box_verified ? ' · коробка проверена' : ''}
                </Text>
                {h.comment && <Text fontSize="sm">{h.comment}</Text>}
                {h.is_cancelled && <Text fontSize="sm" color="fg.error">Отменил {h.cancelled_by}: {h.cancel_reason}</Text>}
                {h.needs_review && <Text fontSize="sm" color="fg.warning">{h.review_note}</Text>}
                {mode === 'issued' && canCancel && h.can_cancel && reasonBox(
                    `cancel-${h.handover_id}`, 'Причина отмены выдачи', 'Отменить выдачу',
                    (reason) => post(`/wms/pickups/handovers/${h.handover_id}/cancel`, { reason }, `cancel-${h.handover_id}`),
                )}
                {mode === 'review' && canCancel && reasonBox(
                    `review-${h.handover_id}`, 'Что выяснили', 'Разобрано',
                    (note) => post(`/wms/pickups/handovers/${h.handover_id}/review`, { note }, `review-${h.handover_id}`), 'green',
                )}
            </Card.Body>
        </Card.Root>
    );

    const empty = (text) => <Card.Root size="sm"><Card.Body><Text color="fg.muted">{text}</Text></Card.Body></Card.Root>;

    return (
        <WmsLayout breadcrumbs={[{ label: 'Выдача заказов' }]}>
            <Head title="Выдача заказов" />

            {view === 'pass' && pass && (
                <PassView pass={pass} via={via} verified={verified} canIssue={canIssue} busyId={busyId}
                    onIssue={issue} onIssueAll={issueAll} onScanBox={() => setView('scan')}
                    scanMode={scanMode} modeSwitch={modeSwitch}
                    scannerField={scannerField('Штрихкод расходного листа')}
                    onBack={() => { setPass(null); setView('list'); reload(); }} />
            )}

            {view === 'scan' && (
                <VStack align="stretch" gap="3">
                    <HStack justify="space-between">
                        <Text fontSize="lg" fontWeight="700">{pass ? 'Скан расходного листа' : 'Скан пропуска'}</Text>
                        <Button variant="ghost" onClick={() => setView(pass ? 'pass' : 'list')}><LuX /> Закрыть</Button>
                    </HStack>
                    {cameraBox}
                    <Text fontSize="sm" color="fg.muted" textAlign="center">
                        {pass ? 'Наведите камеру на штрихкод расходного листа' : 'Наведите камеру на QR-код на телефоне курьера'}
                    </Text>
                    {scannerField(pass ? 'Или введите номер документа' : 'Или введите шесть цифр пропуска')}
                </VStack>
            )}

            {view === 'list' && (
                <VStack align="stretch" gap="3">
                    <PageHeader title="Выдача заказов"
                        description={`Склад ${data.schedule.week_text}. Сегодня ${data.schedule.is_open ? `открыт до ${data.schedule.closes_at}` : 'закрыт'}${data.schedule.cutoff_at ? `, приём к сборке до ${data.schedule.cutoff_at}` : ''}.`} />

                    {/* pick-17: значок на главный экран — Android предложит сам, iPhone получит подсказку */}
                    <PwaInstallBanner />

                    {/* pick-18: выдают ли сейчас — то же видят курьер и клиент; «Отойти» перед обедом или почтой */}
                    <DeskBar desk={data.desk} canIssue={canIssue} onChange={(desk) => setData((d) => ({ ...d, desk }))} />

                    {/* Телефон читает пропуск камерой сразу, без лишнего нажатия; компьютер — USB-сканером в поле с фокусом.
                        Переключатель на виду: у стойки может оказаться и планшет со сканером, и ноутбук без него. */}
                    <Box p="3" bg="green.subtle" borderRadius="lg" borderWidth="1px" borderColor="green.muted">
                        <HStack justify="space-between" mb="2" gap="2" flexWrap="wrap">
                            <HStack gap="2"><LuScanQrCode /><Text fontWeight="700">Сканируйте пропуск</Text></HStack>
                            {modeSwitch}
                        </HStack>
                        {scanMode === 'camera' ? (
                            <VStack align="stretch" gap="2">
                                {cameraBox}
                                <Text fontSize="sm" color="fg.muted" textAlign="center">Наведите камеру на QR-код на телефоне курьера</Text>
                                {scannerField('Или введите шесть цифр пропуска')}
                            </VStack>
                        ) : scannerField('Наведите сканер на QR-код курьера или введите шесть цифр')}
                    </Box>

                    <HStack>
                        <Input size="lg" placeholder="Клиент, номер заказа или ордера" value={query}
                            onChange={(e) => setQuery(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') search(); }} />
                        <Button size="lg" variant="outline" aria-label="Найти" onClick={search}><LuSearch /></Button>
                    </HStack>

                    {found && (
                        <VStack align="stretch" gap="2">
                            <HStack justify="space-between">
                                <Text fontWeight="700">Найдено: {found.length}</Text>
                                <Button size="sm" variant="ghost" onClick={() => { setFound(null); setQuery(''); }}><LuX /> Сбросить</Button>
                            </HStack>
                            {found.map((row) => <IssueCard key={row.id} row={row} canIssue={canIssue && row.status === 'shipped'} onIssue={issue} busy={busyId === row.id}
                                extra={row.status !== 'shipped' ? <Badge colorPalette="orange">{row.status_label}</Badge> : null} />)}
                        </VStack>
                    )}

                    {!found && (
                        <>
                            <HStack gap="1" overflowX="auto" pb="1">
                                {tabs.map(([key, label, count]) => (
                                    <Button key={key} size="sm" flexShrink={0} variant={tab === key ? 'solid' : 'outline'}
                                        colorPalette={key === 'review' ? 'orange' : undefined} onClick={() => setTab(key)}>
                                        {label} {count}
                                    </Button>
                                ))}
                            </HStack>

                            {tab === 'awaiting' && (data.awaiting.length === 0 ? empty('Собранных заказов, ждущих курьера, нет.')
                                : data.awaiting.map((row) => <IssueCard key={row.id} row={row} canIssue={canIssue} onIssue={issue} busy={busyId === row.id} />))}

                            {tab === 'picking' && (data.picking.length === 0 ? empty('В сборке заказов самовывоза нет.')
                                : data.picking.map((row) => (
                                    <IssueCard key={row.id} row={{ ...row, waiting_since: null }} canIssue={false}
                                        extra={<>
                                            <Badge>{row.status_label}</Badge>
                                            <Badge colorPalette={row.is_overdue ? 'red' : 'gray'}>
                                                {row.is_overdue ? `опаздывает, обещали к ${row.promised_text}` : `обещали к ${row.promised_text}`}
                                            </Badge>
                                        </>} />
                                )))}

                            {tab === 'issued' && (data.issued.length === 0 ? empty('Сегодня ещё ничего не выдавали.') : data.issued.map((h) => handoverCard(h, 'issued')))}

                            {tab === 'stale' && (data.stale.length === 0 ? empty(`Заказов, ждущих дольше ${data.staleDays} дн., нет.`)
                                : data.stale.map((row) => (
                                    <IssueCard key={row.id} row={row} canIssue={canIssue} onIssue={issue} busy={busyId === row.id}
                                        footer={canCancel && (
                                            <Button variant="outline" size="sm" loading={busyId === `close-${row.id}`}
                                                onClick={() => post(`/wms/pickups/${row.id}/close`, {}, `close-${row.id}`)}>
                                                Уже забрали раньше — закрыть без выдачи
                                            </Button>
                                        )} />
                                )))}

                            {tab === 'review' && data.review.map((h) => handoverCard(h, 'review'))}
                        </>
                    )}

                    <Text fontSize="xs" color="fg.muted" textAlign="center">
                        Список обновляется сам. {placesText(data.awaiting.reduce((sum, row) => sum + row.packages_count, 0))} на стойке выдачи.
                    </Text>
                </VStack>
            )}
        </WmsLayout>
    );
}
