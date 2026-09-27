<?php

namespace App\Services\Client\Api\Operations;

use App\Models\User;
use App\Models\UserQuestion;
use App\Services\Client\Api\Envelope;
use App\Services\Client\Api\Operation;
use App\Services\Client\Api\OperationProvider;
use App\Services\Support\UserQuestionService;
use App\Support\Client\ClientApiSource;
use App\Support\OperationApi\OperationInput;
use App\Support\OperationApi\Param;

/**
 * Вопрос менеджеру — канал для всего, что API не решает: объединить отправки,
 * подождать предзаказ, самовывоз к часу. Ответ менеджера читается здесь же.
 */
class QuestionOperations implements OperationProvider
{
    public function __construct(private readonly UserQuestionService $questions) {}

    public static function section(): array
    {
        return ['questions', 'Вопросы менеджеру'];
    }

    public static function operations(): array
    {
        return [
            new Operation(
                id: 'questions.list', section: 'questions', method: 'GET', uri: 'questions',
                summary: 'Мои вопросы и статус ответа',
                description: 'Статусы: new, in_progress, answered, rejected. Ответ — в questions.get.',
                params: [
                    Param::string('cursor', 'Курсор страницы из meta.next_cursor'),
                    Param::integer('per_page', 'Строк на странице, до 100', rules: ['min:1', 'max:100']),
                ],
                handler: [self::class, 'list'],
            ),
            new Operation(
                id: 'questions.create', section: 'questions', method: 'POST', uri: 'questions',
                summary: 'Задать вопрос менеджеру',
                description: 'Ответ обычно в течение 1 рабочего дня; он появится в questions.get. Укажите заказ или '
                    .'документ в тексте, если вопрос о них. Принимает Idempotency-Key.',
                params: [
                    Param::string('subject', 'Тема', true, ['min:3', 'max:200']),
                    Param::string('body', 'Текст вопроса', true, ['min:10', 'max:5000']),
                ],
                handler: [self::class, 'create'],
                mutating: true, idempotent: true,
            ),
            new Operation(
                id: 'questions.get', section: 'questions', method: 'GET', uri: 'questions/{question}',
                summary: 'Вопрос с ответом менеджера',
                description: 'answer заполнен, когда статус answered.',
                params: [Param::integer('question', 'id вопроса', true)],
                handler: [self::class, 'get'],
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function list(User $actor, OperationInput $input): array
    {
        $paginator = UserQuestion::forUser($actor)->orderByDesc('created_at')->orderByDesc('id')
            ->cursorPaginate(Envelope::perPage($input->get('per_page'), 50), ['*'], 'cursor', $input->string('cursor'));

        return Envelope::cursor($paginator, fn (UserQuestion $q) => $this->questions->payload($q, withBody: false));
    }

    /** @return array<string, mixed> */
    public function create(User $actor, OperationInput $input): array
    {
        $question = $this->questions->create($actor, [
            'subject' => (string) $input->string('subject'),
            'body' => (string) $input->string('body'),
        ], [
            'source' => 'api:'.(ClientApiSource::tokenName() ?? ''),
            'user_agent' => 'client-api',
        ]);

        return Envelope::data($this->questions->payload($question), ['created' => true]);
    }

    /** @return array<string, mixed> */
    public function get(User $actor, OperationInput $input): array
    {
        $question = UserQuestion::forUser($actor)->whereKey((int) $input->int('question'))->firstOrFail();

        return Envelope::data($this->questions->payload($question));
    }
}
