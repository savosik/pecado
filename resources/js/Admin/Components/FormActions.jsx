import React from 'react';
import { Box, Button, HStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * FormActions - кнопки действий формы (сохранить/отменить)
 * 
 * @param {Function} onCancel - Callback для отмены (опционально, можно использовать cancelHref)
 * @param {string} cancelHref - Ссылка для кнопки отмены (Inertia Link)
 * @param {boolean} isLoading - Индикация загрузки
 * @param {string} submitLabel - Текст кнопки отправки
 * @param {string} cancelLabel - Текст кнопки отмены
 * @param {boolean} sticky - Прикрепить к низу экрана
 */
export const FormActions = ({
    onCancel,
    cancelHref,
    isLoading = false,
    submitLabel = 'Сохранить',
    cancelLabel = 'Отмена',
    sticky = false,
}) => {
    const content = (
        <HStack gap={3} justifyContent="flex-start">
            <Button
                type="submit"
                colorPalette="blue"
                loading={isLoading}
                loadingText="Сохранение..."
            >
                {submitLabel}
            </Button>

            {(onCancel || cancelHref) && (
                cancelHref ? (
                    <Button
                        as={Link}
                        href={cancelHref}
                        variant="ghost"
                        disabled={isLoading}
                    >
                        {cancelLabel}
                    </Button>
                ) : (
                    <Button
                        onClick={onCancel}
                        variant="ghost"
                        disabled={isLoading}
                    >
                        {cancelLabel}
                    </Button>
                )
            )}
        </HStack>
    );

    if (sticky) {
        return (
            <Box
                position="sticky"
                bottom={0}
                bg="bg.panel"
                borderTopWidth="1px"
                borderColor="border.muted"
                p={4}
                mt={6}
                mx={-6}
                mb={-6}
            >
                {content}
            </Box>
        );
    }

    return (
        <Box mt={6}>
            {content}
        </Box>
    );
};
