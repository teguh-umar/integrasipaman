# JumpServer Integration Dashboard (`integrasipaman`)

A [GLPI](https://glpi-project.org/) 11 plugin that connects GLPI to a
[Fit2Cloud JumpServer](https://github.com/jumpserver/jumpserver) PAM instance
through its REST API, pulls audit data on a schedule, and adds risk
evaluation and reporting on top of it.

> **History note:** this repository's history begins at **v1.7.3**. Earlier
> development (v1.4.1–v1.7.0) happened in an internal monorepo and is not
> included. The seed values for the risk-evaluation config (admin user list,
> production IP prefixes) are illustrative placeholders — set your own from
> the plugin's configuration page.

## What it does

- **Scheduled audit pull** — signs requests with a JumpServer Access Key
  (HTTP Signature / HMAC-SHA256) and pulls, page by page:
  - operate logs, login logs, session sharings (historical activity)
  - terminal commands and terminal sessions
  - Stored in dedicated plugin tables, deduplicated, with a configurable
    retention window and a first-run backfill.
- **Command risk evaluation** — classifies every pulled command:
  high-risk keyword / JumpServer risk level, command category, whether the
  target is a production asset, whether the actor is a known admin, and a
  resulting priority + note. Rules live in editable config lists; changing a
  list re-evaluates all history automatically (hash-based staleness).
- **Asset & user correlation** — resolves each JumpServer asset to a
  production verdict (against selected core IPNetworks or "IP Addressing"
  plugin ranges, or a matched GLPI `Computer`) and each JumpServer actor to
  an admin verdict (GLPI group membership), with per-row manual override.
  The editable prefix / admin-user lists remain the fallback.
- **One-way asset sync** — GLPI is the source of truth; each GLPI `Computer`
  create/update is pushed to JumpServer.
- **PBI Insights page** — an optional reporting view backed by a separate
  read-only reporting database.

## Requirements

- GLPI `>= 10.0.0`, `<= 11.99.99` (developed against GLPI 11)
- PHP with the cURL extension
- A JumpServer Access Key (ID + Secret) with API access, and the target
  organization ID

## Install

1. Copy the `integrasipaman/` directory into your GLPI `plugins/` folder
   (the repository root maps directly to `plugins/integrasipaman/`).
2. In GLPI: **Setup → Plugins → JumpServer Integration Dashboard → Install**,
   then **Enable**.
3. Open the plugin's **Configure** link and fill in:
   - JumpServer URL / IP
   - Access Key ID + Secret, Organization ID *(all mandatory)*
   - Command Storage ID (for the terminal-command pull)
   - retention / backfill months
   - the risk-evaluation lists (keywords, admin users, production IP prefixes)
4. Make sure GLPI's automatic actions (cron) actually run — ideally a real
   system cron calling `front/cron.php`, not just page-load triggering.

## Cron tasks

| Task | Default frequency | Purpose |
|------|-------------------|---------|
| `pull_historical_logs` | hourly | operate / login / session-sharing logs |
| `pull_terminal_activity` | daily | terminal commands + sessions, plus retention prune |
| `evaluate_commands` | every 15 min | risk classification of pulled commands |

## Layout

```
setup.php            plugin metadata + init hooks
hook.php             install / uninstall (table DDL, cron registration)
inc/
  config.class.php       configuration store + form
  cron.class.php         historical-log pull
  jmsapi.class.php       JumpServer REST transport (request signing, pagination)
  terminalpull.class.php terminal command/session pull + retention
  riskeval.class.php     command risk classification engine
  correlation.class.php  asset/user -> production/admin resolver
  assetmap.class.php     asset -> production mapping itemtype
  usermap.class.php      user -> admin mapping itemtype
  assetsync.class.php    GLPI Computer -> JumpServer sync
  dashboard.class.php    dashboard cards + Tools-menu entry
  pbiconnection.class.php read-only reporting-DB connection
front/
  config.form.php    configuration page
  backfill.php        manual month backfill trigger
  reeval.php          manual "re-evaluate commands now" trigger
  correlation.php    correlation dashboard + "re-resolve now" trigger
  assetmap.php / assetmap.form.php   asset-mapping list + edit
  usermap.php / usermap.form.php     user-mapping list + edit
  export.php          activity-log view
  pbi.php             PBI Insights page
tests/               offline stub tests (run with a bare PHP CLI)
```

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
