<?php
/**
 * PRINT REPORT LAYOUT (server side)
 * ---------------------------------------------------------------------
 * Renders a complete printable document in the same format as the
 * Visitor Vehicle Report (visitor.php -> Download PDF):
 *   organization / location / report title / generated date,
 *   2px rule, numbered bordered tables, small centered footer.
 * Styles: assets/css/print_report.css (shared with assets/js/print_report.js).
 */

const SECSYS_PRINT_ORGANIZATION = 'L. Surveillance';
const SECSYS_PRINT_LOCATION = 'Lessandra Subdivision, Phase 2, Salinas 1, Bacoor, Cavite';
const SECSYS_PRINT_FOOTER = 'L. Surveillance Security System';

function secsysPrintEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * One bordered table. $columns = [label, ...] or [[label, width%], ...];
 * $rows = list of arrays of cell text (already plain text; escaped here).
 * A cell may be ['text' => ..., 'class' => 'center'].
 */
function secsysPrintTable(array $columns, array $rows, string $emptyText, string $sectionTitle = ''): string
{
    $html = $sectionTitle !== '' ? '<div class="section-title">' . secsysPrintEsc($sectionTitle) . '</div>' : '';
    $html .= '<table><thead><tr>';
    foreach ($columns as $col) {
        if (is_array($col)) {
            $html .= '<th style="width:' . (float)$col[1] . '%;">' . secsysPrintEsc($col[0]) . '</th>';
        } else {
            $html .= '<th>' . secsysPrintEsc($col) . '</th>';
        }
    }
    $html .= '</tr></thead><tbody>';

    if (!$rows) {
        $html .= '<tr><td class="empty" colspan="' . count($columns) . '">' . secsysPrintEsc($emptyText) . '</td></tr>';
    }
    foreach ($rows as $row) {
        $html .= '<tr>';
        foreach ($row as $cell) {
            if (is_array($cell)) {
                $html .= '<td class="' . secsysPrintEsc($cell['class'] ?? '') . '">' . secsysPrintEsc($cell['text'] ?? '') . '</td>';
            } else {
                $html .= '<td>' . secsysPrintEsc($cell) . '</td>';
            }
        }
        $html .= '</tr>';
    }
    return $html . '</tbody></table>';
}

/**
 * Output the whole printable page and open the print dialog.
 * $metaLines: extra lines under "Generated" (e.g. 'Period: November 2026').
 */
function secsysBuildPrintDocumentHtml(string $reportTitle, array $metaLines, string $bodyHtml, string $footerSubtitle, bool $autoPrint = true): string
{
    $cssPath = __DIR__ . '/../assets/css/print_report.css';
    $css = is_file($cssPath) ? (string)file_get_contents($cssPath) : '';

    $html  = '<!DOCTYPE html><html><head><meta charset="UTF-8">';
    $html .= '<title>' . secsysPrintEsc($reportTitle) . '</title>';
    $html .= '<style>' . $css . '</style></head><body>';

    $html .= '<div class="header">';
    $html .= '<div class="organization">' . secsysPrintEsc(SECSYS_PRINT_ORGANIZATION) . '</div>';
    $html .= '<div class="location">' . secsysPrintEsc(SECSYS_PRINT_LOCATION) . '</div>';
    $html .= '<div class="report-title">' . secsysPrintEsc($reportTitle) . '</div>';
    $html .= '<div class="generated">Generated: ' . secsysPrintEsc(date('F d, Y h:i A')) . '</div>';
    foreach ($metaLines as $line) {
        $html .= '<div class="report-meta">' . secsysPrintEsc($line) . '</div>';
    }
    $html .= '</div>';

    $html .= $bodyHtml;

    $html .= '<div class="footer">' . secsysPrintEsc(SECSYS_PRINT_FOOTER) . '<br>' . secsysPrintEsc($footerSubtitle) . '</div>';
    if ($autoPrint) {
        $html .= '<script>window.onload = function () { window.print(); };</script>';
    }
    return $html . '</body></html>';
}

