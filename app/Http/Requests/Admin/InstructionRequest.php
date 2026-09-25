<?php

namespace App\Http\Requests\Admin;

use App\Enums\InstructionAudience;
use App\Enums\InstructionType;
use App\Models\Instruction;
use App\Support\VideoEmbed;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Форма инструкции: общие поля плюс то, что требует выбранный формат.
 *
 * Тело зависит от формата: тексту нужны блоки, PDF — файл, видео — ссылка
 * или файл. При правке уже загруженный файл остаётся, пока его не сняли
 * (`remove_file` / `remove_video`), поэтому «обязателен» проверяется с
 * оглядкой на то, что уже лежит в media.
 */
class InstructionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $ability = $this->route('instruction') ? 'instructions.edit' : 'instructions.create';

        return $this->user()?->can($ability) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'type' => ['required', Rule::in(InstructionType::values())],
            'audiences' => ['required', 'array', 'min:1'],
            'audiences.*' => [Rule::in(InstructionAudience::values())],
            'is_published' => ['boolean'],
            'content' => ['nullable', 'string'],
            'video_url' => ['nullable', 'string', 'max:500', 'url'],
            'cover' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp,gif', 'max:20480'],
            'pdf' => ['nullable', 'file', 'mimes:pdf', 'max:51200'],
            'video' => ['nullable', 'file', 'mimetypes:video/mp4,video/webm,video/quicktime', 'max:51200'],
            'remove_cover' => ['boolean'],
            'remove_file' => ['boolean'],
            'remove_video' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Укажите заголовок инструкции.',
            'audiences.required' => 'Выберите, кому показывать инструкцию.',
            'audiences.min' => 'Выберите, кому показывать инструкцию.',
            'video_url.url' => 'Ссылка на видео должна быть полным адресом.',
            'pdf.mimes' => 'Загрузить можно только PDF.',
            'pdf.max' => 'PDF не больше 50 МБ.',
            'video.mimetypes' => 'Видео — MP4, WebM или MOV.',
            'video.max' => 'Видео не больше 50 МБ. Ролик длиннее — выложите на YouTube, Rutube или VK и дайте ссылку.',
            'cover.max' => 'Обложка не больше 20 МБ.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $type = InstructionType::tryFrom((string) $this->input('type'));
            /** @var Instruction|null $existing */
            $existing = $this->route('instruction');

            match ($type) {
                InstructionType::TEXT => $this->requireText($v),
                InstructionType::PDF => $this->requirePdf($v, $existing),
                InstructionType::VIDEO => $this->requireVideo($v, $existing),
                null => null,
            };
        });
    }

    private function requireText(Validator $v): void
    {
        if ($this->isEmptyContent((string) $this->input('content'))) {
            $v->errors()->add('content', 'Напишите текст инструкции.');
        }
    }

    private function requirePdf(Validator $v, ?Instruction $existing): void
    {
        $keepsExisting = $existing?->getFirstMedia(Instruction::COLLECTION_FILE) !== null
            && ! $this->boolean('remove_file');

        if (! $this->hasFile('pdf') && ! $keepsExisting) {
            $v->errors()->add('pdf', 'Загрузите PDF-файл инструкции.');
        }
    }

    private function requireVideo(Validator $v, ?Instruction $existing): void
    {
        $url = trim((string) $this->input('video_url'));

        if ($url !== '' && VideoEmbed::from($url) === null) {
            $v->errors()->add('video_url', 'Поддерживаются ссылки YouTube, Rutube, VK Видео и Vimeo.');

            return;
        }

        $keepsExisting = $existing?->getFirstMedia(Instruction::COLLECTION_VIDEO) !== null
            && ! $this->boolean('remove_video');

        if ($url === '' && ! $this->hasFile('video') && ! $keepsExisting) {
            $v->errors()->add('video_url', 'Дайте ссылку на ролик или загрузите видеофайл.');
        }
    }

    /**
     * Пустой редактор присылает JSON без блоков — это не текст.
     */
    private function isEmptyContent(string $content): bool
    {
        $content = trim($content);

        if ($content === '') {
            return true;
        }

        $decoded = json_decode($content, true);

        if (is_array($decoded) && array_key_exists('blocks', $decoded)) {
            return $decoded['blocks'] === [];
        }

        return false;
    }
}
