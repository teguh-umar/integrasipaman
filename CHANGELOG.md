# Changelog

All notable changes to the JumpServer Integration Dashboard plugin (`integrasipaman`) for GLPI 11 are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Author: ICT.

This changelog starts at 4.11.0. For earlier releases, see the release's deploy checklist in the source repository
(`docs/vX.Y.Z-deploy-checklist.md`) and its git tag.

## [4.15.2] — 2026-11-11

### Changed
- **README brought up to date.** It said 4.11.1 and pointed at an old 3.4.2 manual. It now names the current version and the in-plugin Manual (`docs/manual.md`, 4.15.0), and describes the shared-session notes and the `JoinedBy` / `SharedBy` columns (4.12.0, 4.13.0), the provisioning existence check, OS-family platforms and "needs mapping" (4.13.1, 4.14.0), and the Manual page (4.15.0). No code change.

## [4.15.1] — 2026-11-10

### Changed
- **Manual updated to 4.15.0.** `docs/manual.md` is copied again from the suite's `docs/manual/integrasipaman.md`, which Copilot brought from 4.5.0 to 4.15.0. The scrubbed text names no hosts, IPs or people. Final: the §4.6 row "Platforms by OS family" had 4 cells in a 3-column table, so its explanation was dropped; fixed at the source and copied again.
- **Lag warning ignores the patch level.** The Manual page warns only when the manual documents an older minor release (4.15.0 is current for any 4.15.x). The version line is also read when it is bold (`**4.15.0**`), as the new header writes it.

## [4.15.0] — 2026-11-09

