# Piece 4 (v1.12.0) — Deployment & Verification Checklist

**Plugin:** integrasipaman — PAM Command Audit Piece 4 (reporting page + CSV export)
**Target:** glpi-server, /data/www/glpi
**Artifact:** glpi/plugins/integrasipamanv1.12.0.zip (tests/ excluded)
**No schema change** — Piece 4 adds no table, cron, menu, or right. A file copy + php-fpm restart is the whole deploy.

## 0. Deploy
- [ ] Confirm the version bump was approved and applied (setup.php → 1.12.0) and the zip rebuilt.
- [ ] Back up: `sudo cp -a /data/www/glpi/plugins/integrasipaman /data/www/glpi/plugins/integrasipaman.bak-v1.11.0`
- [ ] Extract the zip over `/data/www/glpi/plugins/`; `sudo chown -R nginx:nginx …/integrasipaman`
- [ ] `grep VERSION /data/www/glpi/plugins/integrasipaman/setup.php` → 1.12.0
- [ ] Restart php-fpm (OPcache).
- [ ] Plugins page: integrasipaman still Enabled, no "requires update" (no schema change), no new error in files/_log/php-errors.log.

## 1. Tab + period picker
- [ ] Tools → JumpServer Integration → the page now shows two tabs: **PBI Insights** (default, unchanged) and **Command Audit Report**.
- [ ] Open **Command Audit Report**. The month dropdown lists the months that have commands, newest selected. KPI strip + five summary cards + the high-risk card render.
- [ ] KPI numbers reconcile with raw SQL for that month:
  ```sql
  SELECT COUNT(*), SUM(e.is_high_risk),
         COUNT(DISTINCT c.jms_user), COUNT(DISTINCT c.asset)
  FROM glpi_plugin_jumpserver_commands c
  LEFT JOIN glpi_plugin_jumpserver_command_eval e ON e.commands_id = c.id
  WHERE c.executed_at >= '<first>' AND c.executed_at < '<first-of-next>';
  ```
- [ ] Pick a different month → every table + KPI updates. Set a custom from/to → it overrides the month. Set to < from → falls back to the default month (no error).

## 2. Summary tables
- [ ] User / Asset / Category / Top Commands / Evaluation summary each match a hand-written GROUP BY for the period. Category + Evaluation summary are high-risk-only; User/Asset/Top are all-commands.
- [ ] Top Commands shows at most 100 rows.

## 3. High-risk table
- [ ] Pager appears when > 100 rows; Next/Previous work; "Page X of Y (N rows)" is correct.
- [ ] Clicking Timestamp / User / Asset / Priority / Kategori headers sorts; arrow flips on re-click; paging resets to 1.
- [ ] UserStatus shows ADMIN/USER; IsProduction shows True/False; long commands truncated on screen.

## 4. Exports
- [ ] Each summary card's CSV downloads `<YYYYMMDD_YYYYMMDD>_<REPORT>.csv`, comma-delimited, opens clean in Excel (BOM), row count matches the on-screen table.
- [ ] Excel (.xls) opens in Excel; Print opens a print-ready tab and "Save as PDF" works.
- [ ] High-risk CSV/XLS/Print cover the **whole period**, not just the visible page.
- [ ] "Full command log → Download CSV" streams every command for the month; row count == `SELECT COUNT(*) … WHERE executed_at in period`; a big month (e.g. the ~170k one) completes without a 502/timeout.
- [ ] `reportexport.php?report=command_detail&format=xls` → HTTP 400 (CSV-only).
- [ ] `reportexport.php?report=bogus&format=csv` and `…&format=bogus` → HTTP 400 plain text.
- [ ] A command with `=`/`+`/`-`/`@` at the start (craft one via a session if needed, or just trust the unit test) is prefixed with `'` in the CSV.

## 5. Regression
- [ ] **PBI Insights tab** renders exactly as before (sessions / commands / login / sharing tables from the PBI DB).
- [ ] **front/export.php** (config page → "View Activity Log" → CSV / Excel / Print) still works in all three formats — it now shares the exporter.
- [ ] `evaluate_commands` / `pull_terminal_activity` crons unaffected.

## Sign-off
- [ ] All sections pass → Piece 4 verified. Update docs/PROJECT-LOG.md + memory. Merge the mirror PR. `rm` the backup dir.
