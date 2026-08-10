import { Box, HStack, Text } from '@chakra-ui/react';
import { LuCopy, LuMail, LuPhone, LuSquarePlus } from 'react-icons/lu';
import { MenuContent, MenuItem, MenuRoot, MenuSeparator, MenuTrigger } from '@/components/ui/menu';
import { Tooltip } from '@/components/ui/tooltip';
import { toastError, toastSuccess } from '@/utils/toast';

/**
 * Копирование в буфер.
 *
 * navigator.clipboard живёт только в защищённом контексте, а CRM открывают
 * и по http внутри сети — поэтому фолбэк на execCommand, а не «не работает».
 */
async function copy(text) {
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const area = document.createElement('textarea');
            area.value = text;
            area.style.position = 'fixed';
            area.style.opacity = '0';
            document.body.appendChild(area);
            area.select();
            document.execCommand('copy');
            document.body.removeChild(area);
        }
        toastSuccess('Скопировано');
    } catch {
        toastError('Не удалось скопировать');
    }
}

/**
 * Email партнёра: клик открывает написание письма.
 *
 * @param {string|null} email
 * @param {Function} onCompose — открыть диалог письма
 * @param {boolean} canWrite — право crm-emails.create
 */
export function EmailCell({ email, onCompose, canWrite }) {
    if (!email) return <Text fontSize="sm" color="fg.muted">—</Text>;

    if (!canWrite) {
        return <Text fontSize="sm">{email}</Text>;
    }

    return (
        <Tooltip content="Написать письмо" openDelay={400}>
            <HStack
                as="button"
                type="button"
                gap={1}
                onClick={onCompose}
                borderRadius="md"
                px={1}
                py={0.5}
                _hover={{ bg: 'bg.muted', color: 'blue.fg' }}
                aria-label={`Написать письмо на ${email}`}
            >
                <LuMail size={13} />
                <Text fontSize="sm" lineClamp={1}>{email}</Text>
            </HStack>
        </Tooltip>
    );
}

/**
 * Телефон партнёра: клик открывает действия по звонку.
 *
 * «Записать звонок» открывает диалог, где номер набирается tel:-ссылкой, а результат
 * разговора сразу попадает в ленту. Пункт первый не случайно: звонок, который никто
 * не зафиксировал, для CRM не отличается от несостоявшегося.
 *
 * @param {string|null} phone
 * @param {string|null} digits — номер без форматирования, для tel:
 * @param {Function} onCall — открыть диалог звонка
 * @param {Function} onCreateTask
 * @param {boolean} canCall — право crm-calls.create
 */
export function PhoneCell({ phone, digits, onCall, onCreateTask, canCall = false }) {
    if (!phone) return <Text fontSize="sm" color="fg.muted">—</Text>;

    return (
        <MenuRoot>
            <MenuTrigger asChild>
                <HStack
                    gap={1}
                    cursor="pointer"
                    borderRadius="md"
                    px={1}
                    py={0.5}
                    _hover={{ bg: 'bg.muted' }}
                    title="Действия по звонку"
                >
                    <LuPhone size={13} />
                    <Text fontSize="sm" whiteSpace="nowrap">{phone}</Text>
                </HStack>
            </MenuTrigger>
            <MenuContent>
                {canCall && (
                    <MenuItem value="log" onClick={onCall}>
                        <HStack gap={2}><LuPhone size={14} /> <span>Позвонить и записать</span></HStack>
                    </MenuItem>
                )}
                <MenuItem value="call" asChild>
                    <Box as="a" href={`tel:+${digits || ''}`}>
                        <HStack gap={2}><LuPhone size={14} /> <span>Просто набрать</span></HStack>
                    </Box>
                </MenuItem>
                <MenuItem value="copy" onClick={() => copy(phone)}>
                    <HStack gap={2}><LuCopy size={14} /> <span>Скопировать номер</span></HStack>
                </MenuItem>
                <MenuSeparator />
                <MenuItem value="task" onClick={onCreateTask}>
                    <HStack gap={2}><LuSquarePlus size={14} /> <span>Поставить задачу</span></HStack>
                </MenuItem>
            </MenuContent>
        </MenuRoot>
    );
}
