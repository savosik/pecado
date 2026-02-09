<?php

namespace App\Services\ProductExport\Fields\Filters;

use App\Services\ProductExport\RelationFilterField;

class CertificateFilterField extends RelationFilterField
{
    public function key(): string { return 'certificate_id'; }
    public function name(): string { return 'Сертификат'; }
    public function group(): string { return 'Связи'; }
    public function searchUrl(): ?string { return '/admin/certificates/search'; }
    protected function filterMode(): string { return 'relation'; }
    protected function relation(): string { return 'certificates'; }
    protected function relationKey(): string { return 'certificates.id'; }
}
