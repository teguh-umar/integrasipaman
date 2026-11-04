# JumpServer Integration Dashboard (`integrasipaman`)

A [GLPI](https://glpi-project.org/) 11 plugin that connects GLPI to one or more
[Fit2Cloud JumpServer](https://github.com/jumpserver/jumpserver) PAM instances through their
REST API, pulls audit data on a schedule, and adds risk evaluation, correlation to GLPI assets
and users, access reporting and human-gated asset provisioning on top of it.

Current version: **4.11.1**. Changes are listed in `CHANGELOG.md` (from 4.11.0). A user manual in Indonesian, written for 3.4.2, ships as
`docs/MANUAL.id.md` in the published repository; it predates everything in 4.x.

> The seed values for the risk-evaluation config — the admin user list and the production IP
> prefixes — are configuration, not data this plugin stands behind. Set your own from the
> plugin's configuration page before reading any verdict it produces.

## What it does

- **Scheduled audit pull** — signs requests with a JumpServer Access Key (HTTP Signature /
  HMAC-SHA256) and pulls, page by page: operate logs, login logs (failures only), session
  sharings, session joins, terminal commands and terminal sessions. Stored in plugin tables,
  deduplicated per connection, with a retention window and a first-run backfill.
- **Several connections, separated per entity** — a JumpServer connection is a record owned by a
  GLPI entity (optionally with child entities and additional entities). Every stored row carries
  its connection and entity; every list, count, card, export and tab shows only the entities of
  the signed-in user's session. Automatic actions and the CLI are never restricted, and one
  unreachable JumpServer does not stop the others.
- **Command risk evaluation** — classifies every pulled command: high-risk keyword or JumpServer
  risk level, category, whether the target is a production asset, whether the actor is a known
  admin, and a resulting priority and note. Rules are editable lists; changing a list
  re-evaluates the history.
- **Asset and user correlation** — resolves each JumpServer asset to a production verdict (core
  IP networks, "IP Addressing" plugin ranges, or a matched GLPI `Computer`) and each JumpServer
  actor to an admin verdict (GLPI group membership), with per-row manual override.
- **User access reporting** (4.9.0) — which people can reach which assets, and how. A row is one
  **grant path**: a rule naming a person, or a rule naming a group. The same group reaching one
  asset through two rules is two rows, because that is what an audit has to see. Group membership
  is pulled and stored, so access granted through a group is counted rather than missed — on the
  reference instance that is 1450 of 4002 paths. A granting group that is not in the snapshot
  reads *unknown*, never `0`; before the pull has ever run the page says membership has never
  been collected. An asset GLPI no longer holds is labelled as such with its id (4.10.0) rather
  than dropped: the rule was never revoked, so the access is still real, and it is named from the
  bastion's own host list because that is the only place the name still exists.
  From 4.11.0 the page has three sub-tabs: **User** (one row per person: groups, assets, role,
  accounts), **Group** (one row per group, member list on click) and **Asset** (the grant-path
  table above). 4.11.1 stops a deleted-and-recreated Computer from counting the same access twice.
- **Asset provisioning (PAMAN)** — Computers eligible for the bastion wait in a queue; sending,
  re-sending and removing are manual actions behind their own right, and removal needs an
  approved ticket. Nothing is pushed on save.
- **Entitlements** — a weekly pull of JumpServer asset permission rules and user-group
  membership, shown per Computer as "Who has access", including machines nobody has used yet.
- **Reports and pages** (one menu entry in the shared PGN Suite sidebar sector):
  - *PBI Insights* — an optional view on a separate read-only reporting database
  - *Command Audit Report* — period-scoped, with KPI strip, summary tables, sortable high-risk
    detail and CSV / Excel / print export
  - *User access* (4.9.0) — the grant-path report above, filterable by asset, person or group,
    paged, with CSV export of the whole filtered result rather than the page
  - *Login failures* — every refusal reason with the accounts behind it, accounts with repeated
    failures, lockouts (an account that *is* locked, not the "will be locked" warning on a wrong
    password), filters and CSV
  - *Session* (4.6.0, 4.7.0) — two tabs. *Log Session* is a real GLPI list built on the core
    Search engine, so its columns, filters and sort behave like any other GLPI list.
    *Session Sharing* shows who shared a session, with whom (or "anyone with the link and code"),
    which session, until when, and whether it is still open
  - *Asset & user correlation*, *Asset provisioning*, *Setup*
- **Dashboard cards** — All Events, Events by Type (pie and bar), Login Failures, Login Lockouts,
  Session Sharing and Recent Events. The big-number cards show the change against the previous
  period and link to the page behind them. *All Events* counts every event in the window, so the
  other tiles are subsets of it, not separate shares of it — the card says so.
- **Secrets** — the Access Key Secret and the reporting-database password are stored encrypted
  (GLPI core `secured_fields` / `secured_configs`, context `plugin:integrasipaman`) and never
  printed back into a page.

## Rights

Three rights, on **Administration → Profiles → JumpServer Integration**:

| Right | Levels | Grants |
|---|---|---|
| `plugin_integrasipaman_config` | READ / UPDATE | the settings form, mapping lists; UPDATE: saving, *Re-evaluate now*, the one-shot backfills, correlation, editing a mapping |
| `plugin_integrasipaman_report` | READ | PBI Insights, Command Audit Report, User access, Login failures, Session, activity log and exports, dashboard cards, the *JumpServer access* tabs on Computer and User |
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
3. Log out and in again — the sidebar is built at login.
4. Open **Setup → ICT Tools** (the suite label is editable; the plugin's *Configure* icon in
   Setup → Plugins opens the same section from 4.11.1) and add a connection in the
   plugin's section. From 4.10.0 the shared suite settings are their own entry in the Setup
   sidebar; before that they were a tab on Setup → General.
5. Make sure GLPI's automatic actions run, ideally from a system cron calling `front/cron.php`.

Take a database backup before upgrading from 3.x: 4.0.0 alters unique keys on seven tables and
re-decides the entity of stored records.

Some releases ship a **one-shot backfill** under *Setup*, for history imported before the
feature existed — session joins, and session type and login source. They are safe to re-run and
report what they could not fill: JumpServer rotates its own session history away.

## Automatic actions

| Task | Frequency | Purpose |
|---|---|---|
| `pull_historical_logs` | hourly | operate / login-failure / session-sharing logs, per connection |
| `pull_terminal_activity` | daily | terminal commands and sessions, plus the retention prune |
| `evaluate_commands` | every 15 min | correlation resolver and risk classification |
| `provisioning_scan` | daily | refresh the provisioning queue (eligibility, drift, pending removal); never contacts the bastion |
| `entitlement_pull` | weekly | JumpServer asset permission rules, user-group membership and the host-name list, matched to Computers |

## Layout

```
setup.php  hook.php        plugin metadata, init hooks, install / uninstall
inc/                       one class per concern (connection, cron, terminalpull, riskeval,
                           correlation, provisioning, entitlement, accessreport, report,
                           loginfailures, session, sessionlog, sessionsharing, useraccess,
                           dashboard, menu, profile, ...)
front/                     controllers: config.form, pbi (Insights + Report), useraccess,
                           loginfailures, session, correlation, provisioning, mapping lists,
                           one-shot backfills, exports
lib/pgnsuite/              files shared byte-for-byte with the other PGN suite plugins; a
                           divergent copy would silently win for the whole suite
tests/                     offline suites, run each with a bare PHP CLI
```

Run the suites with `php tests/<name>_test.php`; each exits non-zero on a failure, so check the
exit status rather than grepping the output — a fatal error prints no `FAIL`.

## License

GPL-3.0-or-later. The full text ships as `LICENSE` in the published repository.
