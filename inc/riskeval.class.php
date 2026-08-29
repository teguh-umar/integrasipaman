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
     * Split a config textarea into a clean list: one entry per line,
     * trimmed, blank lines and "#" comments dropped. Not lowercased.
     *
     * @return array<int, string>
     */
    public static function parseList(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] !== '#') {
                $out[] = $line;
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
            if ($prefix !== '' && str_starts_with($ip, $prefix)) {
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
            'keywords' => array_map('mb_strtolower', self::parseList((string) ($cfg['jumpserver_risk_keywords'] ?? ''))),
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
            'commands_id'         => (int) $cmd['id'],
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
}
