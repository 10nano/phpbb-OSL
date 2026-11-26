# OSL – Online Shooting League

**Project version: 0.6.0-dev** (development release, prior to the first public release).

A phpBB 3.3.x extension (`tennano/osl`) that manages shooting leagues within forum topics. Each league supports multiple independent disciplines, with separate result submissions and monthly standings.

## Fresh installation (XAMPP)

This **main-candidate** build is intended for a clean installation only. Its single, consolidated phpBB migration replaces the previous series of development migrations. **Do not replace the files of an existing OSL installation with this build without a separate, tested data-migration procedure.** Back up the phpBB database before removing any earlier installation.

1. Make sure the target phpBB installation does not contain old OSL tables or active OSL migration records. Removing extension files alone does not remove its database records.
2. Copy `tennano/osl/` from the installation archive to `C:\xampp\htdocs\<your-forum-directory>\ext\tennano\osl\`, keeping the directory structure. Alternatively, extract `tennano/` directly under the forum's `ext/` directory.
3. In phpBB ACP open **Customise → Manage extensions** and enable **Online Shooting League**.
4. Purge phpBB's cache (ACP → **General → Purge the cache**).

The initial migration creates the complete current OSL schema. Existing development installations with data are **not** automatically upgraded or migrated by this build. Use an isolated test phpBB database for verification.

## Creating a league

- When creating a new topic, open the **Shooting League** tab, enable league creation, and enter the title, **start month and year** (YYYY-MM), duration, and number of best starts to count. Each round runs from the first through the last day of its calendar month, including February and leap years. The database stores the first day of the selected month in the existing `start_date` field; no schema migration is needed.
- In **Disciplines**, rename the initial discipline (for example, `Rifle results`) and configure its scoring fields, each with its own permitted range. For paper targets, enable **Count inner tens (10X)** on an individual series. The shot count is **always derived** from **maximum series points / 10**; there is no manual shot-count field. To enable 10X, the maximum must therefore be a positive multiple of 10 (for example, 400 points means 40 shots and permits 0–40 inner tens).
- Each scoring series uses **whole-number points by default**. Select **Decimals** between its maximum score and the 10X option to allow scores and limits in increments of **0.1** (one decimal place). Result-entry arrows then increase or decrease the score by 0.1 instead of 1. This option cannot be changed after results have been recorded for the discipline.
- Use **+ Add discipline** to create additional disciplines (for example, `Pistol results`) with independent scoring fields.
- Select **Show an optional Group / Club column in standings** to add a club/group field to result submissions and a Group column before Nickname in each standings table. If disabled, both the field and column remain hidden. The group is not an additional discipline.
- Write the league rules in the body of the first post.

## Submitting and editing results

- When replying to a league topic, open the **Match Results** tab. Each discipline has a separate checkbox, **its own date**, and its own scoring fields. When the Group column is enabled, one optional **Group / Club** text field appears above the disciplines and is shared by the participant across the league.
- Select and complete only the disciplines for which you want to submit results. Unselected disciplines are not saved. If a series has 10X enabled, enter its independent integer 10X count (0 through the automatically calculated number of shots) immediately after its score. It does not add extra points.
- You can submit results for another discipline in a later reply, even several days later.
- Each participant may have only **one result per discipline and round (the league's monthly period)**, regardless of group or post.
- The submitted club/group name is associated with the participant in that league and is automatically filled in on subsequent submissions. It is optional (up to 100 characters) and can be changed or cleared in a later submission or while editing an existing one. Changes also update the participant's earlier results in the same league without splitting their standings entry.
- Editing a reply allows saved scores to be corrected. Deselecting a discipline while editing removes **only that discipline's result from the edited post**.
- The first post displays a separate standings table for each discipline. Where 10X is enabled, a **Total 10X** column shows the number of inner tens from the best counted starts, and each monthly round has separate **PTS** and **10X** subcolumns. Months without a result show a dash in both cells; a submitted round with zero inner tens shows `0`. Points determine ranking first, then 10X breaks ties ahead of the former criteria (total from all starts, average, and highest single result). When equal scores compete for the last counted start, the start with more 10X is selected. Replies containing results show the submitted results for all selected disciplines.
- After a discipline has recorded results, its scoring fields cannot be modified and the discipline cannot be deleted. Its name may still be corrected. League dates and the Group column visibility setting are also locked once results exist. Participants can still update their club/group names.
- A result submission may be sent without typing any additional message text; a written comment remains optional.

## Database structure

A **single consolidated migration** (`migrations/v10x/m1_initial_schema.php`) creates four tables with the phpBB table prefix:

- `osl_leagues`: league configuration, calendar rounds, extension days, group visibility, and reply instructions (including their enabled state);
- `osl_competitions`: discipline names, display order, and JSON scoring-field definitions;
- `osl_results`: result submission, post, participant, shooting date, score values and totals, per-series 10X counts (`x_scores_data`) and total 10X (`total_x`);
- `osl_forum_creators`: initially empty per-forum league-creation assignments.

The migration creates all unique and supporting indexes, including uniqueness constraints `(post_id, competition_id)` and `(league_id, competition_id, user_id, round_index)`. It **does not** add the obsolete global `u_create_shooting_league` permission.

The optional Group feature uses `osl_leagues.groups_enabled` (JSON-encoded Boolean) and `osl_results.shooter_group`; standings are never separated by group. This consolidated migration is for **fresh installs**, not upgrades from previously deployed OSL migrations.

## Forum-specific league creation permissions

**No user may create a league by default after installation**, including administrators and moderators. An administrator may grant or revoke permissions in any forum; a moderator may do so only in forums they moderate. Both may also grant permission to themselves.

1. Open a forum, for example **Silhouette** (`viewforum.php?f=ID`).
2. Select **Manage league creators** next to the forum name. This link is visible only to administrators and moderators authorized for that forum.
3. Enter an existing username (for example, `jurek`) and click **Grant access**. You may enter your own username.
4. That user may then create OSL league topics **only in that forum**, provided they also hold the standard phpBB permission `f_post` to create topics.
5. You may revoke the assignment from the same page at any time.

The initial migration creates an empty forum-specific permissions table. No global league-creation permission or administrative/moderator bypass is installed.

**Upgrading older OSL installations is not supported by this candidate.** If you have results to retain, do not overwrite or delete them: prepare and test a dedicated migration first.

## Group / Club verification checklist

1. Create a league with two disciplines and enable the optional Group column. Confirm that the Group column appears before Nickname in the standings.
2. Submit a result without a group name, then another result in a different discipline. The group field should remain empty and the participant's points must not be split into multiple standings entries.
3. Enter `Club A` on a later submission, save it, and reopen the form. The form and all discipline standings should now display `Club A`.
4. Change the group to `Club B` in a subsequent submission or edit, then clear it. All earlier results for the participant should reflect the latest value.
5. Create a separate league with the Group column disabled. Neither the form field nor the standings column should appear.

## Post-installation verification checklist

1. Create a league with rifle and pistol disciplines with different maximum scores.
2. Submit a reply with only the rifle result; only the rifle standings should gain a new result.
3. Submit another reply with a pistol result for the same month; it should be accepted.
4. Try submitting a second rifle result for the same month, even with a different group; the duplicate should be rejected.
5. In the next month, submit both disciplines in one reply using different dates.
6. Edit a post with two results, deselect one discipline, and verify that the other result remains. The removed discipline should become available for a new submission.
7. Check the standings order, best-round totals, overall totals, averages, number of starts, and tied ranks.
8. Test a series with 10X (including zero, the maximum count, and an out-of-range count), two shooters tied on points but different on 10X, then another discipline with 10X disabled. Confirm that points are not doubled and that an existing result still edits correctly.
9. Verify that league creation is unavailable to all users until a forum-specific grant has been made.

## Testing notes

Before release, run PHP syntax checks and the complete integration checklist on phpBB/XAMPP, including a fresh installation and data deletion/uninstallation test of the single migration.

## License

GPL-2.0-only. See `license.txt` for the full license text.


### Extended submission window

League organizers can set **Extension days** (0–7, default 0) when creating a league.
During those calendar days after a round ends, a shooter may choose
**Results for previous month** for each discipline individually. This checkbox
is only displayed while the extension period is active; it disappears afterwards
and reappears during the next round's extension period. The submitted shooting
date must belong to the selected round. One result per shooter, discipline
and round is enforced regardless of when it is submitted. Existing results
can be edited without opening new late submissions. The setting is locked
after results have been recorded. The initial schema defines `extension_days` with a default of 0.


### Exporting complete league standings

The first post of a league provides **PDF** and **CSV** download links beside
the league title. Each download contains all competitions:

- PDF: landscape A3 pages with repeated ranking columns and horizontal month splits;
  supports Polish characters when the server provides Arial or DejaVu Sans.
- CSV: UTF-8 with BOM, semicolon-separated sections for each competition.

Exports use the same ranking service as the on-screen tables and require read
permission to the parent forum. No database migration is needed.
