import { useState, useCallback } from 'react';
import {
    Box, Flex, Input, Button, IconButton, Text,
    Menu, Dialog, Portal,
} from '@chakra-ui/react';
import {
    LuSearch, LuX, LuChevronDown, LuRefreshCw, LuTrash2,
    LuHash, LuFileSpreadsheet, LuListChecks,
} from 'react-icons/lu';
import ConfirmDialog from '@/components/common/ConfirmDialog';

/**
 * Панель инструментов корзины: поиск, bulk-действия, очистка, обновление.
 *
 * @param {{
 *   searchQuery: string,
 *   onSearchChange: (value: string) => void,
 *   bulkSelectedCount: number,
 *   onBulkSetQty: (qty: number) => void,
 *   onBulkDelete: () => void,
 *   onBulkExport: () => void,
 *   onClearCart: () => void,
 *   onRefresh: () => void,
 * }} props
 */
export default function CartToolbar({
    searchQuery,
    onSearchChange,
    bulkSelectedCount = 0,
    onBulkSetQty,
    onBulkDelete,
    onBulkExport,
    onClearCart,
    onRefresh,
}) {
    const [qtyDialogOpen, setQtyDialogOpen] = useState(false);
    const [qtyValue, setQtyValue] = useState('');
    const [clearDialogOpen, setClearDialogOpen] = useState(false);

    const bulkEnabled = bulkSelectedCount > 0;

    const handleConfirmQty = useCallback(() => {
        const n = parseInt(qtyValue, 10);
        if (isNaN(n) || n < 0) return;
        onBulkSetQty?.(Math.min(999, n));
        setQtyValue('');
        setQtyDialogOpen(false);
    }, [qtyValue, onBulkSetQty]);

    const handleConfirmClear = useCallback(() => {
        setClearDialogOpen(false);
        onClearCart?.();
    }, [onClearCart]);

    return (
        <Box>
            <Flex
                p="3"
                bg={{ base: 'bg', lg: 'transparent' }}
                borderWidth={{ base: '1px', lg: '0' }}
                borderBottomWidth={{ lg: '1px' }}
                borderColor="border"
                rounded={{ base: 'lg', lg: '0' }}
                mb={{ base: '3', lg: '0' }}
                direction={{ base: 'column', md: 'row' }}
                align={{ base: 'stretch', md: 'center' }}
                justify="space-between"
                gap="3"
            >
                {/* Поиск */}
                <Box position="relative" w={{ base: '100%', md: '320px' }} flexShrink={0}>
                    <Box position="absolute" left="3" top="50%" transform="translateY(-50%)" color="fg.muted" zIndex="1">
                        <LuSearch size={16} />
                    </Box>
                    <Input
                        value={searchQuery}
                        onChange={(e) => onSearchChange(e.target.value)}
                        placeholder="Поиск по названию, бренду, артикулу или штрихкоду"
                        pl="9"
                        pr={searchQuery ? '9' : '3'}
                        size="sm"
                    />
                    {searchQuery && (
                        <IconButton
                            aria-label="Очистить поиск"
                            variant="ghost"
                            size="xs"
                            position="absolute"
                            right="1"
                            top="50%"
                            transform="translateY(-50%)"
                            onClick={() => onSearchChange('')}
                        >
                            <LuX size={14} />
                        </IconButton>
                    )}
                </Box>

                {/* Кнопки */}
                <Flex gap="2" wrap="wrap">
                    {/* Действия (bulk) */}
                    <Menu.Root>
                        <Menu.Trigger asChild>
                            <Button variant="outline" size="sm">
                                <LuListChecks size={14} />
                                Действия{bulkEnabled ? ` (${bulkSelectedCount})` : ''}
                                <LuChevronDown size={14} />
                            </Button>
                        </Menu.Trigger>
                        <Menu.Positioner>
                            <Menu.Content>
                                <Menu.Item
                                    value="set-qty"
                                    disabled={!bulkEnabled}
                                    onClick={() => setQtyDialogOpen(true)}
                                >
                                    <LuHash size={14} />
                                    Задать количество…
                                </Menu.Item>
                                <Menu.Item
                                    value="delete"
                                    disabled={!bulkEnabled}
                                    onClick={() => onBulkDelete?.()}
                                >
                                    <LuTrash2 size={14} />
                                    Удалить выбранные
                                </Menu.Item>
                                <Menu.Item
                                    value="export"
                                    disabled={!bulkEnabled}
                                    onClick={() => onBulkExport?.()}
                                >
                                    <LuFileSpreadsheet size={14} />
                                    Экспорт в Excel
                                </Menu.Item>
                                <Menu.Separator />
                                <Menu.Item
                                    value="clear"
                                    onClick={() => setClearDialogOpen(true)}
                                    color="fg.error"
                                >
                                    <LuTrash2 size={14} />
                                    Очистить корзину…
                                </Menu.Item>
                            </Menu.Content>
                        </Menu.Positioner>
                    </Menu.Root>

                    {/* Обновить */}
                    <Button variant="outline" size="sm" onClick={onRefresh}>
                        <LuRefreshCw size={14} />
                        <Text display={{ base: 'none', sm: 'inline' }}>Обновить</Text>
                    </Button>
                </Flex>
            </Flex>

            {/* FIX #5: Accessible Chakra Dialog — «Задать количество» */}
            <Dialog.Root open={qtyDialogOpen} onOpenChange={({ open }) => !open && setQtyDialogOpen(false)}>
                <Portal>
                    <Dialog.Backdrop />
                    <Dialog.Positioner>
                        <Dialog.Content maxW="400px">
                            <Dialog.Header>
                                <Dialog.Title>Задать количество</Dialog.Title>
                            </Dialog.Header>
                            <Dialog.Body>
                                <Text fontSize="sm" color="fg.muted" mb="4">
                                    Укажите количество для выбранных товаров (0–999).
                                </Text>
                                <Input
                                    type="number"
                                    min={0}
                                    max={999}
                                    value={qtyValue}
                                    onChange={(e) => setQtyValue(e.target.value)}
                                    placeholder="0"
                                />
                            </Dialog.Body>
                            <Dialog.Footer>
                                <Dialog.ActionTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => { setQtyDialogOpen(false); setQtyValue(''); }}
                                    >
                                        Отмена
                                    </Button>
                                </Dialog.ActionTrigger>
                                <Button
                                    colorPalette="pecado"
                                    size="sm"
                                    onClick={handleConfirmQty}
                                    disabled={String(qtyValue).trim() === ''}
                                >
                                    Применить
                                </Button>
                            </Dialog.Footer>
                        </Dialog.Content>
                    </Dialog.Positioner>
                </Portal>
            </Dialog.Root>

            {/* FIX #5: Accessible Chakra ConfirmDialog — «Очистить корзину» */}
            <ConfirmDialog
                open={clearDialogOpen}
                onClose={() => setClearDialogOpen(false)}
                onConfirm={handleConfirmClear}
                title="Очистить корзину?"
                description="Все товары будут удалены из корзины. Это действие нельзя отменить."
                confirmLabel="Очистить"
                colorPalette="red"
            />
        </Box>
    );
}
