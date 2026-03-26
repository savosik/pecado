import { EmptyState as ChakraEmptyState, VStack, Button, Icon } from '@chakra-ui/react';
import { Link } from '@inertiajs/react';

/**
 * Пустое состояние — отображается когда данных нет (0 результатов).
 *
 * @param {{
 *   icon: React.ElementType,
 *   title: string,
 *   description?: string,
 *   action?: { label: string, href?: string, onClick?: () => void }
 * }} props
 */
export default function EmptyState({ icon, title, description, action }) {
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
                {action && (
                    action.href ? (
                        <Button asChild colorPalette="pecado" size="md" mt="4">
                            <Link href={action.href}>{action.label}</Link>
                        </Button>
                    ) : (
                        <Button
                            colorPalette="pecado"
                            size="md"
                            mt="4"
                            onClick={action.onClick}
                        >
                            {action.label}
                        </Button>
                    )
                )}
            </ChakraEmptyState.Content>
        </ChakraEmptyState.Root>
    );
}
