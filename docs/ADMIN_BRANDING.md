# Installation branding and existing wiki storage

Talk AI and EDUC AI use the same release package and the same technical app ID,
`educai`. Branding and Catalogue integration are independent installation settings.
Changing the name does not enable Catalogue or start indexing.

## OCC commands

Run as the web-server user from your Nextcloud directory (adjust the user/path for
your installation):

```sh
sudo -u www-data php occ educai:branding:show
sudo -u www-data php occ educai:branding:set --name="EDUC AI"
sudo -u www-data php occ educai:branding:reset
```

`set` accepts a plain-text display name of 1–64 bytes. `reset` restores the display
name **Talk AI**, not storage or Catalogue defaults. These commands do not edit
package files, install a second app, or change any user's bot name/mention.

On the next page load the navigation, app interface, personal/admin settings
sections and Smart Picker show the configured name. The existing app-managed Talk
registration is renamed in place; its ID, enabled state, room activations,
features and webhook secret are preserved. If updating Talk fails, the command
reports that the UI setting was saved but the Talk change is outstanding, and
returns a nonzero status. Resolve the reported problem and rerun the command.
When no managed registration exists, a future registration uses the saved name.

The Nextcloud application management/package metadata remains **Talk AI** and the
app ID remains **educai**. This is expected. Branding persists in Nextcloud app
configuration through subsequent app upgrades.

## Upgrade and wiki preservation

The 2.41.0 migration pins the display name and wiki root separately, while the old
installed version is still available:

- Fresh and public installations default to **Talk AI** for both values.
- The known internal **2.40.0.1** distribution keeps **EDUC AI** as its name and
  default wiki root.
- Already configured display names and wiki roots are retained.

The display-name commands never move, rename, delete or rewrite wiki folders.
Resetting an upgraded EDUC installation to the Talk AI display name still leaves
its wiki storage under `EDUC AI/`.

Older private versions did not have a reliable distribution marker. For personal
bots without an explicit path, the app checks for that bot's existing default
folder under both `Talk AI/Personal Wikis/` and `EDUC AI/Personal Wikis/` and reuses
it. When both exist, the persisted default wiki root takes precedence. An explicit
per-bot path can select the other folder; neither folder is merged or removed.
Explicit paths under either historical root remain valid. Collective-backed wikis
are unaffected.

For an older private installation with ambiguous duplicate folders, review the
affected bot's wiki-path setting before writing new wiki content. Set the desired
display name with OCC if it cannot be inferred from the version. Do not change
the installation's `wiki_root_folder` configuration merely to change branding;
it is a storage setting, not a presentation setting.
