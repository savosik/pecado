import { useEffect, useState } from 'react';
import { IconButton } from '@chakra-ui/react';
import { LuSparkles } from 'react-icons/lu';
import { isEffectsEnabled, setEffectsEnabled, subscribeEffects } from '@/utils/effectsEnabled';
import { Tooltip } from '@/components/ui/tooltip';

/**
 * Тогл UI-эффектов (полёт в корзину + конфетти).
 * По умолчанию выключен; визуально светится бордовым, когда включён.
 */
export default function EffectsToggleButton({ inactiveColor, inactiveColorDark, ...props }) {
    const [enabled, setEnabled] = useState(false);

    useEffect(() => {
        setEnabled(isEffectsEnabled());
        return subscribeEffects(setEnabled);
    }, []);

    const toggle = () => setEffectsEnabled(!enabled);

    return (
        <Tooltip content={enabled ? 'Эффекты включены' : 'Эффекты выключены'} positioning={{ placement: 'top' }}>
            <IconButton
                onClick={toggle}
                variant="ghost"
                size="sm"
                aria-label={enabled ? 'Выключить эффекты' : 'Включить эффекты'}
                color={enabled ? 'pecado.500' : (inactiveColor ?? 'gray.500')}
                _dark={{ color: enabled ? 'pecado.300' : (inactiveColorDark ?? inactiveColor ?? 'gray.400') }}
                css={{ _icon: { width: '5', height: '5' } }}
                {...props}
            >
                <LuSparkles />
            </IconButton>
        </Tooltip>
    );
}
