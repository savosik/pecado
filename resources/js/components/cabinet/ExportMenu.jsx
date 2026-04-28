import { Box, Text } from '@chakra-ui/react';
import { LuDownload } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import { MenuRoot, MenuTrigger, MenuContent, MenuItem } from '@/components/ui/menu';

/**
 * ExportMenu — кнопка-дропдаун «Экспорт» с пунктами CSV / XLSX.
 *
 * Показывается только при включённом флаге `search-cabinet.export`.
 * Скачивание идёт через прямую навигацию по URL — браузер сам обрабатывает
 * Content-Disposition: attachment.
 *
 * @param {{ basePath: string, filters: object }} props
 *   - basePath — путь экспорт-эндпойнта (например, '/cabinet/orders/export').
 *   - filters — текущие фильтры/поиск, добавляются в query string.
 */
export default function ExportMenu({ basePath, filters }) {
    const buildUrl = (format) => {
        const params = new URLSearchParams();
        params.set('format', format);
        for (const [key, value] of Object.entries(filters || {})) {
            if (value === null || value === undefined) continue;
            if (Array.isArray(value)) {
                value.forEach((v) => {
                    if (v !== null && v !== undefined && v !== '') {
                        params.append(`${key}[]`, String(v));
                    }
                });
                continue;
            }
            if (value === '' || value === false) continue;
            params.set(key, String(value));
        }
        return `${basePath}?${params.toString()}`;
    };

    const download = (format) => {
        window.location.href = buildUrl(format);
    };

    return (
        <MenuRoot positioning={{ placement: 'bottom-end' }}>
            <MenuTrigger asChild>
                <Button size="sm" variant="outline">
                    <LuDownload size={14} />
                    <Text ml="2">Экспорт</Text>
                </Button>
            </MenuTrigger>
            <MenuContent>
                <MenuItem value="csv" onClick={() => download('csv')} cursor="pointer">
                    <Box>CSV</Box>
                </MenuItem>
                <MenuItem value="xlsx" onClick={() => download('xlsx')} cursor="pointer">
                    <Box>Excel (XLSX)</Box>
                </MenuItem>
            </MenuContent>
        </MenuRoot>
    );
}
