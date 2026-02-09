<?php

namespace App\Http\Controllers;

use App\Models\ProductExport;
use App\Services\ProductExportService;
use Illuminate\Routing\Controller;

class ProductExportDownloadController extends Controller
{
    protected ProductExportService $exportService;

    public function __construct(ProductExportService $exportService)
    {
        $this->exportService = $exportService;
    }

    /**
     * Download an export file by hash.
     * This is a public route — security is ensured by the hash uniqueness.
     */
    public function download(string $hash)
    {
        $export = ProductExport::where('hash', $hash)
            ->where('is_active', true)
            ->firstOrFail();

        return $this->exportService->generate($export);
    }
}
