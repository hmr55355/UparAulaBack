<?php

namespace App\Services\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

/**
 * Renders a Blade view to a PDF file on the "local" disk. Used both by the
 * generic tabular reports (via reports.generic-table) and by the bespoke
 * grade-sheet / student-bulletin views, which pass their own view name.
 */
class PdfTableRenderer
{
    public static function render(string $relativePath, string $view, array $data): void
    {
        $absolutePath = Storage::disk('local')->path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        Pdf::loadView($view, $data)->save($absolutePath);
    }
}
