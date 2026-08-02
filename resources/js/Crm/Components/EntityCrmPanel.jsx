import { Card, Tabs, Text } from '@chakra-ui/react';
import { usePermission } from '@/shared/Panel/usePermission';
import CommentThread from '@/Crm/Components/CommentThread';
import AttachmentPanel from '@/Crm/Components/AttachmentPanel';

/**
 * Готовая врезка CRM для карточки сущности: комментарии и файлы.
 *
 * Существует, чтобы карточки заказа и реализации в админке не повторяли обёртку
 * и проверку прав — они обе одинаковые. Без CRM-прав блок не рисуется вовсе:
 * кладовщик или каталоговед в переписку продаж не заглядывает.
 */
export default function EntityCrmPanel({ entityType, entityId, title = 'CRM' }) {
    const { can } = usePermission();

    const canComments = can('crm-comments.view');
    const canAttachments = can('crm-attachments.view');

    if (!canComments && !canAttachments) {
        return null;
    }

    return (
        <Card.Root mt={4}>
            <Card.Header>
                <Text fontWeight="semibold">{title}</Text>
            </Card.Header>
            <Card.Body>
                <Tabs.Root defaultValue={canComments ? 'comments' : 'files'} lazyMount unmountOnExit>
                    <Tabs.List>
                        {canComments && <Tabs.Trigger value="comments">Комментарии</Tabs.Trigger>}
                        {canAttachments && <Tabs.Trigger value="files">Файлы</Tabs.Trigger>}
                    </Tabs.List>

                    {canComments && (
                        <Tabs.Content value="comments">
                            <CommentThread
                                entityType={entityType}
                                entityId={entityId}
                                canCreate={can('crm-comments.create')}
                            />
                        </Tabs.Content>
                    )}

                    {canAttachments && (
                        <Tabs.Content value="files">
                            <AttachmentPanel
                                entityType={entityType}
                                entityId={entityId}
                                canUpload={can('crm-attachments.create')}
                            />
                        </Tabs.Content>
                    )}
                </Tabs.Root>
            </Card.Body>
        </Card.Root>
    );
}
