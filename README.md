# JumpServer Integration Dashboard (`integrasipaman`)

A [GLPI](https://glpi-project.org/) 11 plugin that connects GLPI to one or more
[Fit2Cloud JumpServer](https://github.com/jumpserver/jumpserver) PAM instances through their
REST API, pulls audit data on a schedule, and adds risk evaluation, correlation to GLPI assets
and users, reporting, and human-gated asset provisioning on top of it.

Current version: **4.3.1**. User manual (Indonesian, written for 3.4.2): [docs/MANUAL.id.md](docs/MANUAL.id.md).

> The seed values for the risk-evaluation config (admin user list, production IP prefixes) and
> every name, host and address in the tests and the manual are illustrative placeholders. Set
> your own from the plugin's configuration page.

## What it does

- **Scheduled audit pull** - signs requests with a JumpServer Access Key (HTTP Signature /
  HMAC-SHA256) and pulls, page by page: operate logs, login logs (failures only), session
  sharings, terminal commands and terminal sessions. Stored in plugin tables, deduplicated per
  connection, with a retention window and a first-run backfill.
- **Several connections, separated per entity** (4.0.0) - a JumpServer connection is a record
  owned by a GLPI entity (optionally with child entities and additional entities). Every stored
  row carries its connection and entity; every list, count, card, export and tab shows only the
  entities of the signed-in user's session. Automatic actions and the CLI are never restricted,
  and one unreachable JumpServer does not stop the others.
- **Command risk evaluation** - classifies every pulled command: high-risk keyword or JumpServer
  risk level, category, whether the target is a production asset, whether the actor is a known
  admin, and a resulting priority and note. Rules are editable lists; changing a list
  re-evaluates the history.
- **Asset and user correlation** - resolves each JumpServer asset to a production verdict
  (core IP networks, "IP Addressing" plugin ranges, or a matched GLPI `Computer`) and each
  JumpServer actor to an admin verdict (GLPI group membership), with per-row manual override.
- **Asset provisioning (PAMAN)** - Computers eligible for the bastion wait in a queue; sending,
  re-sending and removing are manual actions behind their own right, and removal needs an
  approved ticket. Nothing is pushed on save.
- **Entitlements** - a weekly pull of JumpServer asset permission rules, shown per Computer as
  "Who has access", including machines nobody has used yet.
- **Reports and pages** (menu entry in the shared PGN Suite sidebar sector):
  - *PBI Insights* - an optional view on a separate read-only reporting database
  - *Command Audit Report* - period-scoped, with KPI strip, summary tables, sortable high-risk
    detail and CSV / Excel / print export
  - *Login failures* (4.3.0) - every refusal reason with the accounts behind it, accounts with
    repeated failures, lockouts (an account that *is* locked, not the "will be locked" warning
    on a wrong password), filters and CSV
  - *Session sharing* (4.2.0) - who shared a session, with whom (or "anyone with the link and
    code"), which session, until when, and whether it is still open
  - *Asset & user correlation*, *Asset provisioning*, *Setup*
- **Dashboard cards** - Total Events, Events by Type (pie and bar), Login Failures, Login
  Lockouts, Session Sharing and Recent Events. The big-number cards show the change against the
  previous period and link to the page behind them.
- **Secrets** - the Access Key Secret and the reporting-database password are stored encrypted
  (GLPI core `secured_fields` / `secured_configs`, context `plugin:integrasipaman`) and never
  printed back into a page.

## Rights

Three rights, on **Administration -> Profiles -> JumpServer Integration**:

| Right | Levels | Grants |
|---|---|---|
| `plugin_integrasipaman_config` | READ / UPDATE | the settings form, mapping lists; UPDATE: saving, *Re-evaluate now*, backfill, correlation, editing a mapping |
| `plugin_integrasipaman_report` | READ | PBI Insights, Command Audit Report, Login failures, Session sharing, activity log and exports, dashboard cards, the *JumpServer access* tabs on Computer and User |
| `plugin_integrasipaman_provisioning` | READ / Send / Remove | the provisioning queue; sending to, and removing from, the bastion |

Profiles that hold core `config` UPDATE are restored to full rights on every
`plugin:install --force`.

## Requirements

- GLPI `>= 10.0.0`, `<= 11.99.99` (developed against GLPI 11)
- PHP with the cURL extension
- A JumpServer Access Key (ID + Secret) with API access, the organization ID, and the command
  storage ID for the terminal pull

## Install and upgrade

1. Copy this directory to `plugins/integrasipaman/` in your GLPI.
2. Use the command line (the web Upgrade button fails on some 11.0.x releases):

   ```bash
   php bin/console plugin:install integrasipaman --force
   php bin/console plugin:activate integrasipaman
   php bin/console cache:clear
   ```
3. Log out and in again (the sidebar is built at login), open **Setup -> Plugins ->
   JumpServer Integration Dashboard -> Configure** and add a connection.
4. Make sure GLPI's automatic actions run, ideally from a system cron calling `front/cron.php`.

Take a database backup before upgrading from 3.x: 4.0.0 alters unique keys on seven tables and
re-decides the entity of stored records.

## Automatic actions

| Task | Frequency | Purpose |
|---|---|---|
| `pull_historical_logs` | hourly | operate / login-failure / session-sharing logs, per connection |
| `pull_terminal_activity` | daily | terminal commands and sessions, plus the retention prune |
| `evaluate_commands` | every 15 min | correlation resolver and risk classification |
| `provisioning_scan` | daily | refresh the provisioning queue (eligibility, drift, pending removal); never contacts the bastion |
| `entitlement_pull` | weekly | JumpServer asset permission rules, matched to Computers |

## Layout

```
setup.php  hook.php        plugin metadata, init hooks, install / uninstall
inc/                       one class per concern (connection, cron, terminalpull, riskeval,
                           correlation, provisioning, entitlement, report, loginfailures,
                           sessionsharing, dashboard, menu, profile, ...)
front/                     controllers: config.form, pbi (Insights + Report), loginfailures,
                           sessionsharing, correlation, provisioning, mapping lists, exports
lib/pgnsuite/              files shared byte-for-byte with the other PGN suite plugins
tests/                     offline suites, run each with a bare PHP CLI
docs/                      the Indonesian manual and earlier deployment checklists
```

Run the suites with `php tests/<name>_test.php`; each exits non-zero on a failure.

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).
