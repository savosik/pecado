import { createContext, useContext, useState, useCallback, useEffect } from 'react';
import { router } from '@inertiajs/react';

const AuthDialogContext = createContext(null);

export function AuthDialogProvider({ children }) {
    const [activeDialog, setActiveDialog] = useState(null);

    const openLogin = useCallback(() => setActiveDialog('login'), []);
    const openRegister = useCallback(() => setActiveDialog('register'), []);
    const openForgotPassword = useCallback(() => setActiveDialog('forgot'), []);
    const close = useCallback(() => setActiveDialog(null), []);

    // Закрываем диалог при Inertia-навигации (после успешного логина/регистрации)
    useEffect(() => {
        const removeListener = router.on('navigate', () => {
            setActiveDialog(null);
        });
        return removeListener;
    }, []);

    return (
        <AuthDialogContext.Provider value={{ activeDialog, openLogin, openRegister, openForgotPassword, close }}>
            {children}
        </AuthDialogContext.Provider>
    );
}

export function useAuthDialog() {
    const ctx = useContext(AuthDialogContext);
    if (!ctx) throw new Error('useAuthDialog must be used within AuthDialogProvider');
    return ctx;
}
