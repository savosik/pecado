import { Dialog, Portal, CloseButton, Box, Text, Heading } from '@chakra-ui/react';
import { useAuthDialog } from '@/contexts/AuthDialogContext';
import LoginForm from './LoginForm';
import RegisterForm from './RegisterForm';
import ForgotPasswordForm from './ForgotPasswordForm';

const config = {
    login: {
        title: 'Вход в аккаунт',
        component: LoginForm,
        maxW: '460px',
    },
    register: {
        title: 'Регистрация',
        component: RegisterForm,
        maxW: '540px',
    },
    forgot: {
        title: 'Восстановление пароля',
        component: ForgotPasswordForm,
        maxW: '440px',
    },
};

export default function AuthDialog() {
    const { activeDialog, close } = useAuthDialog();

    if (!activeDialog) return null;

    const { title, component: FormComponent, maxW } = config[activeDialog];

    return (
        <Dialog.Root
            open={true}
            onOpenChange={(e) => { if (!e.open) close(); }}
            placement="center"
            motionPreset="slide-in-bottom"
        >
            <Portal>
                <Dialog.Backdrop
                    bg="blackAlpha.600"
                    backdropFilter="blur(4px)"
                />
                <Dialog.Positioner>
                    <Dialog.Content
                        maxW={maxW}
                        w="95vw"
                        mx="auto"
                        borderRadius="2xl"
                        boxShadow="2xl"
                        maxH="90vh"
                        overflow="hidden"
                    >
                        {/* Header */}
                        <Box
                            px="6"
                            pt="5"
                            pb="3"
                            display="flex"
                            alignItems="center"
                            justifyContent="space-between"
                        >
                            <Box>
                                <Heading
                                    size="lg"
                                    color="fg"
                                    fontWeight="bold"
                                    letterSpacing="-0.01em"
                                >
                                    {title}
                                </Heading>
                            </Box>
                            <Dialog.CloseTrigger asChild>
                                <CloseButton size="sm" />
                            </Dialog.CloseTrigger>
                        </Box>

                        {/* Body */}
                        <Dialog.Body
                            px="6"
                            pb="6"
                            pt="2"
                            overflowY="auto"
                        >
                            <FormComponent />
                        </Dialog.Body>
                    </Dialog.Content>
                </Dialog.Positioner>
            </Portal>
        </Dialog.Root>
    );
}
