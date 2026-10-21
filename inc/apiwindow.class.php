<?php

/**
 * -------------------------------------------------------------------------
 * JumpServer Integration Dashboard plugin for GLPI
 * -------------------------------------------------------------------------
 * Client-side date-window enforcement for JumpServer list endpoints.
 *
 * Why this exists: of every endpoint this plugin pulls, only
 * /api/v1/terminal/commands/ documents date_from/date_to (checked against the
 * JumpServer API Docs swagger.yaml, 2026-09-21). /terminal/sessions/ and
 * /audits/operate-logs/ document only `days`/`days__lt` (a numeric "last N
 * days" window), and /audits/login-logs/ documents no date filter at all.
 * Django REST Framework silently ignores query parameters that are not in a
 * view's filterset, and this plugin's own code already records one endpoint
 * where that was confirmed live (session-sharings). So a date_from we send to
 * those endpoints may simply do nothing, and the "one month" pull would
 * quietly be a full-history pull that dedup then hides.
 *
 * Rather than guess `days` semantics (guessing wrong breaks a pull that
 * currently works), the pull keeps sending date_from/date_to - free if
 * honoured - and this class guarantees the window regardless:
 *
 *   1. every entry is re-checked against [from, to) here, so the imported set
 *      is correct whether or not the server filtered;
 *   2. once the stream has demonstrably run past the start of the window, the
 *      caller can stop paging early, which is what keeps the cost sane when
 *      the server did ignore the filter.
 *
 * Step 2 is only safe while the results really are newest-first. `order` is
 * itself just another query parameter DRF may ignore, so this class does not
 * trust it: it watches the timestamps and enables the early stop only after
 * observing an actual decrease. On ascending or unordered data it never stops
 * early and the pull degrades to "slow but correct".
 *
 * PROBE RESULT (live, paman.example.com, 2026-09-22) - the guesses above are now
 * measured, and both answers matter:
 *
 *   - `order` IS honoured on all three pulled endpoints, so step 2 really does
 *     engage and the hourly pull is incremental (0-2 second runs). Keep the
 *     distrust anyway: it costs nothing, and it is the only thing standing
 *     between a future JumpServer change and silent row loss.
 *   - `days` / `days__lt` return **HTTP 500** on /audits/operate-logs/ and
 *     /terminal/sessions/, with and without `order`. The one date filter those
 *     endpoints document does not work at all. Nothing in this plugin may send
 *     it; re-probe before anyone reopens that idea.
 * -------------------------------------------------------------------------
 */

if (!defined('GLPI_ROOT')) {
    die('Sorry. You can not access this file directly');
}

class PluginIntegrasipamanApiWindow
{
    private ?int $from_ts;
    private ?int $to_ts;

    /** Timestamp of the previously seen entry, for the ordering check. */
    private ?int $prev_ts = null;

    /** True once any entry was newer than the one before it. */
    private bool $ascent_seen = false;

    /** Number of strict decreases seen - evidence the stream is newest-first. */
    private int $descents = 0;

    /** True once an entry older than the window start has been seen. */
    private bool $past_start = false;

    private int $kept = 0;
    private int $dropped = 0;
    private int $undated = 0;

    /**
     * @param string      $field Entry key holding the timestamp
     *                           (operate-logs/login-logs: 'datetime',
     *                           sessions: 'date_start').
     * @param string|null $from  Inclusive lower bound, any strtotime()-able
     *                           string; null = open (no lower bound, and no
     *                           early stop is ever possible).
     * @param string|null $to    Exclusive upper bound, or null for open-ended.
     */
    public function __construct(private string $field, ?string $from, ?string $to)
    {
        $this->from_ts = self::toTs($from);
        $this->to_ts   = self::toTs($to);
    }

    /**
     * Filter one page down to the entries inside the window, updating the
     * ordering evidence and the past-start flag as it goes.
     *
     * Entries whose timestamp is missing or unparseable are **kept**, not
     * dropped: this is an audit trail, and importing a few rows that may sit
     * outside the window is far less harmful than silently discarding
     * evidence because of an unexpected date format. They take no part in the
     * ordering check either, so one odd row cannot trigger an early stop.
     *
     * @param array<int, mixed> $entries One page of decoded API entries.
     * @return array<int, array<string, mixed>> The entries inside the window.
     */
    public function filter(array $entries): array
    {
        $out = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $ts = self::toTs(isset($entry[$this->field]) ? (string) $entry[$this->field] : null);

            if ($ts === null) {
                $this->undated++;
                $this->kept++;
                $out[] = $entry;
                continue;
            }

            if ($this->prev_ts !== null) {
                if ($ts > $this->prev_ts) {
                    $this->ascent_seen = true;
                } elseif ($ts < $this->prev_ts) {
                    $this->descents++;
                }
            }
            $this->prev_ts = $ts;

            if ($this->from_ts !== null && $ts < $this->from_ts) {
                $this->past_start = true;
                $this->dropped++;
                continue;
            }

            if ($this->to_ts !== null && $ts >= $this->to_ts) {
                $this->dropped++;
                continue;
            }

            $this->kept++;
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Whether paging can stop now without losing rows.
     *
     * Requires all three: a lower bound to stop at, an entry already seen
     * below it, and positive evidence the stream is newest-first (at least
     * one strict decrease and never an increase). Without that evidence the
     * answer stays false and the caller pages to the end.
     */
    public function canStop(): bool
    {
        return $this->past_start
            && $this->from_ts !== null
            && $this->descents > 0
            && !$this->ascent_seen;
    }

    /**
     * True when the server clearly did NOT apply the date filter - i.e. it
     * handed back rows outside the window we asked for. Worth logging: it is
     * the difference between "the pull is scoped server-side" and "we are
     * downloading history every run and throwing most of it away".
     */
    public function serverFilterIgnored(): bool
    {
        return $this->dropped > 0;
    }

    /** @return array{kept:int,dropped:int,undated:int,ordered_desc:bool} */
    public function stats(): array
    {
        return [
            'kept'         => $this->kept,
            'dropped'      => $this->dropped,
            'undated'      => $this->undated,
            'ordered_desc' => $this->descents > 0 && !$this->ascent_seen,
        ];
    }

    /** strtotime() that maps '' / null / unparseable to null rather than false. */
    private static function toTs(?string $value): ?int
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);

        return $ts === false ? null : $ts;
    }

    /**
     * A JumpServer timestamp as a MySQL datetime in GLPI's own timezone, or
     * null when the value is missing or not a time at all.
     *
     * Two formats arrive from the same server: ISO 8601, and
     * "2026/10/16 23:35:45 +0800" (session-sharings, session-join-records).
     * Both carry an explicit offset, and that offset matters: this JumpServer
     * reports **+0800** while GLPI runs Asia/Jakarta (+0700). strtotime()
     * resolves the offset to an absolute instant and date() renders it in
     * GLPI's timezone, so the hour is converted rather than copied. Writing
     * the raw string into a TIMESTAMP column would instead shift every event
     * an hour forward and throw anything near midnight onto the wrong day.
     *
     * Values outside what a MySQL TIMESTAMP can hold are rejected as null
     * (suite §4 rule 2e): out-of-range dates are stored as NULL rather than
     * silently clamped to 1970.
     */
    public static function sqlTime(?string $value): ?string
    {
        $ts = self::toTs($value);

        if ($ts === null || $ts < 1 || $ts > 2147483647) {
            return null;
        }

        return date('Y-m-d H:i:s', $ts);
    }
}
