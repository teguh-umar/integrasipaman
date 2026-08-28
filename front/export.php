<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Standalone activity log page (linked from the config page's "View
 * Activity Log" button, since GLPI 11 dashboard cards cannot host a data
 * table themselves - see inc/dashboard.class.php's class docblock).
 * Default view is a browsable HTML table; also streams the same dataset
 * as CSV, Excel (.xls), or a print-ready HTML view (for "Save as PDF"),
 * reusing inc/dashboard.class.php's filtering logic so every format
 * always matches the same underlying data.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/dashboard.class.php');

// Any authenticated GLPI user may export this data - the same audience
// that can already see it rendered live on the dashboard widget itself,
// which has no stricter right of its own.
Session::checkLoginUser();

$allowed_formats = ['view', 'csv', 'xls', 'pdf'];
$format          = (string) ($_GET['format'] ?? 'view');

if (!in_array($format, $allowed_formats, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid export format. Expected one of: ' . implode(', ', $allowed_formats) . '.';
    exit;
}

// Reuses PluginIntegrasipamanDashboard's own option-parsing and
// WHERE-clause logic (see getFilteredRows() in inc/dashboard.class.php):
// every value below is defaulted/validated there exactly the same way as
// when the widget renders itself, so a tampered or missing query
// parameter can never produce anything other than a safely-defaulted,
// still-correctly-scoped export.
$options = [
    'filter_event' => (string) ($_GET['filter_event'] ?? 'all'),
    'date_preset'  => (string) ($_GET['date_preset'] ?? 'last_30_days'),
    'date_from'    => (string) ($_GET['date_from'] ?? ''),
    'date_to'      => (string) ($_GET['date_to'] ?? ''),
];

$rows = PluginIntegrasipamanDashboard::getFilteredRows($options);

$filename_stub = 'jumpserver_export_' . date('Ymd_His');

switch ($format) {
    case 'view':
        jumpserver_export_send_view($rows, $options);
        break;

    case 'csv':
        jumpserver_export_send_csv($rows, $filename_stub . '.csv');
        break;

    case 'xls':
        jumpserver_export_send_xls($rows, $filename_stub . '.xls');
        break;

    case 'pdf':
        jumpserver_export_send_print_view($rows, $options);
        break;
}

exit;

/**
 * Column headers shared by every export format, in a fixed order.
 *
 * @return array<int, string>
 */
function jumpserver_export_columns(): array
{
    return [
        __('Timestamp', 'integrasipaman'),
        __('Event Type', 'integrasipaman'),
        __('Username', 'integrasipaman'),
        __('Asset Target', 'integrasipaman'),
        __('IP Address', 'integrasipaman'),
        __('Message', 'integrasipaman'),
    ];
}

/**
 * Pull the export-column values out of one DB row, in the same fixed
 * order as jumpserver_export_columns().
 *
 * @param array<string, mixed> $row
 *
 * @return array<int, string>
 */
function jumpserver_export_row_values(array $row): array
{
    return [
        (string) ($row['date_creation'] ?? ''),
        (string) ($row['event_type'] ?? ''),
        (string) ($row['username'] ?? ''),
        (string) ($row['asset_name'] ?? ''),
        (string) ($row['ip_address'] ?? ''),
        (string) ($row['message'] ?? ''),
    ];
}

/**
 * Neutralize spreadsheet "formula injection": a cell value coming from
 * JumpServer-supplied data (username, message, ...) that starts with
 * =, +, -, @ or a tab/CR is otherwise interpreted as a formula by Excel
 * when the file is opened, which is a well-known CSV/XLS export risk for
 * any application exporting untrusted text.
 */
function jumpserver_export_sanitize_cell(string $value): string
{
    if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
        return "'" . $value;
    }

    return $value;
}

/**
 * Stream the dataset as a real comma-separated CSV file, forced as a
 * download via a Content-Disposition attachment header.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function jumpserver_export_send_csv(array $rows, string $filename): void
{
    // $filename is built exclusively from a server-side timestamp and a
    // fixed extension (see front/export.php above) - never from request
    // input - so there is no header-injection surface here.
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');

    // A UTF-8 BOM keeps Excel from misreading non-ASCII usernames/asset
    // names when it opens the file.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, jumpserver_export_columns());

    foreach ($rows as $row) {
        $values = array_map('jumpserver_export_sanitize_cell', jumpserver_export_row_values($row));
        fputcsv($output, $values);
    }

    fclose($output);
}

/**
 * Build the shared, fully-escaped <table> markup used by both the Excel
 * export and the printable PDF view below.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function jumpserver_export_build_table_html(array $rows): string
{
    $html = '<table border="1" cellspacing="0" cellpadding="4"><thead><tr>';

    foreach (jumpserver_export_columns() as $col) {
        $html .= '<th>' . htmlescape($col) . '</th>';
    }

    $html .= '</tr></thead><tbody>';

    if (empty($rows)) {
        $html .= '<tr><td colspan="' . count(jumpserver_export_columns()) . '">'
            . htmlescape(__('No data for the selected filters.', 'integrasipaman'))
            . '</td></tr>';
    }

    foreach ($rows as $row) {
        $html .= '<tr>';

        foreach (jumpserver_export_row_values($row) as $value) {
            $html .= '<td>' . htmlescape($value) . '</td>';
        }

        $html .= '</tr>';
    }

    $html .= '</tbody></table>';

    return $html;
}

/**
 * Send the dataset as an Excel-openable .xls file: an HTML table wrapped
 * with the classic application/vnd.ms-excel content type, which Excel
 * opens natively without requiring a spreadsheet-generation library.
 *
 * @param array<int, array<string, mixed>> $rows
 */
function jumpserver_export_send_xls(array $rows, string $filename): void
{
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo '<!doctype html><html><head><meta charset="UTF-8"></head><body>';
    echo jumpserver_export_build_table_html($rows);
    echo '</body></html>';
}

/**
 * Send a clean, standalone, print-ready HTML page (not an attachment -
 * it is meant to open in the browser so the user can print it / choose
 * "Save as PDF"), with proper @page/@media print rules.
 *
 * @param array<int, array<string, mixed>> $rows
 * @param array<string, string>            $options Active filter options, shown as the export's context.
 */
function jumpserver_export_send_print_view(array $rows, array $options): void
{
    // Rendered inline in the browser, not force-downloaded: the point is
    // a print preview the user can save as PDF from their browser's own
    // print dialog.
    header('Content-Type: text/html; charset=UTF-8');

    $generated_at = date('Y-m-d H:i');

    echo '<!doctype html><html><head><meta charset="UTF-8">';
    echo '<title>' . htmlescape(__('JumpServer Activity Export', 'integrasipaman')) . '</title>';
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

    echo '<h1>' . htmlescape(__('JumpServer Activity Export', 'integrasipaman')) . '</h1>';
    echo '<div class="meta">'
        . htmlescape(sprintf(
            __('Event filter: %1$s - Date range: %2$s - Generated %3$s', 'integrasipaman'),
            $options['filter_event'],
            $options['date_preset'],
            $generated_at
        ))
        . '</div>';

    echo jumpserver_export_build_table_html($rows);

    echo '</body></html>';
}

/**
 * Send the default, browsable HTML view of the dataset: a standalone page
 * (not a download) styled like the plugin's dashboard cards, with a
 * Date/Event/User/Asset/IP/Status table (status shown as a colored pill)
 * and links to the CSV/Excel/PDF export formats that carry the same
 * active filters over as query parameters.
 *
 * @param array<int, array<string, mixed>> $rows
 * @param array<string, string>            $options Active filter options.
 */
function jumpserver_export_send_view(array $rows, array $options): void
{
    header('Content-Type: text/html; charset=UTF-8');

    $self_url = Plugin::getWebDir('integrasipaman') . '/front/export.php';

    $formats = [
        'csv' => __('CSV', 'integrasipaman'),
        'xls' => __('Excel', 'integrasipaman'),
        'pdf' => __('Print / PDF', 'integrasipaman'),
    ];

    echo '<!doctype html><html><head><meta charset="UTF-8">';
    echo '<title>' . htmlescape(__('JumpServer Activity Log', 'integrasipaman')) . '</title>';
    echo '<style>
        :root {
            --ink: #16202e; --muted: #62708a; --primary: #206bc4;
            --bg-canvas: #eef1f7; --surface: #ffffff; --surface-sunken: #f6f8fc;
            --border: #dfe3ec; --success: #2fb344; --success-soft: #e6f6e9;
            --danger: #d63939; --danger-soft: #fbe7e7;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg-canvas); color: var(--ink);
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.5;
        }
        .page { max-width: 1080px; margin: 0 auto; padding: 2rem 1.5rem 4rem; }
        h1 { font-size: 1.3rem; margin: 0 0 0.3rem; }
        .meta { font-size: 0.85rem; color: var(--muted); margin-bottom: 1.25rem; }
        .toolbar { display: flex; align-items: center; gap: 0.6rem; margin-bottom: 1rem; }
        .toolbar .lbl { font-size: 0.82rem; color: var(--muted); }
        .toolbar a {
            font-size: 0.82rem; font-weight: 600; text-decoration: none; color: var(--primary);
            border: 1px solid var(--border); background: var(--surface);
            border-radius: 8px; padding: 0.35rem 0.8rem;
        }
        .panel {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 12px; overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th {
            text-align: left; font-size: 0.68rem; text-transform: uppercase;
            letter-spacing: 0.05em; color: var(--muted); font-weight: 700;
            padding: 0.6rem 0.9rem; background: var(--surface-sunken);
            border-bottom: 1px solid var(--border);
        }
        td { padding: 0.6rem 0.9rem; border-bottom: 1px solid var(--border); }
        td.mono { font-family: ui-monospace, "JetBrains Mono", monospace; font-size: 0.8rem; color: var(--muted); }
        .status-pill {
            display: inline-flex; align-items: center; gap: 0.35rem;
            font-size: 0.72rem; font-weight: 700; padding: 0.15rem 0.55rem; border-radius: 999px;
        }
        .status-pill.success { color: var(--success); background: var(--success-soft); }
        .status-pill.failed { color: var(--danger); background: var(--danger-soft); }
        .status-pill::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }
    </style></head><body>';

    echo '<div class="page">';
    echo '<h1>' . htmlescape(__('JumpServer Activity Log', 'integrasipaman')) . '</h1>';
    echo '<div class="meta">'
        . htmlescape(sprintf(
            __('Event filter: %1$s - Date range: %2$s - %3$d row(s)', 'integrasipaman'),
            $options['filter_event'],
            $options['date_preset'],
            count($rows)
        ))
        . '</div>';

    echo '<div class="toolbar"><span class="lbl">' . htmlescape(__('Export:', 'integrasipaman')) . '</span>';
    foreach ($formats as $format_key => $format_label) {
        $url = $self_url . '?' . http_build_query(array_merge(['format' => $format_key], $options));
        echo '<a href="' . htmlescape($url) . '">' . htmlescape($format_label) . '</a>';
    }
    echo '</div>';

    echo '<div class="panel"><table><thead><tr>';
    foreach ([
        __('Date', 'integrasipaman'),
        __('Event', 'integrasipaman'),
        __('User', 'integrasipaman'),
        __('Asset', 'integrasipaman'),
        __('IP Address', 'integrasipaman'),
        __('Status', 'integrasipaman'),
    ] as $col) {
        echo '<th>' . htmlescape($col) . '</th>';
    }
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="6">' . htmlescape(__('No data for the selected filters.', 'integrasipaman')) . '</td></tr>';
    }

    foreach ($rows as $row) {
        $status       = (string) ($row['status'] ?? '');
        $status_class = $status === 'failed' ? 'failed' : 'success';

        echo '<tr>';
        echo '<td class="mono">' . htmlescape((string) ($row['date_creation'] ?? '')) . '</td>';
        echo '<td>' . htmlescape((string) ($row['event_type'] ?? '')) . '</td>';
        echo '<td>' . htmlescape((string) ($row['username'] ?? '')) . '</td>';
        echo '<td>' . htmlescape((string) ($row['asset_name'] ?? '')) . '</td>';
        echo '<td class="mono">' . htmlescape((string) ($row['ip_address'] ?? '')) . '</td>';
        echo '<td><span class="status-pill ' . $status_class . '">' . htmlescape($status) . '</span></td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
    echo '</div></body></html>';
}
