<?php

use App\Models\PrintedDocument;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ключ склейки форматов одной печатной формы (топик Agent Hub № 9).
 *
 * 1С готовит УПД дополнительно в XLSX и публикует его отдельным стабильным `uuid`
 * — иначе Excel заменил бы PDF по тому же ключу идемпотентности. Для клиента же
 * это один документ, и в кабинете он обязан остаться одной строкой с двумя кнопками.
 * Ключ считается из полей конверта и хранится рядом, чтобы список умел отбирать
 * «главную» запись группы прямо в SQL, не ломая пагинацию.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('printed_documents', function (Blueprint $table) {
            $table->string('variant_key', 40)->nullable()->after('base_document_kind')
                ->comment('Ключ склейки форматов одной печатной формы: sha1 от вида, основания, номера, даты, контрагента и организации; NULL — форма показывается отдельной строкой');
            $table->index('variant_key');
        });

        // Ключи считаются и накопленным формам: XLSX к УПД прошлого месяца
        // склеится с уже лежащим PDF, даже если сам PDF приехал год назад.
        // Пишем через query builder — у печатной формы `updated_at` это снимок
        // обмена, и разовый пересчёт ключа не должен выглядеть как правка документа.
        PrintedDocument::withTrashed()->chunkById(500, function ($documents): void {
            foreach ($documents as $document) {
                $key = $document->buildVariantKey();

                if ($key !== null) {
                    DB::table('printed_documents')->where('id', $document->getKey())->update(['variant_key' => $key]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('printed_documents', function (Blueprint $table) {
            $table->dropIndex(['variant_key']);
            $table->dropColumn('variant_key');
        });
    }
};
