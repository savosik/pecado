import { useState, useMemo, useRef, useCallback } from 'react';
import {
    Box, Flex, Stack, HStack, Text, Textarea, Input, Button,
    Tabs, Dialog, Portal, Badge, Link, CloseButton,
} from '@chakra-ui/react';
import {
    LuFileUp, LuDownload, LuListChecks, LuFileSpreadsheet,
    LuTriangleAlert, LuCheck,
} from 'react-icons/lu';
import axios from 'axios';
import { toastSuccess, toastError, toastInfo } from '@/utils/toast';

/** Непустые строки текста (после trim). */
function nonEmptyLines(text) {
    return String(text || '')
        .split(/\r\n|\r|\n/)
        .map((l) => l.trim())
        .filter((l) => l !== '');
}

/**
 * Диалог «Импорт заказа» в корзину.
 *
 * Вкладка «Списком» — два textarea (идентификаторы + количества в столбик).
 * Вкладка «Из файла» — скачивание шаблона + загрузка заполненного XLSX/CSV.
 *
 * @param {{ open: boolean, onClose: () => void, onSuccess?: () => void }} props
 */
export default function ImportOrderDialog({ open, onClose, onSuccess }) {
    const [tab, setTab] = useState('list');
    const [identifiers, setIdentifiers] = useState('');
    const [quantities, setQuantities] = useState('');
    const [file, setFile] = useState(null);
    const [submitting, setSubmitting] = useState(false);
    const [result, setResult] = useState(null); // { added_count, unresolved }
    const fileInputRef = useRef(null);

    const idLines = useMemo(() => nonEmptyLines(identifiers).length, [identifiers]);
    const qtyLines = useMemo(() => nonEmptyLines(quantities).length, [quantities]);
    const listValid = idLines > 0 && idLines === qtyLines;

    const handleClose = useCallback(() => {
        if (submitting) return;
        onClose?.();
    }, [submitting, onClose]);

    const applyResult = useCallback((data, { clearList = false } = {}) => {
        const added = Number(data?.added_count ?? 0);
        const unresolved = Array.isArray(data?.unresolved) ? data.unresolved : [];
        setResult({ added_count: added, unresolved });

        if (added > 0) {
            toastSuccess('Импорт заказа', data.message || `Импортировано позиций: ${added}.`);
            onSuccess?.();
            if (clearList && unresolved.length === 0) {
                setIdentifiers('');
                setQuantities('');
            }
        }
        if (unresolved.length > 0) {
            toastInfo('Часть позиций не импортирована', `Не распознано позиций: ${unresolved.length}.`);
        }
    }, [onSuccess]);

    const handleError = useCallback((err) => {
        const resp = err?.response?.data;
        // Наш структурированный ответ (422 «ничего не добавлено») — показываем как результат.
        if (resp && (resp.unresolved !== undefined || resp.added_count !== undefined)) {
            applyResult(resp);
            return;
        }
        toastError('Ошибка импорта', resp?.message || 'Не удалось импортировать позиции.');
    }, [applyResult]);

    const handleImportList = useCallback(async () => {
        if (!listValid || submitting) return;
        const ids = nonEmptyLines(identifiers);
        const qtys = nonEmptyLines(quantities);
        const items = ids.map((identifier, i) => ({ identifier, quantity: qtys[i] }));

        setSubmitting(true);
        setResult(null);
        try {
            const { data } = await axios.post('/api/cart/import-order', { items });
            applyResult(data, { clearList: true });
        } catch (err) {
            handleError(err);
        } finally {
            setSubmitting(false);
        }
    }, [listValid, submitting, identifiers, quantities, applyResult, handleError]);

    const handleImportFile = useCallback(async () => {
        if (!file || submitting) return;
        const fd = new FormData();
        fd.append('file', file);

        setSubmitting(true);
        setResult(null);
        try {
            const { data } = await axios.post('/api/cart/import-order-file', fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            applyResult(data);
            setFile(null);
            if (fileInputRef.current) fileInputRef.current.value = '';
        } catch (err) {
            handleError(err);
        } finally {
            setSubmitting(false);
        }
    }, [file, submitting, applyResult, handleError]);

    return (
        <Dialog.Root
            open={open}
            onOpenChange={({ open: isOpen }) => !isOpen && handleClose()}
            size="lg"
        >
            <Portal>
                <Dialog.Backdrop />
                <Dialog.Positioner>
                    <Dialog.Content maxW="640px">
                        <Dialog.Header>
                            <Dialog.Title>
                                <HStack gap="2">
                                    <LuFileUp size={18} />
                                    <Text>Импорт заказа</Text>
                                </HStack>
                            </Dialog.Title>
                            <Dialog.CloseTrigger asChild>
                                <CloseButton size="sm" onClick={handleClose} />
                            </Dialog.CloseTrigger>
                        </Dialog.Header>

                        <Dialog.Body>
                            <Tabs.Root
                                value={tab}
                                onValueChange={({ value }) => { setTab(value); setResult(null); }}
                                colorPalette="pecado"
                            >
                                <Tabs.List>
                                    <Tabs.Trigger value="list">
                                        <LuListChecks size={16} /> Списком
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="file">
                                        <LuFileSpreadsheet size={16} /> Из файла
                                    </Tabs.Trigger>
                                </Tabs.List>

                                {/* Вкладка: Списком */}
                                <Tabs.Content value="list">
                                    <Stack gap="4" mt="2">
                                        <Text fontSize="sm" color="fg.muted">
                                            Вставьте идентификаторы товаров (код, артикул или штрихкод) в первое
                                            поле и соответствующие количества — во второе, по одному значению в
                                            строке. Число строк должно совпадать.
                                        </Text>

                                        <Flex gap="3" direction={{ base: 'column', sm: 'row' }}>
                                            <Box flex="2">
                                                <Text fontSize="xs" fontWeight="medium" mb="1">
                                                    Идентификаторы
                                                </Text>
                                                <Textarea
                                                    value={identifiers}
                                                    onChange={(e) => { setIdentifiers(e.target.value); setResult(null); }}
                                                    placeholder={'ART-000123\n4600000000000\n...'}
                                                    rows={10}
                                                    fontFamily="mono"
                                                    fontSize="sm"
                                                    resize="vertical"
                                                />
                                                <Text fontSize="xs" color="fg.muted" mt="1">
                                                    Строк: {idLines}
                                                </Text>
                                            </Box>
                                            <Box flex="1">
                                                <Text fontSize="xs" fontWeight="medium" mb="1">
                                                    Количество
                                                </Text>
                                                <Textarea
                                                    value={quantities}
                                                    onChange={(e) => { setQuantities(e.target.value); setResult(null); }}
                                                    placeholder={'2\n1\n...'}
                                                    rows={10}
                                                    fontFamily="mono"
                                                    fontSize="sm"
                                                    resize="vertical"
                                                />
                                                <Text
                                                    fontSize="xs"
                                                    mt="1"
                                                    color={idLines > 0 && idLines !== qtyLines ? 'fg.error' : 'fg.muted'}
                                                >
                                                    Строк: {qtyLines}
                                                </Text>
                                            </Box>
                                        </Flex>

                                        {idLines > 0 && idLines !== qtyLines && (
                                            <HStack gap="2" color="fg.error" fontSize="sm">
                                                <LuTriangleAlert size={16} />
                                                <Text>
                                                    Число строк не совпадает: идентификаторов — {idLines},
                                                    количеств — {qtyLines}.
                                                </Text>
                                            </HStack>
                                        )}

                                        <ResultBlock result={result} />

                                        <Flex justify="flex-end">
                                            <Button
                                                colorPalette="pecado"
                                                size="sm"
                                                onClick={handleImportList}
                                                disabled={!listValid || submitting}
                                                loading={submitting}
                                            >
                                                <LuFileUp size={14} /> Импортировать
                                            </Button>
                                        </Flex>
                                    </Stack>
                                </Tabs.Content>

                                {/* Вкладка: Из файла */}
                                <Tabs.Content value="file">
                                    <Stack gap="4" mt="2">
                                        <Text fontSize="sm" color="fg.muted">
                                            Скачайте шаблон, заполните его (первая колонка — идентификатор, вторая —
                                            количество) и загрузите обратно. Поддерживаются форматы XLSX и CSV.
                                        </Text>

                                        <Box>
                                            <Link
                                                href="/api/cart/import-order/template"
                                                color="pecado.fg"
                                                fontSize="sm"
                                                fontWeight="medium"
                                            >
                                                <LuDownload size={14} /> Скачать шаблон (XLSX)
                                            </Link>
                                        </Box>

                                        <Box>
                                            <Text fontSize="xs" fontWeight="medium" mb="1">Файл</Text>
                                            <Input
                                                ref={fileInputRef}
                                                type="file"
                                                accept=".xlsx,.csv,.txt"
                                                p="1.5"
                                                onChange={(e) => { setFile(e.target.files?.[0] || null); setResult(null); }}
                                            />
                                            {file && (
                                                <Text fontSize="xs" color="fg.muted" mt="1">
                                                    Выбран файл: {file.name}
                                                </Text>
                                            )}
                                        </Box>

                                        <ResultBlock result={result} />

                                        <Flex justify="flex-end">
                                            <Button
                                                colorPalette="pecado"
                                                size="sm"
                                                onClick={handleImportFile}
                                                disabled={!file || submitting}
                                                loading={submitting}
                                            >
                                                <LuFileUp size={14} /> Загрузить и импортировать
                                            </Button>
                                        </Flex>
                                    </Stack>
                                </Tabs.Content>
                            </Tabs.Root>
                        </Dialog.Body>

                        <Dialog.Footer>
                            <Button variant="outline" size="sm" onClick={handleClose} disabled={submitting}>
                                Закрыть
                            </Button>
                        </Dialog.Footer>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}

/** Блок с результатом импорта: сколько добавлено + список нераспознанных. */
function ResultBlock({ result }) {
    if (!result) return null;

    const { added_count: added, unresolved } = result;

    return (
        <Stack
            gap="2"
            p="3"
            borderWidth="1px"
            borderColor="border.muted"
            rounded="md"
            bg="bg.subtle"
        >
            <HStack gap="2" fontSize="sm" color={added > 0 ? 'fg.success' : 'fg.muted'}>
                {added > 0 ? <LuCheck size={16} /> : <LuTriangleAlert size={16} />}
                <Text fontWeight="medium">
                    {added > 0
                        ? `Добавлено позиций: ${added}.`
                        : 'Не добавлено ни одной позиции.'}
                </Text>
            </HStack>

            {unresolved.length > 0 && (
                <Box>
                    <HStack gap="2" fontSize="sm" color="fg.error" mb="1">
                        <LuTriangleAlert size={16} />
                        <Text fontWeight="medium">
                            Не распознано позиций: {unresolved.length}
                        </Text>
                    </HStack>
                    <Box maxH="160px" overflowY="auto">
                        <Stack gap="1">
                            {unresolved.map((u, i) => (
                                <HStack key={`${u.identifier}-${i}`} gap="2" fontSize="xs" justify="space-between">
                                    <Text fontFamily="mono" truncate>{u.identifier || '—'}</Text>
                                    <Badge colorPalette="red" variant="subtle" size="sm" flexShrink={0}>
                                        {u.reason}
                                    </Badge>
                                </HStack>
                            ))}
                        </Stack>
                    </Box>
                </Box>
            )}
        </Stack>
    );
}
