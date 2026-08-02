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
            'file' => [
                'required',
                'file',
                'max:'.(CrmAttachments::MAX_MB * 1024),
                'mimetypes:'.implode(',', CrmAttachments::MIMES),
            ],
        ];
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
            'file.mimetypes' => 'Такой тип файла не поддерживается. Разрешены изображения, PDF, документы Word и Excel, CSV и текст.',
        ];
    }
}
