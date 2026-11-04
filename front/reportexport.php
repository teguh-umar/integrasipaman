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
Session::checkRight(PluginIntegrasipamanProfile::RIGHT_REPORT, READ);

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
    // export = the whole matching set, honouring an explicit sort and the
    // on-screen search. The search selects ROWS, so someone who filters the
    // table and then clicks Excel means those rows - all of them, not the
    // page they can see. Hidden columns are a screen setting and never reach
    // here: a file that silently lost columns is worse than a wide one.
    //
    // The Evaluation summary's catatan drill-down is a row filter by the
    // same reasoning, and is exported the same way - it rides in as
    // "catatan" (the screen's hr_catatan, carried into this link as
    // "catatan" the way hr_q is carried in as "q"). Leaving it out here
    // would mean clicking a summary count, seeing only its rows on screen,
    // then exporting the whole period anyway - merging back the two catatan
    // populations (Aktivitas Admin and Aset Belum Diklasifikasi) that this
    // filter exists to keep apart, in a file an auditor may forward as
    // evidence of exactly the wrong thing.
    $opts = [
        'sort'    => (string) ($_GET['sort'] ?? ''),
        'dir'     => (string) ($_GET['dir'] ?? 'desc'),
        'q'       => (string) ($_GET['q'] ?? ''),
        // ANDed with the catatan, the same way the screen does it: the
        // summary groups on priority AND catatan, so an export keyed on the
        // catatan alone could hand back MORE rows than the screen showed.
        'catatan'  => (string) ($_GET['catatan'] ?? ''),
        'priority' => (string) ($_GET['priority'] ?? ''),
        // The High-risk by category card's drill-down, exported the same
        // way: without this, clicking a category then Excel hands back a
        // wider set than the screen - the defect v3.7.0 fixed for the
        // catatan, recurring in this same code path.
        'kategori' => (string) ($_GET['kategori'] ?? ''),
        // v4.12.0: Session Sharing drill-down and "shared sessions only".
        'shared'   => !empty($_GET['shared']),
        'session'  => (string) ($_GET['session'] ?? ''),
    ];
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
