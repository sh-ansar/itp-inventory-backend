<?php

namespace App\Domain\Inventory;

use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Small dependency-free XLSX writer. Every external value is an inline string,
 * never a formula; large reports are streamed into a temporary sheet on disk.
 */
final class XlsxExport
{
    private const NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private static function xml(string $text): string
    {
        // Control characters are not legal in SpreadsheetML 1.0.
        $text = mb_scrub($text, 'UTF-8');
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $text);

        return htmlspecialchars($text ?? '', ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    /** @param iterable<array> $rows */
    public function download(string $name, string $sheetName, iterable $rows): BinaryFileResponse
    {
        $sheet = tempnam(sys_get_temp_dir(), 'itp-sheet-');
        $xlsx = tempnam(sys_get_temp_dir(), 'itp-xlsx-');
        abort_unless($sheet !== false && $xlsx !== false, 507, 'Unable to allocate export storage');
        $handle = fopen($sheet, 'wb');
        abort_unless($handle !== false, 507, 'Unable to create export sheet');

        try {
            fwrite($handle, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
                '<worksheet xmlns="'.self::NS.'"><sheetData>');
            $number = 0;
            foreach ($rows as $row) {
                $number++;
                if ($number > 50001) abort(413, 'Export exceeds 50,000 records');
                fwrite($handle, '<row r="'.$number.'">');
                foreach (array_values($row) as $position => $value) {
                    $cell = self::column($position).$number;
                    $safe = self::xml((string) ($value ?? ''));
                    fwrite($handle, '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'.
                        $safe.'</t></is></c>');
                }
                fwrite($handle, '</row>');
            }
            fwrite($handle, '</sheetData></worksheet>');
            fclose($handle);
            $handle = false;

            $zip = new ZipArchive();
            abort_unless($zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 507, 'Unable to prepare XLSX archive');
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'.
                '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
                '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                '<Default Extension="xml" ContentType="application/xml"/>'.
                '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
                '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
                '</Types>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'.
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
                '</Relationships>');
            $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'.
                '<workbook xmlns="'.self::NS.'" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
                '<sheets><sheet name="'.self::xml(mb_substr($sheetName, 0, 31)).'" sheetId="1" r:id="rId1"/></sheets>'.
                '</workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'.
                '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
                '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
                '</Relationships>');
            $zip->addFile($sheet, 'xl/worksheets/sheet1.xml');
            abort_unless($zip->close(), 507, 'Unable to finish XLSX export');
        } catch (\Throwable $e) {
            if (is_resource($handle)) fclose($handle);
            @unlink($sheet);
            @unlink($xlsx);
            throw $e;
        }
        @unlink($sheet);
        return response()->download($xlsx, $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }
}
