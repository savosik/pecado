import { forwardRef, useState } from 'react';
import { Input, IconButton, Box } from '@chakra-ui/react';
import { LuEye, LuEyeOff } from 'react-icons/lu';

/**
 * Поле ввода пароля с кнопкой-глазиком для показа/скрытия символов.
 * Принимает все пропсы Chakra UI Input.
 */
const PasswordInput = forwardRef(function PasswordInput(props, ref) {
    const [show, setShow] = useState(false);

    return (
        <Box position="relative" width="100%">
            <Input
                ref={ref}
                {...props}
                type={show ? 'text' : 'password'}
                pr="10"
            />
            <IconButton
                aria-label={show ? 'Скрыть пароль' : 'Показать пароль'}
                onClick={() => setShow(!show)}
                variant="ghost"
                size="sm"
                position="absolute"
                right="1"
                top="50%"
                transform="translateY(-50%)"
                zIndex="1"
                color="gray.400"
                _hover={{ color: 'gray.600', bg: 'transparent' }}
                minW="8"
                h="8"
                tabIndex={-1}
            >
                {show ? <LuEyeOff size={18} /> : <LuEye size={18} />}
            </IconButton>
        </Box>
    );
});

export default PasswordInput;
