<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Risk classification for glpi_plugin_jumpserver_commands rows, written
 * into the 1:1 glpi_plugin_jumpserver_command_eval table. Logic ported
 * verbatim from jumpserver_monthly_audit.py (RISK_KEYWORDS,
 * get_command_category, the high-risk rule, is_production_asset, and the
 * Priority/Catatan table). Driven by the evaluate_commands CronTask
 * (every 15 min) and by front/reeval.php. See
 * docs/superpowers/specs/2026-08-29-pam-command-audit-piece2-risk-eval-design.md.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

require_once __DIR__ . '/config.class.php';
require_once __DIR__ . '/rulesettings.class.php';

class PluginIntegrasipamanRiskEval extends CommonGLPI
{
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const EVAL_TABLE      = 'glpi_plugin_jumpserver_command_eval';

    /**
     * Split a config textarea into a clean list: one entry per line. Leading
     * whitespace is always stripped; blank lines and "#" comment lines are
     * dropped (tested against a fully trimmed copy). Not lowercased.
     *
     * When $keep_trailing_space is set, a single significant trailing space
     * (a non-space character followed by exactly one space at end of line)
     * is preserved on the stored value. This is needed by the "su " keyword,
     * which keeps its trailing space so it only matches a real `su `
     * invocation and not every command containing the letters "su". Default
     * ($keep_trailing_space = false) fully trims every line, which is the
     * faithful behaviour for jumpserver_admin_users /
     * jumpserver_production_ip_prefixes (the Python script reads those with
     * .strip()).
     *
     * @return array<int, string>
     */
    public static function parseList(string $raw, bool $keep_trailing_space = false): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $probe = trim($line);
            if ($probe === '' || $probe[0] === '#') {
                continue;
            }
            // Leading whitespace is never meaningful here. A single trailing
            // space is: it is what keeps the "su " keyword from matching every
            // command containing the letters "su". Preserve exactly that one
            // significant trailing space when asked; otherwise full trim.
            if ($keep_trailing_space && preg_match('/\S $/', $line)) {
                $out[] = $probe . ' ';
            } else {
                $out[] = $probe;
            }
        }
        return $out;
    }

    /**
     * The bucket for a command no category matched.
     *
     * "General" read like a decision somebody had made. It is not: it is
     * the absence of a rule. Naming it as work makes the list of
     * uncategorised commands something an operator can finish.
     */
    public const UNCATEGORISED = 'Belum Dikategorikan';

    /**
     * Parse the category textarea into an ordered name => keywords map.
     * Line order is match order, so the array's insertion order matters.
     *
     * Format: one category per line, `Nama: kata1, kata2`.
     *
     * Keywords are trimmed the same way parseList() trims a line: leading
     * whitespace is never meaningful, but one significant trailing space is
     * preserved when the raw keyword ends with exactly one space after a
     * non-space character; otherwise it is fully trimmed. `su ` carries
     * that space in both the seeded keyword list and Privilege Escalation;
     * trimmed to `su` it matches sudo, sudoers, issue and resume, and the
     * category silently becomes a catch-all in which every command reads
     * as a privilege event.
     *
     * @return array<string, list<string>>
     */
    public static function parseCategories(string $raw): array
    {
        $out = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            // Left-trim only here: a right-trim would erase a keyword's
            // significant trailing space before it ever reaches the keyword
            // loop below, for any line where that keyword is the last thing
            // on the line (the common case: one category, one line).
            $left = ltrim($line);
            if ($left === '' || $left[0] === '#') {
                continue;
            }

            // Split on the FIRST colon only: a keyword may legitimately
            // contain one of its own - commands do, constantly (C:\ paths,
            // `scp host:/path`, `rsync host:path`, any URL) - and splitting
            // on the last colon would chop a keyword in half. A category
            // NAME does not need a colon; an operator who wants one writes
            // "Backup - nightly" instead, which costs nothing.
            //
            // When $left has no colon, strpos() returns false, and
            // substr($left, 0, false) coerces that false length to 0,
            // giving "" - which the empty-name check right below already
            // drops. A separate "$at === false" guard would be dead code:
            // there is no input it catches that the empty-name check does
            // not also catch, since every no-colon line takes this same
            // path to an empty name.
            $at = strpos($left, ':');

            $name = trim(substr($left, 0, $at));
            if ($name === '' || isset($out[$name])) {
                // A duplicate keeps its first occurrence: two lines competing
                // would leave the loser looking configured but never matching.
                continue;
            }

            $keywords = [];
            foreach (explode(',', substr($left, $at + 1)) as $raw_kw) {
                $trimmed = trim($raw_kw);
                if ($trimmed === '') {
                    continue;
                }
                $kw = preg_match('/\S $/', $raw_kw) ? $trimmed . ' ' : $trimmed;
                $keywords[] = mb_strtolower($kw);
            }

            if ($keywords === []) {
                continue;
            }

            $out[$name] = $keywords;
        }

        return $out;
    }

    /**
     * First category (in file order) whose keyword appears in the
     * already-lowercased command, or UNCATEGORISED.
     *
     * @param array<string, list<string>> $categories
     */
    public static function categoryFor(string $cmd_lower, array $categories): string
    {
        foreach ($categories as $name => $keywords) {
            foreach ($keywords as $kw) {
                if ($kw !== '' && str_contains($cmd_lower, $kw)) {
                    return (string) $name;
                }
            }
        }

        return self::UNCATEGORISED;
    }

    /** First IPv4 dotted-quad in the asset string (e.g. "VM(10.0.0.1)"), or ''. */
    public static function extractIp(string $asset): string
    {
        return preg_match('/(\d{1,3}(?:\.\d{1,3}){3})/', $asset, $m) ? $m[1] : '';
    }

    /**
     * First keyword (in list order) that appears as a substring of the
     * already-lowercased command, or ''.
     *
     * @param array<int, string> $keywords_lower
     */
    public static function matchKeyword(string $cmd_lower, array $keywords_lower): string
    {
        foreach ($keywords_lower as $kw) {
            if ($kw !== '' && str_contains($cmd_lower, $kw)) {
                return $kw;
            }
        }
        return '';
    }

    /**
     * Address-only classification, used when an asset string has no row in
     * the correlation map yet.
     *
     * Delegates to the resolver so there is ONE tie-breaking rule in the
     * codebase. Two copies of "production wins" drift, and the drift shows
     * up as two screens disagreeing about one asset.
     *
     * @param array<int,string> $prod_prefixes
     * @param array<int,string> $dev_prefixes
     */
    public static function classFromIp(string $ip, array $prod_prefixes, array $dev_prefixes): string
    {
        return PluginIntegrasipamanCorrelation::classFor($ip, $prod_prefixes, $dev_prefixes);
    }

    public static function isHighRisk(int $risk_level, string $keyword_hit): bool
    {
        return $risk_level >= 4 || $keyword_hit !== '';
    }

    /**
     * @param string $class production | development | '' (unclassified)
     * @return array{0: string, 1: string} [priority, catatan]
     */
    public static function priorityNote(bool $high_risk, string $class, bool $is_admin): array
    {
        if (!$high_risk) {
            return ['', ''];
        }
        if ($class === 'development') {
            return ['LOW', 'Aset Development'];
        }
        // Anything that is not one of the two stated classes is unstated -
        // including a bool coerced to '1' by a call site missed during the
        // signature change. Defaulting the UNKNOWN case to the visible
        // branch is deliberate: a coercion bug then shows up as an
        // unexpected MEDIUM rather than as a silent HIGH or LOW.
        if ($class !== 'production') {
            return ['MEDIUM', 'Aset Belum Diklasifikasi'];
        }
        if ($is_admin) {
            return ['MEDIUM', 'Aktivitas Admin'];
        }

        return ['HIGH', 'Butuh Tindak Lanjut'];
    }

    /**
     * The three rule lists, parsed from config, plus the Piece 3
     * correlation maps. Keywords are lowercased here so matchKeyword()
     * gets a ready list.
     *
     * @return array{keywords: array<int,string>, admins: array<int,string>, prefixes: array<int,string>, dev_prefixes: array<int,string>, asset_class: array<string,string>, user_admin: array<string,bool>}
     */
    public static function loadRules(): array
    {
        // v4.4.0: the rules of the CURRENT connection - its own, or inherited,
        // or the instance defaults. Never the instance set directly.
        $cfg = PluginIntegrasipamanRuleSettings::current();

        return [
            'keywords'     => array_map('mb_strtolower', self::parseList((string) ($cfg['jumpserver_risk_keywords'] ?? ''), true)),
            'admins'       => self::parseList((string) ($cfg['jumpserver_admin_users'] ?? '')),
            'prefixes'     => self::parseList((string) ($cfg['jumpserver_production_ip_prefixes'] ?? '')),
            'dev_prefixes' => self::parseList((string) ($cfg['jumpserver_development_ip_prefixes'] ?? '')),
            'categories'   => self::parseCategories((string) ($cfg['jumpserver_command_categories'] ?? '')),
            // Piece 3: GLPI-native resolution (asset -> class,
            // user -> admin). evaluateRow() reads these first and falls
            // back to admins/prefixes above only when a string has no map
            // row yet. A read failure (e.g. the map tables not yet created
            // on a deploy where the version was not bumped) degrades to an
            // empty map -> exact Piece 2 behaviour via the fallback lists.
            'asset_class'  => self::safeCorrelationMap('assetClassMap'),
            'user_admin'   => self::safeCorrelationMap('userAdminMap'),
        ];
    }

    /**
     * @return array<string,bool>|array<string,string>
     */
    private static function safeCorrelationMap(string $method): array
    {
        try {
            /** @var array<string,bool>|array<string,string> $map */
            $map = PluginIntegrasipamanCorrelation::$method();
            return $map;
        } catch (\Throwable $e) {
            trigger_error(
                sprintf('riskeval: correlation %s() failed (%s); using the fallback lists only', $method, $e->getMessage()),
                E_USER_WARNING
            );
            return [];
        }
    }

    /**
     * 40-char sha1 of every input that can change a classification: the
     * three lists, the Piece 3 correlation-maps generation counter (bumped
     * only when a resolve actually changes an asset_class / is_admin
     * verdict, or an admin override does - so a plain new asset string
     * does NOT trigger a full re-evaluation), plus the parsed category
     * list. Rows whose stored rules_hash differs from this are re-evaluated.
     *
     * @param array{keywords: array, admins: array, prefixes: array, dev_prefixes: array, categories: array<string, list<string>>} $rules
     */
    public static function rulesHash(array $rules): string
    {
        $generation = 0;
        try {
            $generation = PluginIntegrasipamanCorrelation::mapsGeneration();
        } catch (\Throwable $e) {
            // Correlation unavailable -> generation 0; the ?? fallback in
            // evaluateRow() keeps classification correct regardless.
        }

        // The PARSED list, never the raw textarea. Hashing the raw value
        // would make tidying whitespace or adding a comment re-evaluate
        // every row in the history - a full sweep with no cause. json_encode
        // (not hand-joined "$name=$kw,$kw") because a name or keyword may
        // itself contain '=' or ',' - the seed ships "dd if=" - and an
        // unescaped join lets two different configurations serialise to the
        // same string, which would silently stop hashing a real rule change.
        // JSON_INVALID_UTF8_SUBSTITUTE because a bare json_encode() RETURNS
        // FALSE on invalid UTF-8, which concatenates as '' - two different
        // broken configurations would then hash identically, the sweep would
        // never run, and the report would keep showing categories the config
        // no longer holds. The flag leaves every valid config's hash unchanged.
        $categories = json_encode($rules['categories'] ?? [], JSON_INVALID_UTF8_SUBSTITUTE);

        return sha1(
            implode("\n", $rules['keywords']) . "\0"
          . implode("\n", $rules['admins'])   . "\0"
          . implode("\n", $rules['prefixes'])     . "\0"
          . implode("\n", $rules['dev_prefixes'] ?? []) . "\0"
          . 'gen' . $generation . "\0"
          . $categories
        );
    }

    /**
     * Full eval-table column set for one command row.
     *
     * @param array{id: int|string, risk_level: int|string, command: ?string, asset: ?string, jms_user: ?string}          $cmd
     * @param array{keywords: array, admins: array, prefixes: array, dev_prefixes: array, categories: array<string, list<string>>, asset_class: array<string,string>, user_admin: array<string,bool>} $rules
     * @return array<string, mixed>
     */
    public static function evaluateRow(array $cmd, array $rules, string $hash): array
    {
        $cmd_lower   = mb_strtolower((string) ($cmd['command'] ?? ''));
        $keyword_hit = self::matchKeyword($cmd_lower, $rules['keywords']);
        $risk_level  = (int) ($cmd['risk_level'] ?? 0);
        $high_risk   = self::isHighRisk($risk_level, $keyword_hit);

        $ip       = self::extractIp((string) ($cmd['asset'] ?? ''));
        // The correlation map wins; the prefix lists are the fallback only
        // for an asset string with no map row yet. `??` falls through on
        // absent/null ONLY - so a row explicitly resolved to '' stays
        // unclassified instead of being re-derived from the very lists that
        // produced the '' in the first place.
        $class    = $rules['asset_class'][(string) ($cmd['asset'] ?? '')]
                    ?? self::classFromIp($ip, $rules['prefixes'], $rules['dev_prefixes'] ?? []);
        $is_admin = $rules['user_admin'][(string) ($cmd['jms_user'] ?? '')]
                    ?? in_array((string) ($cmd['jms_user'] ?? ''), $rules['admins'], true);

        [$priority, $catatan] = self::priorityNote($high_risk, $class, $is_admin);

        return [
            'commands_id'         => (int) ($cmd['id'] ?? 0),
            'is_high_risk'        => $high_risk ? 1 : 0,
            'keyword_hit'         => mb_substr($keyword_hit, 0, 64),
            'risk_category'       => mb_substr(self::categoryFor($cmd_lower, $rules['categories'] ?? []), 0, 32),
            'asset_ip'            => mb_substr($ip, 0, 45),
            'asset_class'         => mb_substr($class, 0, 20),
            'user_is_admin'       => $is_admin ? 1 : 0,
            'priority'            => $priority,
            'catatan'             => $catatan,
            'rules_hash'          => $hash,
        ];
    }

    /**
     * Upsert eval rows for every command row that has no eval row yet, or
     * whose eval row was evaluated under a different rules_hash. Processes
     * $chunk rows at a time so memory is flat and a killed run resumes
     * (the WHERE re-selects whatever is still stale). Stops after roughly
     * $max_seconds of wall-clock work so one cron invocation cannot run for
     * hours on a big first backfill; a later cron run continues where this
     * one left off. Also bails out if a whole chunk writes nothing (a
     * persistent per-row error would otherwise re-select the same rows
     * forever and spin the cron lock). Returns how many rows it evaluated
     * and how many remain stale after it stopped.
     *
     * @return array{evaluated: int, stale_remaining: int}
     */
    public static function evaluateStale(int $chunk = 5000, float $max_seconds = 120.0): array
    {
        $deadline = microtime(true) + $max_seconds;

        // v4.0.0: one pass per connection. The verdict of a command depends
        // on the asset map and the user map, and those belong to the
        // connection the command came through: the same asset string on two
        // JumpServers is two different machines. The time budget is shared.
        if (PluginIntegrasipamanConnection::all() === []) {
            return self::evaluateCurrent($chunk, $deadline);
        }

        $total = ['evaluated' => 0, 'stale_remaining' => 0];
        $runs  = PluginIntegrasipamanConnection::each(static fn () => self::evaluateCurrent($chunk, $deadline));
        foreach ($runs as $run) {
            if ($run['error'] !== '') {
                trigger_error(
                    sprintf('command_eval: connection "%s" failed: %s', $run['connection']->label(), $run['error']),
                    E_USER_WARNING
                );
                continue;
            }
            $total['evaluated']       += $run['result']['evaluated'];
            $total['stale_remaining'] += $run['result']['stale_remaining'];
        }

        return $total;
    }

    /**
     * evaluateStale() for the current connection's commands - or, on an
     * instance with no connection at all, for every command.
     *
     * @return array{evaluated: int, stale_remaining: int}
     */
    private static function evaluateCurrent(int $chunk, float $deadline): array
    {
        global $DB;

        // loadRules() reads the two maps of the CURRENT connection.
        $rules = self::loadRules();
        $hash  = self::rulesHash($rules);
        $evaluated = 0;
        $mine      = PluginIntegrasipamanConnection::ownRows('c');

        do {
            // CONTINGENCY: if this GLPI query-builder LEFT JOIN + OR/NOT/null
            // shape throws at runtime, swap the two $DB->request() calls in
            // this method for the guarded raw SQL in Step 5 of task-3-brief.md
            // (same chunk/loop structure). Do not switch pre-emptively -
            // post-deploy verification decides.
            $iterator = $DB->request([
                'SELECT'     => ['c.id', 'c.risk_level', 'c.command', 'c.asset', 'c.jms_user', 'e.id AS eval_id'],
                'FROM'       => self::COMMANDS_TABLE . ' AS c',
                'LEFT JOIN'  => [
                    self::EVAL_TABLE . ' AS e' => [
                        'ON' => ['e' => 'commands_id', 'c' => 'id'],
                    ],
                ],
                'WHERE'      => [
                    'OR' => [
                        ['e.id' => null],
                        ['NOT'  => ['e.rules_hash' => $hash]],
                    ],
                ] + $mine,
                'LIMIT'      => $chunk,
            ]);

            $batch  = 0;
            $failed = 0;
            foreach ($iterator as $row) {
                // The LEFT JOIN already told us whether an eval row exists,
                // so we go straight to INSERT or UPDATE. A rules-change
                // re-eval makes every selected row an UPDATE - never
                // attempt an INSERT that is bound to hit the UNIQUE
                // commands_id and log a "Duplicate entry" SQL error.
                $has_eval = ($row['eval_id'] ?? null) !== null;
                if (!self::writeEval(self::evaluateRow($row, $rules, $hash), $has_eval)) {
                    $failed++;
                }
                $batch++;
            }
            $evaluated += ($batch - $failed);

            if ($batch === 0) {
                break;
            }
            if ($failed === $batch) {
                trigger_error(
                    'command_eval: evaluateStale wrote 0 of ' . $batch . ' rows in a chunk; aborting pass to avoid a spin loop',
                    E_USER_WARNING
                );
                break;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
        } while ($batch === $chunk);

        $stale_remaining = $DB->request([
            'COUNT'     => 'c',
            'FROM'      => self::COMMANDS_TABLE . ' AS c',
            'LEFT JOIN' => [
                self::EVAL_TABLE . ' AS e' => ['ON' => ['e' => 'commands_id', 'c' => 'id']],
            ],
            'WHERE'     => ['OR' => [['e.id' => null], ['NOT' => ['e.rules_hash' => $hash]]]] + $mine,
        ])->current()['c'] ?? 0;

        return ['evaluated' => $evaluated, 'stale_remaining' => (int) $stale_remaining];
    }

    /**
     * Write one eval row. $has_existing comes from the caller's LEFT JOIN
     * (e.id present) - when true, this is a straight UPDATE keyed by the
     * UNIQUE commands_id, with no INSERT attempt (GLPI's query builder has
     * no ON DUPLICATE KEY UPDATE, and a doomed INSERT logs a "Duplicate
     * entry" SQL error for every already-evaluated row on a rules-change
     * re-eval). When false, INSERT; a lost race against a concurrent pass
     * still falls through to UPDATE.
     *
     * @param array<string, mixed> $vals Output of evaluateRow().
     * @return bool true when the eval row is now current; false when the
     *              write failed.
     */
    private static function writeEval(array $vals, bool $has_existing): bool
    {
        global $DB;

        if (!$has_existing) {
            if ($DB->insert(self::EVAL_TABLE, $vals)) {
                return true;
            }
            if (!str_contains((string) $DB->error(), 'Duplicate entry')) {
                trigger_error(
                    sprintf('command_eval insert failed for commands_id %d: %s', $vals['commands_id'] ?? 0, $DB->error()),
                    E_USER_WARNING
                );
                return false;
            }
            // Duplicate: a concurrent pass beat us to it - fall through to UPDATE.
        }

        $id = (int) $vals['commands_id'];
        unset($vals['commands_id']);
        $vals['date_eval'] = $_SESSION['glpi_currenttime'] ?? date('Y-m-d H:i:s');
        if (!$DB->update(self::EVAL_TABLE, $vals, ['commands_id' => $id])) {
            trigger_error(
                sprintf('command_eval update failed for commands_id %d: %s', $id, $DB->error()),
                E_USER_WARNING
            );
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public static function cronInfo(string $name): array
    {
        if ($name === 'evaluate_commands') {
            return ['description' => 'Classify JumpServer command records (high-risk, category, priority) into the command-eval table'];
        }
        return [];
    }

    /**
     * CronTask 'evaluate_commands' (registered in hook.php, every 15 min,
     * MODE_EXTERNAL). Cheap no-op when nothing is stale.
     *
     * @return int always 1 (a slow rules-change re-eval is normal, not a failure)
     */
    public static function cronEvaluate_commands(CronTask $task): int
    {
        global $DB;

        $have = $DB->request(['COUNT' => 'c', 'FROM' => self::COMMANDS_TABLE])->current()['c'] ?? 0;
        if ($have == 0) {
            $task->log('No command rows to evaluate.');
            $task->setVolume(0);
            return 1;
        }

        // Piece 3: refresh the asset/user correlation maps before
        // evaluating so is_production / is_admin are current. A failure
        // here is logged, not fatal - evaluation continues on the
        // existing maps and self-corrects next pass via rules_hash.
        try {
            $c = PluginIntegrasipamanCorrelation::resolveAll();
            $task->log(sprintf(
                'Correlation: %d/%d assets resolved (%d prefix-fallback, %d manual, %d unresolved); %d/%d users resolved.',
                $c['assets']['resolved'], $c['assets']['total'], $c['assets']['prefix_fallback'],
                $c['assets']['manual'], $c['assets']['unresolved'],
                $c['users']['resolved'], $c['users']['total']
            ));
        } catch (\Throwable $e) {
            $task->log('Correlation resolve failed (evaluation continues on existing maps): ' . $e->getMessage());
        }

        $r = self::evaluateStale();

        $task->log(sprintf(
            'Evaluated %d command row(s); %d still stale (next run continues).',
            $r['evaluated'],
            $r['stale_remaining']
        ));
        $task->setVolume($r['evaluated']);

        return 1;
    }
}