### Added
- **User manual inside the plugin** (suite rule, 2026-11-09). A **Manual** entry in the plugin menu and a **Manual** button on the Setup page open `front/manual.php`. It renders `docs/manual.md` (copied at build from the suite's `docs/manual/integrasipaman.md`) with GLPI's league/commonmark, with raw HTML escaped and unsafe links dropped. The page shows the manual's version and warns when it documents an older release than the one installed. A missing file shows a notice.

## [4.14.0] — 2026-11-09

### Added
- **Platform by OS family.** New per-connection setting in Setup > Asset provisioning: one line per platform, `Platform: keyword, keyword`. When an OS has no exact platform mapping, the first line with a keyword the OS name contains (case-insensitive) decides, and the platform is looked up by name in PAMAN. The exact mapping still wins; a rule naming a platform PAMAN does not have refuses the Send with that reason. Default: `Linux: linux, ubuntu, debian, centos, red hat, rocky, alma, openeuler, oracle` and `Windows: windows`. Kubernetes/SKE are left out on purpose: in PAMAN they are Cloud assets, and provisioning only creates Hosts.

- **Map first, then send.** An Eligible Computer whose node or platform cannot be resolved reads `Eligible - needs mapping` on its tab and in the queue; its Send is disabled and both places link to the Node and Platform mapping pages. Display only: the stored status does not change.
- **The Node and Platform mapping pages have an Add button.** GLPI draws its `+ Add` from the menu entry, and these pages sit under Asset provisioning, which declares none; no page linked to the add forms, so a mapping could not be created from the UI.

### Fixed
- **The queue and Send also know an asset is already in PAMAN.** An Eligible row made before 4.13.1 stayed in the queue with a live checkbox until the next scan, although its tab already said "already in PAMAN". The queue now shows it as `Already in PAMAN` with no checkbox, and a first Send of a host PAMAN already holds is refused before any mapping or network step. It used to adopt the host and PATCH node/platform onto it.
- **The OS is read from `glpi_items_operatingsystems`.** The plugin read `operatingsystems_id` off the Computer, a column GLPI no longer has, so the per-OS platform mapping never matched and the message read `OS is not mapped` with no name. A Computer with no OS now says so. A provisioned row whose platform now resolves differently from what was sent turns Drift.

## [4.13.1] — 2026-11-07

### Fixed
- **Provisioning no longer offers Send for an asset that already exists in PAMAN.** The queue only knew about hosts this plugin had sent itself, so a host created in PAMAN by hand showed as Eligible with a Send button. Existence is now decided by identity (address, or name, case-insensitive) against the host list `entitlement_pull` already stores for the connection, independent of the type/OS mapping. Such a Computer is not queued, an unsent Eligible row is dropped on the next refresh, and the tab says the asset is already present.

## [4.13.0] — 2026-11-04

A HIGH row (non-admin, production, high-risk) now says how the user reached production. Under the access policy, a
non-admin reaches production only by joining a session someone else shared.

### Changed
- **Catatan of a HIGH row** is one of two notes, and both texts can be set per connection in Command risk evaluation.
  "Butuh Tindak Lanjut" is no longer produced.
  - **`Shared session`**: the command was typed by someone joined to *another user's* shared session, while the
    join was live.
  - **`Akses Langsung Non-Admin`**: every other HIGH row, including the session owner's own commands. This should
    not happen under the access policy. A row means a wrong grant, a user not known as an admin, or a join not
    pulled yet.
- Other notes do not change.

### Added
- New joins send the HIGH commands of their session back through evaluation, so a join pulled after its commands
  still takes effect.

### Notes
- Both notes are part of the rules hash. The upgrade re-evaluates every stored command once, in chunks, through the
  `evaluate_commands` cron.
- The evaluator reads every join, whoever triggers it. A manual re-evaluation by a sub-entity user cannot drop the
  note.

## [4.12.0] — 2026-11-04

Non-admins reach production only by joining a session someone else shared. JumpServer files each command under
whoever **typed** it (VM-checked: in Alice Example's session, the commands of Bob Example read
`Bob Example(bob.example)`), so the command row never said that the session was someone else's. This release
lays the session-join records over the commands to show both who was joined and who shared the session.

### Added
- **High-risk detail: `JoinedBy` column** (the last column, so saved hidden-column choices keep their place).
  It names who was joined to the shared session when each command ran. It is also included in the CSV, Excel and
  Print exports.
- **High-risk detail: `SharedBy` column** (after `JoinedBy`). It shows the owner of a shared session, who gave
  the share. It is empty for sessions nobody joined.
- **High-risk detail: "Sessions that were shared" filter.** It keeps every command of a session someone joined,
  including commands run before and after the join.
- **Session Sharing > Who joined: `High-risk` column.** It shows the number of high-risk commands run in the
  session while the join lasted. The number links to High-risk detail, filtered to that session. The Who joined
  CSV gains a `High-risk commands` column.

### Changed
- **Schema:** `glpi_plugin_jumpserver_logs.session_jms_id` (indexed) is added and backfilled from the stored
  JSON during the upgrade. The cron fills it for new share and join rows.

### Notes
- Refused joins never count as present.
- A join with no `date_left` ends at the session's `date_end`. Production had no finished join without a
  `date_left` (checked 2026-11-04).
- Times are compared on one clock: join times carry their own offset and are converted into GLPI's timezone,
  so the JumpServer server offset does not matter.

## [4.11.1] — 2026-11-03

A patch release on 4.11.0, with no schema change. It was verified on the production VM before release.

### Verification

Upgraded from 4.11.0, then ran `entitlement_pull`:
- Map rows pointing at a Computer id that no longer exists in GLPI went from 44 ids / 166 rows to 0 / 0.
- The rows that had been expected to remain belonged to an asset that a live Computer had been correlated to
  since the last measurement. That Computer now holds the same rows, so no access was lost.
- On the Asset sub-tab, the duplicates and the Groups card are gone.
- The Setup > Plugins wrench opens the suite settings.

### Fixed
- **Access is no longer counted twice on the Asset sub-tab.** When a Computer was deleted from GLPI and
  recreated, both its old id and its new id held rows for the same JumpServer asset.
  - After a successful refresh of a Computer, the cron now removes rows for that same asset when they belong
    to a Computer id that no longer exists in `glpi_computers`.
  - Not removed: a Computer in the trash, two live Computers that share an asset, or an asset with no live
    successor. That last one still reads "asset no longer in GLPI".

### Changed
- The Setup > Plugins wrench opens `front/pgnsuite.settings.php?section=integrasipaman`, like every other
  suite plugin. `front/config.form.php` still saves the form, and the Tools ICT menu entry still points at it.

### Removed
- The Groups card under the Asset sub-tab. The Group sub-tab has listed the same groups since 4.11.0. The only
  thing it does not repeat is the per-group "differs" badge; the member pop-up shows the actual members.

## [4.11.0] — 2026-11-02

A minor release. **Schema change:** four new tables and three new columns, created by `plugin:install --force`.
It was verified on the production VM: `entitlement_pull` takes about 7 minutes 15 seconds.

### Added
- **User access is three sub-tabs.** The menu entry lands on User.
  - **User**: one row per person. Columns: groups, assets, role, accounts, and number of assets. Group and
    asset chips open a pop-up with the full list.
  - **Group**: one row per group. Click the member count for the member list.
  - **Asset**: the previous per-Computer table, unchanged, with its CSV.

  User and Group are GLPI searches, with sorting, filters and export.
- New tables: `glpi_plugin_jumpserver_jmsusers`, `glpi_plugin_jumpserver_ruleassets`,
  `glpi_plugin_jumpserver_accessusers` and `glpi_plugin_jumpserver_accessgroups`.
- New columns: `protocols` on `permrules` and on `hosts`, and `comment` on `usergroups`.
- `front/groupmembers.php?id=<id>` returns one group's member list for the pop-up. It answers 403 for a
  group outside the viewer's entities.
- Until the first cron run after the upgrade, the User and Group sub-tabs say the report is not built yet,
  instead of showing an empty table.

### Changed
- `entitlement_pull` adds three steps to each run:
  - pull every JumpServer user with their system role;
  - ask which rules reach each JumpServer host;
  - rebuild the User and Group rows.

  A failure in one of these steps is logged and does not stop the run. The per-Computer refresh and the
  return value of the run are unchanged.
- Rule-to-host links are pruned only when the host list arrived complete. A failed later page no longer
  prunes them.
- Text from the server is cut to the column width before it is stored.
