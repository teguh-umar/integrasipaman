<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Download controller for the PAM Command Audit report (Piece 4). GET
 * only, read-only: ?report=<key>&format=<csv|xls|pdf>&month=YYYY-MM (or
 * &from=&to=). Routes PluginIntegrasipamanReport datasets through
 * PluginIntegrasipamanExporter. Gated to config/UPDATE like front/pbi.php,
 * which is where its links live.
 * -------------------------------------------------------------------------
 */

include('../../../inc/includes.php');
include_once(__DIR__ . '/../inc/report.class.php');
include_once(__DIR__ . '/../inc/exporter.class.php');

Session::checkLoginUser();
// v2.0.0: gated on the plugin's own right (READ) instead of core `config`
// UPDATE, which used to hand the whole of Setup > General to anyone who
// needed these reports.
include_once(__DIR__ . '/../inc/profile.class.php');
Session::checkRight(PluginIntegrasipamanProfile::RIGHT, READ);

$report = (string) ($_GET['report'] ?? '');
$format = (string) ($_GET['format'] ?? '');

if (!in_array($report, PluginIntegrasipamanReport::REPORTS, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Unknown report. Expected one of: ' . implode(', ', PluginIntegrasipamanReport::REPORTS) . '.';
    exit;
}

$allowed_formats = ['csv', 'xls', 'pdf'];
// COMMAND_DETAIL is CSV-only: an .xls / print view of ~170k rows is not useful.
if ($report === 'command_detail') {
    $allowed_formats = ['csv'];
}
if (!in_array($format, $allowed_formats, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Invalid format for this report. Expected one of: ' . implode(', ', $allowed_formats) . '.';
    exit;
}

// Detail reports get the 366-day cap; summaries are cheap and uncapped.
$cap    = in_array($report, ['command_detail', 'evaluasi_highrisk'], true);
$period = PluginIntegrasipamanReport::resolvePeriod([
    'month' => (string) ($_GET['month'] ?? ''),
    'from'  => (string) ($_GET['from'] ?? ''),
    'to'    => (string) ($_GET['to'] ?? ''),
    'cap'   => $cap,
]);

$opts = [];
if ($report === 'evaluasi_highrisk') {
    // export = the whole period set, honouring an explicit sort only
    $opts = ['sort' => (string) ($_GET['sort'] ?? ''), 'dir' => (string) ($_GET['dir'] ?? 'desc')];
}

$data      = PluginIntegrasipamanReport::report($report, $period, $opts);
$name_part = strtoupper($report);
$filename  = $period['stub'] . '_' . $name_part;

switch ($format) {
    case 'csv':
        PluginIntegrasipamanExporter::sendCsv($filename . '.csv', $data['columns'], $data['rows']);
        break;
    case 'xls':
        PluginIntegrasipamanExporter::sendXls($filename . '.xls', $data['columns'], $data['rows']);
        break;
    case 'pdf':
        PluginIntegrasipamanExporter::sendPrintView(
            sprintf(__('PAM Command Audit — %s', 'integrasipaman'), $name_part),
            sprintf(__('Period: %1$s — generated %2$s', 'integrasipaman'), $period['label'], date('Y-m-d H:i')),
            $data['columns'],
            $data['rows']
        );
        break;
}

exit;
