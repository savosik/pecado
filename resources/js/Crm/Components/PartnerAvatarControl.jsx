import { useRef, useState } from 'react';
import axios from 'axios';
import { router } from '@inertiajs/react';
import { Box, HStack } from '@chakra-ui/react';
import { MenuContent, MenuItem, MenuRoot, MenuSeparator, MenuTrigger } from '@/components/ui/menu';
import { toastError, toastSuccess } from '@/utils/toast';
import PartnerAvatar from './PartnerAvatar';

/**
 * Аватарка в карточке партнёра: показ и управление.
 *
 * Клик по картинке открывает меню — загрузить свою, перерисовать, снять.
 * Отдельных кнопок в шапке нет намеренно: аватарка нужна ради узнавания,
 * а не ради работы с ней, и три кнопки рядом с именем партнёра заняли бы
 * место, которого в шапке и так нет.
 *
 * @param {number} clientId
 * @param {string} name
 * @param {{url: string, source: string|null}|null} avatar
 * @param {boolean} canEdit — право crm-profile.edit
 * @param {boolean} generationEnabled — включена ли ИИ-генерация
 */
export default function PartnerAvatarControl({
    clientId,
    name,
    avatar,
    canEdit = false,
    generationEnabled = true,
}) {
    const fileInput = useRef(null);
    const [busy, setBusy] = useState(false);

    const hint = avatar?.source === 'ai'
        ? 'Нарисовал ИИ — можно загрузить свою'
        : (avatar ? 'Загружена вручную' : 'Аватарки пока нет');

    if (!canEdit) {
        return <PartnerAvatar avatar={avatar} name={name} size={56} hint={hint} />;
    }

    const upload = async (file) => {
        if (!file) return;

        const form = new FormData();
        form.append('avatar', file);

        setBusy(true);
        try {
            await axios.post(route('crm.clients.avatar.store', clientId), form);
            toastSuccess('Аватарка обновлена');
            // Перечитываем только пропсы карточки: адрес картинки содержит
            // версию файла, поэтому браузер заберёт новую, а не показанную.
            router.reload({ only: ['client'] });
        } catch (error) {
            toastError(error?.response?.data?.message || 'Не удалось загрузить аватарку');
        } finally {
            setBusy(false);
            if (fileInput.current) fileInput.current.value = '';
        }
    };

    const regenerate = async () => {
        setBusy(true);
        try {
            const { data } = await axios.post(route('crm.clients.avatar.regenerate', clientId));
            toastSuccess(data?.message || 'Рисуем новую аватарку');
        } catch (error) {
            toastError(error?.response?.data?.message || 'Не удалось поставить задание');
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        setBusy(true);
        try {
            await axios.delete(route('crm.clients.avatar.destroy', clientId));
            toastSuccess('Аватарка снята');
            router.reload({ only: ['client'] });
        } catch (error) {
            toastError(error?.response?.data?.message || 'Не удалось снять аватарку');
        } finally {
            setBusy(false);
        }
    };

    return (
        <HStack gap={0}>
            <input
                ref={fileInput}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                hidden
                onChange={(e) => upload(e.target.files?.[0])}
            />
            <MenuRoot>
                <MenuTrigger asChild disabled={busy}>
                    <Box cursor="pointer" opacity={busy ? 0.5 : 1} title="Аватарка партнёра">
                        <PartnerAvatar avatar={avatar} name={name} size={56} hint={hint} />
                    </Box>
                </MenuTrigger>
                <MenuContent>
                    <MenuItem value="upload" onClick={() => fileInput.current?.click()}>
                        Загрузить свою…
                    </MenuItem>
                    {generationEnabled && (
                        <MenuItem value="regenerate" onClick={regenerate}>
                            {avatar ? 'Перерисовать через ИИ' : 'Нарисовать через ИИ'}
                        </MenuItem>
                    )}
                    {avatar && (
                        <>
                            <MenuSeparator />
                            <MenuItem value="remove" color="fg.error" onClick={remove}>
                                Снять аватарку
                            </MenuItem>
                        </>
                    )}
                </MenuContent>
            </MenuRoot>
        </HStack>
    );
}
