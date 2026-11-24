# OSL – Online Shooting League

**Project version: 0.5.0-dev** (development release, prior to the first public release).

A phpBB 3.3.x extension (`tennano/osl`) that manages shooting leagues within forum topics. Each league supports multiple independent disciplines, with separate result submissions and monthly standings.

## Installation and updates (XAMPP)

1. If OSL is already installed and running, **do not delete its data or database tables**. Back up the extension directory and database before replacing any files.
2. Close any open forum editing sessions and back up `ext/tennano/osl`.
3. Copy `tennano/osl/` from the installation archive into `C:\xampp\htdocs\<your-forum-directory>\ext\tennano\osl\`, preserving the directory structure and overwriting the existing files as needed. Alternatively, extract `tennano/` directly into the forum's `ext/` directory.
4. For a fresh installation, open the phpBB Administration Control Panel (ACP), go to **Customise → Manage extensions**, and enable **Online Shooting League**. If updating an enabled installation, follow the update procedure under *Forum-specific league creation permissions* below when migrations are pending.
5. Clear phpBB's cache in the ACP (**General → Purge the cache**). If an old compiled template prevents access to the ACP, clear the generated phpBB cache files manually, preserving `index.htm` and `.htaccess`.

**Note:** The calendar-month update changes round boundaries. If you have test leagues with results recorded under the old mid-month boundaries, back up your data and recreate those test leagues before checking new standings; the old results retain their original `round_index` values and are not automatically reassigned. No database tables or records are deleted by this update. The optional Group column can be enabled when creating a new league; this setting is locked for existing leagues once results have been recorded.

## Creating a league

- When creating a new topic, open the **Shooting League** tab, enable league creation, and enter the title, **start month and year** (YYYY-MM), duration, and number of best starts to count. Each round runs from the first through the last day of its calendar month, including February and leap years. The database stores the first day of the selected month in the existing `start_date` field; no schema migration is needed.
- In **Disciplines**, rename the initial discipline (for example, `Rifle results`) and configure its scoring fields, each with its own permitted range. For paper targets, enable **Count inner tens (10X)** on an individual series. The shot count is **always derived** from **maximum series points / 10**; there is no manual shot-count field. To enable 10X, the maximum must therefore be a positive multiple of 10 (for example, 400 points means 40 shots and permits 0–40 inner tens).
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

The initial migration (`migrations/v10x/m1_initial_schema.php`) creates three tables with the phpBB table prefix:

- `osl_leagues`: league configuration and round parameters;
- `osl_competitions`: discipline names, display order, and scoring field definitions;
- `osl_results`: per-discipline result, post, date, scores, and round.

The second migration (`migrations/v10x/m2_forum_creators.php`) adds:

- `osl_forum_creators`: forum-specific permissions to create league topics.

The third migration (`migrations/v10x/m3_inner_tens.php`) extends `osl_results` with `x_scores_data` (per-series 10X counts) and `total_x` (per-start sum), leaving existing scores intact and defaulting old 10X counts to zero.

Unique indexes on `osl_results` enforce `(post_id, competition_id)` and `(league_id, competition_id, user_id, round_index)`.

The optional group feature uses the existing `osl_leagues.groups_enabled` column (a JSON-encoded Boolean for new leagues) and `osl_results.shooter_group`. Older leagues with a non-empty list of predefined groups are interpreted as having the Group column enabled. Rankings are never split by group.

## Forum-specific league creation permissions

**No user may create a league by default after installation**, including administrators and moderators. An administrator may grant or revoke permissions in any forum; a moderator may do so only in forums they moderate. Both may also grant permission to themselves.

1. Open a forum, for example **Silhouette** (`viewforum.php?f=ID`).
2. Select **Manage league creators** next to the forum name. This link is visible only to administrators and moderators authorized for that forum.
3. Enter an existing username (for example, `jurek`) and click **Grant access**. You may enter your own username.
4. That user may then create OSL league topics **only in that forum**, provided they also hold the standard phpBB permission `f_post` to create topics.
5. You may revoke the assignment from the same page at any time.

The `m2_forum_creators` migration creates an initially empty assignments table and removes the obsolete global `u_create_shooting_league` permission, which previously granted league creation rights to administrators and global moderators. The current code contains no administrative or moderator bypass for forum-specific assignments. Existing leagues and results are not changed.

**Updating from an earlier OSL version:** Back up the database and extension directory. Disable OSL in the ACP **without deleting its data**, replace the files, then re-enable the extension so phpBB runs any pending migration. Finally, purge phpBB's cache. Do not edit the previously applied `m1_initial_schema.php` migration or delete league data.

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

The PHP files have passed syntax checks; full integration testing of this updated build on phpBB/XAMPP remains necessary. For a new forum installation, perform the integration checks above, including a fresh-install test of all three migrations.

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
after results have been recorded. Migration `m4_extension_days` preserves
existing data and defaults old leagues to 0 extra days.


### Exporting complete league standings

The first post of a league provides **PDF** and **CSV** download links beside
the league title. Each download contains all competitions:

- PDF: landscape A3 pages with repeated ranking columns and horizontal month splits;
  supports Polish characters when the server provides Arial or DejaVu Sans.
- CSV: UTF-8 with BOM, semicolon-separated sections for each competition.

Exports use the same ranking service as the on-screen tables and require read
permission to the parent forum. No database migration is needed.


## Optional test clock (test-clock branch only)

The OSL test clock is disabled by default. In ACP, go to **Extensions → OSL → OSL test clock** to enable or disable time simulation and to select a test date and time (Europe/Warsaw). The settings are stored in this forum's phpBB configuration, not in a tracked configuration file.

The simulated time applies only to the submission-window logic in OSL (form display, result submission validation, and the initial default league-year field), and only if the HTTP request originates from `127.0.0.1` or `::1`. All other requests use the actual server time, even when the ACP switch is enabled. The simulator does not alter phpBB post timestamps, the Windows clock, or any other extension. It does not affect export timestamps.

**Install/upgrade:** A new, data-preserving migration registers the ACP module and disabled-by-default settings. Use ACP → Customise → Manage extensions to disable OSL (do **not** delete its data), copy the updated extension files, enable OSL again, and purge phpBB's cache. Installation requires an administrator with the `a_board` permission. Keep this feature in the `test-clock` Git branch, not `main`.

**Warning:** Submissions made during testing still create real records in the local database. Use a separate test forum and database. The clock is intentionally limited to direct localhost requests, and should stay disabled on any publicly accessible installation.
