<?php

namespace App\Services\Reports;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Generic tabular Excel writer shared by every report type except the grade
 * sheet (whose merged section headers need a bespoke builder). Writes a
 * title, optional meta lines, a bold header row, data rows with an optional
 * per-cell background color callback, and optional footer lines — then saves
 * to the given path on the "local" disk.
 */
class ExcelTableWriter
{
    /**
     * @param  string[]  $metaLines
     * @param  string[]  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  (callable(int $rowIndex, int $colIndex, mixed $value): ?string)|null  $cellStyle  returns a 6-digit hex color (no '#') or null
     * @param  string[]  $footerLines
     */
    public static function write(
        string $relativePath,
        string $title,
        array $metaLines,
        array $headers,
        array $rows,
        ?callable $cellStyle = null,
        array $footerLines = []
    ): void {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $lastColumn = Coordinate::stringFromColumnIndex(max(count($headers), 1));

        $currentRow = 1;

        $sheet->setCellValue("A{$currentRow}", $title);
        $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells("A{$currentRow}:{$lastColumn}{$currentRow}");
        $currentRow++;

        foreach ($metaLines as $line) {
            $sheet->setCellValue("A{$currentRow}", $line);
            $sheet->mergeCells("A{$currentRow}:{$lastColumn}{$currentRow}");
            $currentRow++;
        }

        $currentRow++; // blank separator row

        $headerRow = $currentRow;
        foreach ($headers as $colIndex => $header) {
            $column = Coordinate::stringFromColumnIndex($colIndex + 1);
            $sheet->setCellValue("{$column}{$headerRow}", $header);
        }
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFont()->setBold(true);
        $sheet->getStyle("A{$headerRow}:{$lastColumn}{$headerRow}")->getFill()
            ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9D9D9');
        $currentRow++;

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $column = Coordinate::stringFromColumnIndex($colIndex + 1);
                $sheet->setCellValue("{$column}{$currentRow}", $value);

                $color = $cellStyle ? $cellStyle($rowIndex, $colIndex, $value) : null;
                if ($color) {
                    $sheet->getStyle("{$column}{$currentRow}")->getFill()
                        ->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($color);
                }
            }
            $currentRow++;
        }

        $currentRow++; // blank separator row before footer

        foreach ($footerLines as $line) {
            $sheet->setCellValue("A{$currentRow}", $line);
            $sheet->getStyle("A{$currentRow}")->getFont()->setBold(true);
            $currentRow++;
        }

        foreach (range(1, max(count($headers), 1)) as $colIndex) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($colIndex))->setAutoSize(true);
        }

        $absolutePath = Storage::disk('local')->path($relativePath);
        File::ensureDirectoryExists(dirname($absolutePath));
        (new Xlsx($spreadsheet))->save($absolutePath);
    }
}
