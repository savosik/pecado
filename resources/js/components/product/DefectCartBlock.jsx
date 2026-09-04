import { memo, useCallback } from 'react';
import { Box } from '@chakra-ui/react';
import { LuBadgePercent } from 'react-icons/lu';
import { Button } from '@/components/ui/button';
import DefectQuantityControl from './DefectQuantityControl';
import { openDefectQuickView } from '@/utils/quickView';

/**
 * Блок покупки уценки в карточке каталога (раздел «Уценка»).
 *
 * Партия одна — сразу счётчик [−] N [+] на неё: клиент кладёт в корзину именно
 * уценённый экземпляр, а не обычный товар. Партий несколько — выбирать должен
 * клиент, поэтому вместо счётчика кнопка «Выбрать», открывающая QuickView на
 * вкладке «Уценка».
 *
 * @param {{ product: Object, size?: string, fullWidth?: boolean }} props
 */
function DefectCartBlock({ product, size = 'sm', fullWidth = true }) {
    const lot = product?.defect_lot ?? null;
    const lotsCount = Number(product?.defect_lots_count ?? 0);
    const slug = product?.slug;

    const handleChoose = useCallback((e) => {
        e.preventDefault();
        e.stopPropagation();
        openDefectQuickView(slug);
    }, [slug]);

    const stopNavigation = useCallback((e) => {
        e.preventDefault();
        e.stopPropagation();
    }, []);

    if (lot) {
        return (
            <Box onClick={stopNavigation} w={fullWidth ? '100%' : undefined}>
                <DefectQuantityControl defect={lot} size={size} fullWidth={fullWidth} />
            </Box>
        );
    }

    if (lotsCount > 1) {
        return (
            <Button
                type="button"
                onClick={handleChoose}
                variant="outline"
                colorPalette="purple"
                size={size}
                w={fullWidth ? '100%' : undefined}
            >
                <LuBadgePercent size={14} />
                Выбрать
            </Button>
        );
    }

    return null;
}

export default memo(DefectCartBlock);
