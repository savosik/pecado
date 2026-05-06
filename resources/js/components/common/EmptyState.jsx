import { EmptyState as ChakraEmptyState, VStack, Button, Icon, HStack } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * Пустое состояние — отображается когда данных нет (0 результатов).
 *
 * @param {{
 *   icon: React.ElementType,
 *   title: string,
 *   description?: string,
 *   action?: { label: string, href?: string, onClick?: () => void, icon?: React.ElementType },
 *   secondaryAction?: { label: string, href?: string, onClick?: () => void, icon?: React.ElementType },
 * }} props
 */
export default function EmptyState({ icon, title, description, action, secondaryAction }) {
    const renderButton = (a, variant) => {
        const ButtonIcon = a.icon;
        const content = (
            <>
                {ButtonIcon && <ButtonIcon size={16} />}
                {a.label}
            </>
        );
        const colorPalette = variant === 'solid' ? 'pecado' : undefined;
        const buttonVariant = variant === 'solid' ? undefined : 'outline';

        if (a.href) {
            return (
                <Button asChild colorPalette={colorPalette} variant={buttonVariant} size="md">
                    <Link href={a.href}>{content}</Link>
                </Button>
            );
        }
        return (
            <Button
                colorPalette={colorPalette}
                variant={buttonVariant}
                size="md"
                onClick={a.onClick}
            >
                {content}
            </Button>
        );
    };

    return (
        <ChakraEmptyState.Root py="16">
            <ChakraEmptyState.Content>
                {icon && (
                    <ChakraEmptyState.Indicator>
                        <Icon as={icon} boxSize="10" />
                    </ChakraEmptyState.Indicator>
                )}
                <VStack gap="1" textAlign="center">
                    <ChakraEmptyState.Title fontSize="lg" fontWeight="semibold">
                        {title}
                    </ChakraEmptyState.Title>
                    {description && (
                        <ChakraEmptyState.Description color="fg.muted">
                            {description}
                        </ChakraEmptyState.Description>
                    )}
                </VStack>
                {(action || secondaryAction) && (
                    <HStack gap="2" mt="4" wrap="wrap" justify="center">
                        {action && renderButton(action, 'solid')}
                        {secondaryAction && renderButton(secondaryAction, 'outline')}
                    </HStack>
                )}
            </ChakraEmptyState.Content>
        </ChakraEmptyState.Root>
    );
}
