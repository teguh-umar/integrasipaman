# Changelog

All notable changes to the JumpServer Integration Dashboard plugin (`integrasipaman`) for GLPI 11 are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Author: ICT.

This changelog starts at 4.11.0. For earlier releases, see the release's deploy checklist in the source repository
(`docs/vX.Y.Z-deploy-checklist.md`) and its git tag.

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
