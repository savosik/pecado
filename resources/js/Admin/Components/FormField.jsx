import React from 'react';
import { Field } from '../../components/ui/field';

/**
 * FormField - обёртка для полей формы с автоматическим отображением ошибок
 * 
 * @param {string} label - Метка поля
 * @param {string} name - Имя поля (используется для получения ошибки из Inertia)
 * @param {string} error - Текст ошибки (из Inertia useForm)
 * @param {string} helpText - Вспомогательный текст
 * @param {boolean} required - Обязательное ли поле
 * @param {boolean} optional - Показать "опционально"
 * @param {ReactNode} children - Содержимое поля (Input, Textarea, и т.д.)
 */
export const FormField = ({
    label,
    name,
    error,
    helpText,
    required = false,
    optional = false,
    children,
    ...rest
}) => {
    return (
        <Field
            label={label}
            errorText={error}
            helperText={helpText}
            required={required}
            optionalText={optional ? 'опционально' : undefined}
            invalid={!!error}
            {...rest}
        >
            {children}
        </Field>
    );
};
