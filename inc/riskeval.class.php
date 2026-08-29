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

class PluginIntegrasipamanRiskEval extends CommonGLPI
{
    private const COMMANDS_TABLE = 'glpi_plugin_jumpserver_commands';
    private const EVAL_TABLE      = 'glpi_plugin_jumpserver_command_eval';

    /**
     * Bumped by hand ONLY when CATEGORY_MAP below changes. It is an input
     * to rulesHash(), so a deploy that changes the map forces a full
     * re-evaluation on the next evaluate_commands run.
     */
    private const CATEGORY_MAP_VERSION = 1;

    /**
     * Command-substring -> category. Order is significant (first category
     * with a match wins); anything unmatched is "General". Verbatim from
     * jumpserver_monthly_audit.py get_command_category().
     */
    private const CATEGORY_MAP = [
        'Account Management'   => ['passwd', 'useradd', 'userdel', 'usermod', 'groupadd', 'groupdel', 'gpasswd', 'chage'],
        'Privilege Escalation' => ['sudo', 'su ', 'visudo'],
        'Service Change'       => ['systemctl', 'service', 'iisreset', 'sc stop', 'sc config'],
        'Network Security'     => ['iptables', 'firewall-cmd', 'route', 'ip route'],
        'Destructive'          => ['rm -rf', 'mkfs', 'fdisk', 'parted', 'dd if=', 'format', 'diskpart', 'del /f', 'remove-item'],
    ];

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

    /** Category of an already-lowercased command; "General" if nothing matches. */
    public static function category(string $cmd_lower): string
    {
        foreach (self::CATEGORY_MAP as $name => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($cmd_lower, $kw)) {
                    return $name;
                }
            }
        }
        return 'General';
    }

    /**
     * @param array<int, string> $prefixes
     */
    public static function isProduction(string $ip, array $prefixes): bool
    {
        if ($ip === '') {
            return false;
        }
        foreach ($prefixes as $prefix) {
            if ($prefix !== '' && str_starts_with($ip, str_replace('xx', '', $prefix))) {
                return true;
            }
        }
        return false;
    }

    public static function isHighRisk(int $risk_level, string $keyword_hit): bool
    {
        return $risk_level >= 4 || $keyword_hit !== '';
    }

    /**
     * @return array{0: string, 1: string} [priority, catatan]
     */
    public static function priorityNote(bool $high_risk, bool $is_prod, bool $is_admin): array
    {
        if (!$high_risk) {
            return ['', ''];
        }
        if (!$is_prod) {
            return ['LOW', 'Aset Non-Production'];
        }
        if ($is_admin) {
            return ['MEDIUM', 'Aktivitas Admin'];
        }
        return ['HIGH', 'Butuh Tindak Lanjut'];
    }

    /**
     * The three rule lists, parsed from config. Keywords are lowercased
     * here so matchKeyword() gets a ready list.
     *
     * @return array{keywords: array<int,string>, admins: array<int,string>, prefixes: array<int,string>}
     */
    public static function loadRules(): array
    {
        $cfg = Config::getConfigurationValues(PluginIntegrasipamanConfig::CONFIG_CONTEXT);

        return [
            'keywords' => array_map('mb_strtolower', self::parseList((string) ($cfg['jumpserver_risk_keywords'] ?? ''), true)),
            'admins'   => self::parseList((string) ($cfg['jumpserver_admin_users'] ?? '')),
            'prefixes' => self::parseList((string) ($cfg['jumpserver_production_ip_prefixes'] ?? '')),
        ];
    }

    /**
     * 40-char sha1 of every input that can change a classification: the
     * three lists plus CATEGORY_MAP_VERSION. Rows whose stored rules_hash
     * differs from this are re-evaluated.
     *
     * @param array{keywords: array, admins: array, prefixes: array} $rules
     */
    public static function rulesHash(array $rules): string
    {
        return sha1(
            implode("\n", $rules['keywords']) . "\0"
          . implode("\n", $rules['admins'])   . "\0"
          . implode("\n", $rules['prefixes']) . "\0"
          . self::CATEGORY_MAP_VERSION
        );
    }

    /**
     * Full eval-table column set for one command row.
     *
     * @param array{id: int|string, risk_level: int|string, command: ?string, asset: ?string, jms_user: ?string} $cmd
     * @param array{keywords: array, admins: array, prefixes: array}                                              $rules
     * @return array<string, mixed>
     */
    public static function evaluateRow(array $cmd, array $rules, string $hash): array
    {
        $cmd_lower   = mb_strtolower((string) ($cmd['command'] ?? ''));
        $keyword_hit = self::matchKeyword($cmd_lower, $rules['keywords']);
        $risk_level  = (int) ($cmd['risk_level'] ?? 0);
        $high_risk   = self::isHighRisk($risk_level, $keyword_hit);

        $ip       = self::extractIp((string) ($cmd['asset'] ?? ''));
        $is_prod  = self::isProduction($ip, $rules['prefixes']);
        $is_admin = in_array((string) ($cmd['jms_user'] ?? ''), $rules['admins'], true);

        [$priority, $catatan] = self::priorityNote($high_risk, $is_prod, $is_admin);

        return [
            'commands_id'         => (int) ($cmd['id'] ?? 0),
            'is_high_risk'        => $high_risk ? 1 : 0,
            'keyword_hit'         => mb_substr($keyword_hit, 0, 64),
            'risk_category'       => mb_substr(self::category($cmd_lower), 0, 32),
            'asset_ip'            => mb_substr($ip, 0, 45),
            'asset_is_production' => $is_prod ? 1 : 0,
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
        global $DB;

        $rules = self::loadRules();
        $hash  = self::rulesHash($rules);
        $evaluated = 0;
        $deadline  = microtime(true) + $max_seconds;

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
                ],
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
            'WHERE'     => ['OR' => [['e.id' => null], ['NOT' => ['e.rules_hash' => $hash]]]],
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
