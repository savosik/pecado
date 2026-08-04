/**
 * Сборка записи ленты из ответа обычного эндпоинта.
 *
 * Задачи, письма и звонки создаются своими API — они возвращают payload сущности,
 * а не запись ленты. Собирать её на сервере вторым форматом ответа не стали:
 * тогда у каждого эндпоинта появился бы «режим для ленты», и форма записи начала бы
 * жить в четырёх местах вместо одного.
 *
 * Форма повторяет ClientTimelineService: type, id, happened_at_label, author,
 * title, excerpt, entity, can.
 */

export function entryFromTask(task, author) {
    return {
        type: 'task',
        id: task.id,
        happened_at_label: task.created_at_label,
        author: task.author || author || null,
        title: task.title,
        excerpt: task.description || null,
        entity: task.entity || null,
        attachments_count: task.attachments_count ?? 0,
        task,
        can: { update: !!task.can?.update, delete: !!task.can?.delete },
    };
}

export function entryFromEmail(email, author) {
    return {
        type: 'email',
        id: email.id,
        happened_at_label: email.created_at_label,
        author: email.author || author || null,
        title: email.subject,
        excerpt: `Кому: ${(email.to || []).join(', ')}`,
        entity: email.entity || null,
        attachments_count: email.attachments_count ?? 0,
        email,
        can: { update: !!email.can?.update, delete: !!email.can?.delete },
    };
}

export function entryFromCall(call, author) {
    return {
        type: 'call',
        id: call.id,
        happened_at_label: call.started_at_label || call.created_at_label,
        author: call.author || author || null,
        title: call.result_label,
        excerpt: call.summary || null,
        entity: call.entity || null,
        attachments_count: call.attachments_count ?? 0,
        call,
        can: { update: !!call.can?.update, delete: !!call.can?.delete },
    };
}