/** Output the printable page and open the print dialog. */
function secsysRenderPrintDocument(string $reportTitle, array $metaLines, string $bodyHtml, string $footerSubtitle): void
{
    echo secsysBuildPrintDocumentHtml($reportTitle, $metaLines, $bodyHtml, $footerSubtitle, true);
}

/* =====================================================================
   CSV DOWNLOADS
   Every "Download" button lets the user pick PDF or CSV (format=csv).
   The CSV is produced from the exact HTML used for the PDF, so both
   formats always contain the same rows and columns.
   ===================================================================== */

/** True when the current download request asked for CSV instead of PDF. */
function secsysWantsCsvDownload(): bool
{
    return strtolower(trim((string)($_GET['format'] ?? ''))) === 'csv';
}

/** Keep spreadsheet apps from treating a cell as a formula (CSV injection). */
function secsysCsvSafeCell(string $value): string
{
    if ($value === '') {
        return $value;
    }
    $first = $value[0];
    if (in_array($first, ['=', '+', '@', "\t", "\r"], true)
        || ($first === '-' && strlen($value) > 1 && !preg_match('/^-\s*$|^-?\d/', $value))) {
        return "'" . $value;
    }
    return $value;
}

/**
 * Turn a report's HTML (the same HTML rendered to PDF) into CSV lines:
 * report title / meta / section headings become single-cell lines, and
 * every table becomes a header row plus data rows.
 */
function secsysHtmlReportToCsvRows(string $html): array
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $xpath = new DOMXPath($dom);
    $textClasses = ['organization', 'location', 'report-title', 'generated', 'report-meta',
                    'report-period', 'section-title', 'section-heading'];
    $classTests = [];
    foreach ($textClasses as $cls) {
        $classTests[] = "contains(concat(' ', normalize-space(@class), ' '), ' {$cls} ')";
    }
    $nodes = $xpath->query('//table[not(ancestor::table)] | //*[(' . implode(' or ', $classTests) . ') and not(ancestor::table)]');

    $clean = function (DOMNode $node): string {
        return trim(preg_replace('/\s+/u', ' ', (string)$node->textContent));
    };

    $rows = [];
    foreach ($nodes as $node) {
        if (strtolower($node->nodeName) !== 'table') {
            $text = $clean($node);
            if ($text !== '') {
                $isHeading = preg_match('/\bsection-(title|heading)\b/', (string)$node->getAttribute('class'));
                if ($isHeading && $rows) {
                    $rows[] = [];               // blank line before each section
                }
                $rows[] = [$text];
            }
            continue;
        }

        $rows[] = [];
        foreach ($xpath->query('.//tr', $node) as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                $name = strtolower($cell->nodeName);
                if ($name === 'td' || $name === 'th') {
                    $cells[] = $clean($cell);
                }
            }
            if ($cells && implode('', $cells) !== '') {
                $rows[] = $cells;
            }
        }
    }

    // Drop leading/trailing blank lines
    while ($rows && $rows[0] === []) {
        array_shift($rows);
    }
    while ($rows && end($rows) === []) {
        array_pop($rows);
    }
    return $rows;
}

/** Send a report's HTML as a CSV download (UTF-8 with BOM so Excel shows symbols correctly). */
function secsysStreamCsvFromHtml(string $html, string $filename): void
{
    $filename = preg_replace('/\.pdf$/i', '', basename($filename));
    $filename = preg_replace('/[^A-Za-z0-9._-]+/', '_', $filename) . '.csv';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    foreach (secsysHtmlReportToCsvRows($html) as $row) {
        fputcsv($out, array_map('secsysCsvSafeCell', $row), ',', '"', '\\', "\r\n");
    }
    fclose($out);
}

/**
 * Send the same report as a downloaded PDF (Dompdf, A4 landscape - like the
 * Visitor Vehicle Report's Download PDF).
 */
function secsysStreamPdfReport(string $reportTitle, array $metaLines, string $bodyHtml, string $footerSubtitle, string $filename): void
{
    require_once __DIR__ . '/../vendor/autoload.php';

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Arial');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml(secsysBuildPrintDocumentHtml($reportTitle, $metaLines, $bodyHtml, $footerSubtitle, false));
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream($filename, ['Attachment' => true]);
}
