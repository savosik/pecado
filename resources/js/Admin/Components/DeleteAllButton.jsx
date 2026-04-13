import React from 'react';
import { Button } from '@chakra-ui/react';
import { LuTrash2 } from 'react-icons/lu';
import { ConfirmDialog } from './ConfirmDialog';

/**
 * DeleteAllButton — кнопка «Удалить все записи» с диалогом подтверждения.
 *
 * @param {string} sectionLabel - Название раздела (напр. «статьи», «товары»)
 * @param {boolean} dialogOpen - Открыт ли диалог
 * @param {Function} onOpen - Открытие диалога
 * @param {Function} onClose - Закрытие диалога
 * @param {Function} onConfirm - Подтверждение удаления
 * @param {boolean} isLoading - Индикация загрузки
 */
export const DeleteAllButton = ({
    sectionLabel = 'записи',
    dialogOpen,
    onOpen,
    onClose,
    onConfirm,
    isLoading = false,
}) => {
    return (
        <>
            <Button
                size="sm"
                variant="outline"
                colorPalette="red"
                onClick={onOpen}
            >
                <LuTrash2 />
                Удалить все
            </Button>

            <ConfirmDialog
                open={dialogOpen}
                onClose={onClose}
                onConfirm={onConfirm}
                title={`Удалить все ${sectionLabel}?`}
                description={`Вы уверены, что хотите удалить ВСЕ ${sectionLabel}? Это действие необратимо и затронет все записи раздела, включая связанные данные.`}
                confirmLabel="Да, удалить все"
                cancelLabel="Отмена"
                isLoading={isLoading}
                colorPalette="red"
            />
        </>
    );
};
