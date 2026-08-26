import { HStack, IconButton } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';
import { LuEye, LuPencil, LuTrash2 } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';
import { usePermission } from '@/shared/Panel/usePermission';

/**
 * RowActions — единая колонка действий строки для всех панелей (/admin, /crm, /wms).
 *
 * Стандарт: «глаз» — просмотр, «карандаш» — редактирование, «корзина» — удаление.
 * Иконки трёх стандартных действий не переопределяются: пользователь должен
 * узнавать их в любом разделе, не гадая, по чему кликать. Доменные действия
 * (отправить, vCard, поставить задачу…) идут в `extra` между карандашом и корзиной —
 * корзина всегда крайняя правая, чтобы деструктивное действие не соседствовало
 * с частым.
 *
 * Действие описывается объектом:
 *   { href | onClick, permission?, allowed?, disabled?: boolean|string, label?, icon? }
 * — `href` рендерится Inertia-ссылкой (работает средняя кнопка мыши),
 * — `permission` проверяется через usePermission().can(),
 * — `allowed === false` — серверный флаг (row.can?.delete), кнопка не рисуется,
 * — `disabled` строкой — кнопка неактивна, причина в подсказке.
 * Действие без обработчика/без прав не рисуется вовсе: серая кнопка занимает
 * место и обещает то, чего не будет.
 */

const STANDARD = {
    view: { icon: LuEye, label: 'Просмотреть' },
    edit: { icon: LuPencil, label: 'Редактировать' },
    delete: { icon: LuTrash2, label: 'Удалить', colorPalette: 'red' },
};

export function RowActionButton({
    icon: Icon,
    label,
    href,
    onClick,
    disabled = false,
    colorPalette,
    size = 'sm',
    stopPropagation = true,
    ...rest
}) {
    const reason = typeof disabled === 'string' ? disabled : null;
    const isDisabled = Boolean(disabled);

    const handleClick = (event) => {
        if (stopPropagation) {
            event.stopPropagation();
        }
        if (isDisabled) {
            event.preventDefault();
            return;
        }
        onClick?.(event);
    };

    const button = href && !isDisabled
        ? (
            <IconButton asChild size={size} variant="ghost" colorPalette={colorPalette} aria-label={label} {...rest}>
                <Link href={href} onClick={handleClick}><Icon /></Link>
            </IconButton>
        )
        : (
            <IconButton
                size={size}
                variant="ghost"
                colorPalette={colorPalette}
                aria-label={label}
                disabled={isDisabled}
                onClick={handleClick}
                {...rest}
            >
                <Icon />
            </IconButton>
        );

    return (
        <Tooltip content={reason || label} openDelay={400}>
            {button}
        </Tooltip>
    );
}

function normalize(action, kind, can) {
    if (!action) return null;
    const base = STANDARD[kind] || {};
    const { permission, allowed, ...props } = action;

    if (allowed === false) return null;
    if (permission && !can(permission)) return null;
    if (!props.href && !props.onClick) return null;

    return {
        ...base,
        ...props,
        // Стандартные иконки не переопределяются — в этом весь смысл единообразия.
        icon: kind === 'extra' ? props.icon : base.icon,
        colorPalette: kind === 'delete' ? 'red' : props.colorPalette,
    };
}

export default function RowActions({
    view = null,
    edit = null,
    delete: del = null,
    extra = null,
    size = 'sm',
    justify = 'end',
    stopPropagation = true,
}) {
    const { can } = usePermission();

    const extraItems = Array.isArray(extra)
        ? extra.map((item) => normalize(item, 'extra', can)).filter(Boolean)
        : [];

    const items = [
        normalize(view, 'view', can),
        normalize(edit, 'edit', can),
        ...extraItems,
    ].filter(Boolean);
    const deleteItem = normalize(del, 'delete', can);

    const extraNode = !Array.isArray(extra) ? extra : null;

    if (items.length === 0 && !deleteItem && !extraNode) {
        return null;
    }

    return (
        <HStack gap={1} justify={justify}>
            {items.map((item, index) => (
                <RowActionButton key={item.key ?? index} size={size} stopPropagation={stopPropagation} {...item} />
            ))}
            {extraNode}
            {deleteItem && (
                <RowActionButton size={size} stopPropagation={stopPropagation} {...deleteItem} />
            )}
        </HStack>
    );
}
