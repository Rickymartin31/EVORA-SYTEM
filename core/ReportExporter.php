<?php

use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ReportExporter
{
    public static function pdf(string $title, array $columns, array $rows): void
    {
        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: sans-serif; font-size: 11px; }
            h2 { margin-bottom: 4px; }
            .meta { color: #666; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
            th { background: #4f46e5; color: #fff; }
            tr:nth-child(even) { background: #f4f6fb; }
        </style></head><body>';
        $html .= '<h2>EVORA — ' . htmlspecialchars($title) . '</h2>';
        $html .= '<p class="meta">Generated on ' . date('F d, Y H:i') . '</p>';
        $html .= '<table><thead><tr>';
        foreach ($columns as $col) {
            $html .= '<th>' . htmlspecialchars($col) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($row as $cell) {
                $html .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', count($columns) > 5 ? 'landscape' : 'portrait');
        $dompdf->render();
        $dompdf->stream(self::filename($title, 'pdf'), ['Attachment' => true]);
        exit;
    }

    public static function excel(string $title, array $columns, array $rows): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Report');

        $sheet->fromArray($columns, null, 'A1');
        $sheet->getStyle('A1:' . self::colLetter(count($columns)) . '1')->getFont()->setBold(true);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_values($row), null, 'A' . $rowIndex);
            $rowIndex++;
        }

        foreach (range(1, count($columns)) as $colIndex) {
            $sheet->getColumnDimensionByColumn($colIndex)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . self::filename($title, 'xlsx') . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    /**
     * Multi-section report — each section is either 'keyvalue' (rows of
     * [label, value] pairs, rendered as a 2-column table) or 'table' (a
     * normal columns+rows table). Used by the per-event financial report;
     * the plain pdf()/excel() above stay untouched for the generic Reports page.
     */
    public static function sectionedPdf(string $title, string $eventName, array $sections): void
    {
        $maxCols = 2;
        foreach ($sections as $section) {
            if ($section['type'] === 'table') {
                $maxCols = max($maxCols, count($section['columns']));
            }
        }

        $html = '<html><head><meta charset="utf-8"><style>
            body { font-family: sans-serif; font-size: 11px; }
            h2 { margin-bottom: 4px; }
            h3 { margin: 18px 0 6px; color: #4f46e5; }
            .meta { color: #666; margin-bottom: 14px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
            th { background: #4f46e5; color: #fff; }
            tr:nth-child(even) { background: #f4f6fb; }
            .kv td:first-child { font-weight: bold; width: 240px; background: #f8f9fc; }
        </style></head><body>';
        $html .= '<h2>EVORA — ' . htmlspecialchars($title) . '</h2>';
        $html .= '<p class="meta">Generated on ' . date('F d, Y H:i') . '</p>';

        foreach ($sections as $section) {
            $html .= '<h3>' . htmlspecialchars($section['title']) . '</h3>';
            if ($section['type'] === 'table') {
                $html .= '<table><thead><tr>';
                foreach ($section['columns'] as $col) {
                    $html .= '<th>' . htmlspecialchars($col) . '</th>';
                }
                $html .= '</tr></thead><tbody>';
                if (empty($section['rows'])) {
                    $html .= '<tr><td colspan="' . count($section['columns']) . '">No data.</td></tr>';
                }
                foreach ($section['rows'] as $row) {
                    $html .= '<tr>';
                    foreach ($row as $cell) {
                        $html .= '<td>' . htmlspecialchars((string) $cell) . '</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            } else {
                $html .= '<table class="kv"><tbody>';
                foreach ($section['rows'] as $row) {
                    $html .= '<tr><td>' . htmlspecialchars((string) $row[0]) . '</td><td>' . htmlspecialchars((string) $row[1]) . '</td></tr>';
                }
                $html .= '</tbody></table>';
            }
        }
        $html .= '</body></html>';

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $maxCols > 5 ? 'landscape' : 'portrait');
        $dompdf->render();
        $dompdf->stream(self::sectionedFilename($eventName, 'pdf'), ['Attachment' => true]);
        exit;
    }

    public static function sectionedExcel(string $title, string $eventName, array $sections): void
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Financial Report');

        $rowIndex = 1;
        $sheet->setCellValue('A' . $rowIndex, 'EVORA — ' . $title);
        $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true)->setSize(14);
        $rowIndex += 2;

        $maxCols = 2;
        foreach ($sections as $section) {
            $sheet->setCellValue('A' . $rowIndex, $section['title']);
            $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true)->setSize(12);
            $rowIndex++;

            if ($section['type'] === 'table') {
                $maxCols = max($maxCols, count($section['columns']));
                $sheet->fromArray($section['columns'], null, 'A' . $rowIndex);
                $sheet->getStyle('A' . $rowIndex . ':' . self::colLetter(count($section['columns'])) . $rowIndex)->getFont()->setBold(true);
                $rowIndex++;
                foreach ($section['rows'] as $row) {
                    $sheet->fromArray(array_values($row), null, 'A' . $rowIndex);
                    $rowIndex++;
                }
            } else {
                foreach ($section['rows'] as $row) {
                    $sheet->setCellValue('A' . $rowIndex, (string) $row[0]);
                    $sheet->setCellValue('B' . $rowIndex, (string) $row[1]);
                    $sheet->getStyle('A' . $rowIndex)->getFont()->setBold(true);
                    $rowIndex++;
                }
            }
            $rowIndex++; // spacer row between sections
        }

        foreach (range(1, $maxCols) as $colIndex) {
            $sheet->getColumnDimensionByColumn($colIndex)->setAutoSize(true);
        }

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="' . self::sectionedFilename($eventName, 'xlsx') . '"');
        header('Cache-Control: max-age=0');

        $writer = new Xlsx($spreadsheet);
        $writer->save('php://output');
        exit;
    }

    private static function colLetter(int $count): string
    {
        return \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($count);
    }

    private static function filename(string $title, string $ext): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '_', strtolower($title));
        return trim($slug, '_') . '_' . date('Ymd_His') . '.' . $ext;
    }

    private static function sectionedFilename(string $eventName, string $ext): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', $eventName);
        $slug = trim($slug, '_');

        return 'EVORA_' . $slug . '_Financial_Report.' . $ext;
    }
}
