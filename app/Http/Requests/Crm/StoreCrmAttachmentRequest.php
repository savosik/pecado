<?php

namespace App\Http\Requests\Crm;

use App\Support\Crm\CrmAttachments;
use App\Support\Crm\CrmEntityMap;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCrmAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('crm-attachments.create') ?? false;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'entity_type' => ['required', 'string', Rule::in(CrmEntityMap::types())],
            'entity_id' => ['required', 'integer', 'min:1'],
            // Голос — отдельная коллекция со своим списком типов: в общий
            // список вложений аудио не попадает, а в голосовое досье не попадают
            // счета и спецификации.
            'kind' => ['sometimes', Rule::in(['file', 'voice'])],
            'file' => [
                'required',
                'file',
                'max:'.(CrmAttachments::MAX_MB * 1024),
                'mimetypes:'.implode(',', $this->isVoice() ? CrmAttachments::VOICE_MIMES : CrmAttachments::MIMES),
            ],
        ];
    }

    /**
     * Голосовая запись или обычный файл.
     */
    public function isVoice(): bool
    {
        return $this->input('kind') === 'voice';
    }

    /**
     * Коллекция MediaLibrary, в которую ляжет файл.
     */
    public function collection(): string
    {
        return $this->isVoice()
            ? CrmAttachments::VOICE_COLLECTION
            : CrmAttachments::COLLECTION;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'entity_type.required' => 'Не указано, к чему прикрепляется файл.',
            'entity_type.in' => 'К этому типу записей файлы не прикрепляются.',
            'entity_id.required' => 'Не указана запись, к которой прикрепляется файл.',
            'file.required' => 'Выберите файл.',
            'file.max' => 'Файл больше '.CrmAttachments::MAX_MB.' МБ — уменьшите его или отправьте ссылкой.',
            'file.mimetypes' => $this->isVoice()
                ? 'Такой формат записи не поддерживается. Разрешены webm, ogg, mp3, m4a, mp4 и wav.'
                : 'Такой тип файла не поддерживается. Разрешены изображения, PDF, документы Word и Excel, CSV и текст.',
        ];
    }
}
