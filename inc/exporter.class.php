<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * The plugin's single tabular exporter: turns a (filename, column labels,
 * row iterable) triple into a downloadable CSV / .xls file or an inline
 * print-ready HTML view. Extracted from front/export.php so the activity
 * log export (front/export.php) and the command-audit report export
 * (front/reportexport.php) share one implementation. Rows are consumed
 * lazily - pass a Generator for large result sets so nothing materialises
 * the whole set in memory.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanExporter
{
    /**
     * Neutralise spreadsheet "formula injection": a cell value that
     * starts with =, +, -, @ or a tab/CR is otherwise run as a formula by
     * Excel on open - a well-known CSV/XLS export risk for any app
     * exporting untrusted text (here: JumpServer-supplied usernames,
     * commands, asset names).
     */
    public static function sanitizeCell(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
            return "'" . $value;
        }

        return $value;
    }

    /**
     * Stream the dataset as a real comma-separated CSV, forced as a
     * download. A leading UTF-8 BOM keeps Excel from misreading non-ASCII
     * text. $filename must be caller-built from trusted parts only (never
     * request input) - there is no header-injection escaping here.
     *
     * @param list<string>          $columns Header labels, in order.
     * @param iterable<list<scalar>> $rows    One list of cell values per data row.
     */
    public static function sendCsv(string $filename, array $columns, iterable $rows): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        $output = fopen('php://output', 'w');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $columns);

        foreach ($rows as $row) {
            fputcsv($output, array_map(
                static fn($v): string => self::sanitizeCell((string) $v),
                array_values((array) $row)
            ));
        }

        fclose($output);
    }

    /**
     * Send the dataset as an Excel-openable .xls: an HTML table with the
     * classic application/vnd.ms-excel content type (no spreadsheet
     * library needed). Streamed row by row.
     *
     * @param list<string>          $columns
     * @param iterable<list<scalar>> $rows
     * @param string                $emptyLabel Optional empty-dataset message; if empty, uses default.
     */
    public static function sendXls(string $filename, array $columns, iterable $rows, string $emptyLabel = ''): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }

        echo '<!doctype html><html><head><meta charset="UTF-8"></head><body>';
        echo '<table border="1" cellspacing="0" cellpadding="4"><thead><tr>';
        foreach ($columns as $col) {
            echo '<th>' . htmlescape($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $any = false;
        foreach ($rows as $row) {
            $any = true;
            echo '<tr>';
            foreach (array_values((array) $row) as $value) {
                echo '<td>' . htmlescape((string) $value) . '</td>';
            }
            echo '</tr>';
        }
        if (!$any) {
            echo '<tr><td colspan="' . count($columns) . '">'
                . htmlescape($emptyLabel !== '' ? $emptyLabel : __('No data.', 'integrasipaman')) . '</td></tr>';
        }

        echo '</tbody></table></body></html>';
    }

    /**
     * Send a standalone, print-ready HTML page (NOT an attachment - it is
     * meant to open in the browser so the user can "Save as PDF" from the
     * print dialog), with proper @page / @media print rules.
     *
     * @param list<string>          $columns
     * @param iterable<list<scalar>> $rows
     * @param string                $emptyLabel Optional empty-dataset message; if empty, uses default.
     */
    public static function sendPrintView(string $title, string $meta, array $columns, iterable $rows, string $emptyLabel = ''): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=UTF-8');
        }

        echo '<!doctype html><html><head><meta charset="UTF-8">';
        echo '<title>' . htmlescape($title) . '</title>';
        echo '<style>
            @page { size: A4 landscape; margin: 14mm; }
            body { font-family: Arial, Helvetica, sans-serif; color: #16202e; margin: 0; padding: 24px; }
            h1 { font-size: 18px; margin: 0 0 4px; }
            .meta { font-size: 12px; color: #62708a; margin-bottom: 16px; }
            table { width: 100%; border-collapse: collapse; font-size: 12px; }
            th, td { border: 1px solid #c7ceda; padding: 6px 8px; text-align: left; }
            th { background: #f6f8fc; text-transform: uppercase; letter-spacing: 0.03em; font-size: 10px; }
            .print-bar { margin-bottom: 16px; }
            .print-bar button { font-size: 13px; padding: 6px 14px; cursor: pointer; }
            @media print { .print-bar { display: none; } body { padding: 0; } }
        </style></head><body>';

        echo '<div class="print-bar"><button type="button" onclick="window.print()">'
            . htmlescape(__('Print / Save as PDF', 'integrasipaman')) . '</button></div>';
        echo '<h1>' . htmlescape($title) . '</h1>';
        echo '<div class="meta">' . htmlescape($meta) . '</div>';

        echo '<table><thead><tr>';
        foreach ($columns as $col) {
            echo '<th>' . htmlescape($col) . '</th>';
        }
        echo '</tr></thead><tbody>';

        $any = false;
        foreach ($rows as $row) {
            $any = true;
            echo '<tr>';
            foreach (array_values((array) $row) as $value) {
                echo '<td>' . htmlescape((string) $value) . '</td>';
            }
            echo '</tr>';
        }
        if (!$any) {
            echo '<tr><td colspan="' . count($columns) . '">'
                . htmlescape($emptyLabel !== '' ? $emptyLabel : __('No data.', 'integrasipaman')) . '</td></tr>';
        }

        echo '</tbody></table></body></html>';
    }
}
