import { Badge, Box, HStack, Text, Wrap, WrapItem } from '@chakra-ui/react';
import { LuBuilding, LuMail, LuPhone, LuUser } from 'react-icons/lu';
import { Tooltip } from '@/components/ui/tooltip';
import LifecycleCell from './LifecycleCell';
import LastVisitHint from '@/Crm/Components/LastVisitHint';
import DebtLevelBadge from '@/Crm/Components/DebtLevelBadge';

/**
 * Один факт о партнёре: подпись мелко, значение рядом.
 */
function Fact({ icon: Icon, label, children }) {
    if (!children) return null;

    return (
        <Tooltip content={label} openDelay={400}>
            <HStack gap={1} color="fg.muted">
                {Icon && <Icon size={13} />}
                <Text fontSize="sm" color="fg">{children}</Text>
            </HStack>
        </Tooltip>
    );
}

/**
 * Компактная шапка карточки партнёра.
 *
 * Раньше здесь были две карточки на пол-экрана — «Основная информация» и «Статусы».
 * Между ними и лентой оставалось столько прокрутки, что до работы менеджер
 * доходил не сразу. Теперь всё, что нужно в разговоре, помещается в одну строку
 * чипов, а подробности живут в аккордеоне ниже.
 *
 * @param {object} client — payload из ClientController::show
 * @param {object|null} lifecycle
 * @param {Array} lifecycleOptions
 * @param {boolean} canEditLifecycle
 */
export default function ClientSummaryBar({
    client,
    lifecycle = null,
    lifecycleOptions = [],
    canEditLifecycle = false,
    debt = null,
}) {
    return (
        <Box borderWidth="1px" borderColor="border" borderRadius="lg" bg="bg.panel" px={3} py={2}>
            <Wrap gap={4} align="center">
                {debt?.partner && debt.partner.level !== 'clean' && (
                    <WrapItem>
                        <DebtLevelBadge
                            debt={{ ...debt.partner, label: debt.partner.level_label, color: debt.partner.level_color }}
                            pause={debt.active_pause}
                        />
                    </WrapItem>
                )}
                {lifecycle && (
                    <WrapItem>
                        <LifecycleCell
                            clientId={client.id}
                            lifecycle={{
                                status: lifecycle.status,
                                label: lifecycle.status_label,
                                color: lifecycle.status_color,
                                hint: lifecycle.hint,
                            }}
                            options={lifecycleOptions}
                            canEdit={canEditLifecycle}
                            // В карточке стадия приезжает в props `lifecycle`
                            // (бейдж, подсказка, журнал смен), а не в списке
                            // партнёров, ради которого написан дефолт ячейки.
                            reloadOnly={['lifecycle']}
                        />
                    </WrapItem>
                )}

                {client.client_status && (
                    <WrapItem>
                        <Tooltip content="Статус лояльности — источник 1С, в CRM не редактируется" openDelay={400}>
                            <Badge colorPalette="gray" variant="subtle">{client.client_status.name}</Badge>
                        </Tooltip>
                    </WrapItem>
                )}

                <WrapItem><Fact icon={LuUser} label="Персональный менеджер">{client.manager?.name}</Fact></WrapItem>
                <WrapItem><Fact icon={LuBuilding} label="Город">{client.city}</Fact></WrapItem>
                <WrapItem>
                    <Fact icon={LuMail} label="Email">
                        {client.email && <a href={`mailto:${client.email}`}>{client.email}</a>}
                    </Fact>
                </WrapItem>
                <WrapItem>
                    <Fact icon={LuPhone} label="Телефон">
                        {client.phone && (
                            <a href={`tel:+${(client.phone || '').replace(/\D+/g, '')}`}>{client.phone}</a>
                        )}
                    </Fact>
                </WrapItem>
                {/* Визит — в шапке, а не только в аккордеоне реквизитов: тот
                    закрыт по умолчанию, а «партнёр не открывал сайт» нужно
                    видеть до того, как менеджер снимет трубку. */}
                <WrapItem><LastVisitHint visit={client.last_visit} /></WrapItem>
            </Wrap>
        </Box>
    );
}
