import { useState } from 'react';
import { LuListChecks, LuPhone } from 'react-icons/lu';
import RowActions from '@/shared/Panel/RowActions';
import TaskDialog from '@/Crm/Components/TaskDialog';
import CallDialog from '@/Crm/Components/CallDialog';

/**
 * Действия строки партнёра — стандартный набор: карточка, задача, звонок.
 *
 * Диалоги живут в родителе списка через {@link usePartnerDialogs}, чтобы
 * на странице был один экземпляр каждого, а не по паре на строку.
 */
export function usePartnerDialogs() {
    const [taskFor, setTaskFor] = useState(null);
    const [callFor, setCallFor] = useState(null);

    const dialogs = (
        <>
            <TaskDialog
                open={taskFor !== null}
                entity={taskFor ? { type: 'client', id: taskFor.id } : null}
                onClose={() => setTaskFor(null)}
                onSaved={() => setTaskFor(null)}
            />
            <CallDialog
                open={callFor !== null}
                client={callFor}
                onClose={() => setCallFor(null)}
                onSaved={() => setCallFor(null)}
            />
        </>
    );

    return { dialogs, setTaskFor, setCallFor };
}

export default function PartnerActions({ row, onTask, onCall }) {
    return (
        <RowActions
            size="xs"
            view={{ href: `/crm/partners/${row.id}`, label: 'Открыть карточку партнёра' }}
            extra={[
                { key: 'task', icon: LuListChecks, label: 'Поставить задачу', permission: 'crm-tasks.create', onClick: () => onTask(row) },
                { key: 'call', icon: LuPhone, label: 'Записать звонок', permission: 'crm-calls.create', onClick: () => onCall(row) },
            ]}
        />
    );
}
