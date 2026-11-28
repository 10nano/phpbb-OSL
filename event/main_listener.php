<?php

namespace tennano\osl\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class main_listener implements EventSubscriberInterface
{
    protected $manager;
    protected $template;
    protected $request;
    protected $user;
    protected $auth;
    protected $language;
    protected $db;
    protected $leagues_table;
    protected $competitions_table;
    protected $results_table;
    protected $creator_permissions;
    protected $controller_helper;
    protected $cached_leagues = [];
    protected $cached_rounds = [];
    protected $cached_standings = [];
    protected $cached_post_results = [];

    public function __construct(
        \tennano\osl\service\league_manager $manager,
        \phpbb\template\template $template,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\auth\auth $auth,
        \phpbb\language\language $language,
        \phpbb\db\driver\driver_interface $db,
        string $leagues_table,
        string $competitions_table,
        string $results_table,
        \tennano\osl\service\creator_permissions $creator_permissions,
        \phpbb\controller\helper $controller_helper
    ) {
        $this->manager = $manager;
        $this->template = $template;
        $this->request = $request;
        $this->user = $user;
        $this->auth = $auth;
        $this->language = $language;
        $this->db = $db;
        $this->leagues_table = $leagues_table;
        $this->competitions_table = $competitions_table;
        $this->results_table = $results_table;
        $this->creator_permissions = $creator_permissions;
        $this->controller_helper = $controller_helper;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.user_setup' => 'load_language_on_setup',
            'core.viewforum_modify_page_title' => 'viewforum_add_creator_management_link',
            'core.posting_modify_template_vars' => 'posting_modify_template_vars',
            'core.posting_modify_submission_errors' => 'posting_validate_submission',
            'core.submit_post_end' => 'submit_post_save_data',
            'core.delete_posts_after' => 'delete_posts_remove_results',
            'core.delete_topics_after_query' => 'delete_topics_remove_leagues',
            'core.viewtopic_modify_post_data' => 'viewtopic_preload_league_data',
            'core.viewtopic_modify_post_row' => 'viewtopic_modify_post_row',
            'core.viewtopic_modify_post_action_conditions' => 'allow_league_author_edit',
        ];
    }

    public function load_language_on_setup($event)
    {
        $langs = $event['lang_set_ext'];
        $langs[] = ['ext_name' => 'tennano/osl', 'lang_set' => 'common'];
        $event['lang_set_ext'] = $langs;
    }

    public function viewforum_add_creator_management_link($event)
    {
        $forum_id = (int) $event['forum_id'];
        if ((int) ($event['forum_data']['forum_type'] ?? 0) === FORUM_POST
            && $this->creator_permissions->can_manage($forum_id))
        {
            $this->template->assign_vars([
                'S_OSL_CAN_MANAGE_CREATORS' => true,
                'U_OSL_MANAGE_CREATORS' => $this->controller_helper->route('tennano_osl_manage_creators', ['forum_id' => $forum_id]),
            ]);
        }
    }

    public function allow_league_author_edit($event)
    {
        $row = $event['row'];
        $league = $this->get_cached_league((int) $row['topic_id']);
        if ($league && (int) $this->user->data['user_id'] === (int) $league['league_author_id'])
        {
            $event['force_edit_allowed'] = true;
        }
    }

    protected function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Legacy leagues inherit visibility from text; edited leagues use the saved switch. */
    protected function reply_instructions_enabled(array $league): bool
    {
        $enabled = (int) ($league['reply_instructions_enabled'] ?? 2);
        return $enabled === 2
            ? trim((string) ($league['reply_instructions'] ?? '')) !== ''
            : $enabled === 1;
    }

    protected function get_cached_league(int $topic_id): ?array
    {
        if (!array_key_exists($topic_id, $this->cached_leagues))
        {
            $this->cached_leagues[$topic_id] = $this->manager->get_league_by_topic($topic_id);
        }
        return $this->cached_leagues[$topic_id];
    }

    protected function get_first_post_id(int $topic_id, array $data): int
    {
        $first = (int) ($data['topic_first_post_id'] ?? 0);
        if ($first || !$topic_id)
        {
            return $first;
        }
        $res = $this->db->sql_query('SELECT topic_first_post_id FROM ' . TOPICS_TABLE . ' WHERE topic_id = ' . $topic_id);
        $row = $this->db->sql_fetchrow($res);
        $this->db->sql_freeresult($res);
        return (int) ($row['topic_first_post_id'] ?? 0);
    }

    /** Current time for OSL submission windows only (real time by default). */
    protected function osl_now(): int
    {
        return \tennano\osl\service\test_clock::now($this->request);
    }

    protected function format_date(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
    }

    /** For late entries, accept shooting dates only in the last N days of the previous month. */
    protected function previous_round_earliest_date(array $round, int $extension_days): int
    {
        if ($extension_days <= 0)
        {
            return (int) $round['start_ts'];
        }
        $days = min(7, $extension_days);
        $end_day = (new \DateTimeImmutable('@' . (int) $round['end_ts']))
            ->setTimezone(new \DateTimeZone('Europe/Warsaw'))->setTime(0, 0, 0);
        $earliest = $end_day->modify('-' . ($days - 1) . ' days')->getTimestamp();
        return max((int) $round['start_ts'], $earliest);
    }

    /** League configuration accepts a month and year, never a day. */
    protected function parse_start_month(string $month): int
    {
        if (!preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $month))
        {
            return 0;
        }
        $zone = new \DateTimeZone('Europe/Warsaw');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01', $zone);
        return ($dt && $dt->format('Y-m') === $month && $dt->getTimestamp() > 0)
            ? $dt->getTimestamp()
            : 0;
    }

    protected function format_start_month(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m');
    }

    protected function parse_date(string $date, bool $at_noon = false): int
    {
        $zone = new \DateTimeZone('Europe/Warsaw');
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if (!$dt || $dt->format('Y-m-d') !== $date)
        {
            return 0;
        }
        return $dt->setTime($at_noon ? 12 : 0, 0)->getTimestamp();
    }

    /**
     * Always derive the 10X limit from the maximum score (10 points per shot).
     * Series using 10X must have a positive maximum divisible by ten.
     */
    protected function tenx_shot_limit(array $field): int
    {
        $calculated = (float) ($field['max'] ?? 0) / 10.0;
        if (is_finite($calculated) && $calculated >= 1 && $calculated <= 10000 && abs($calculated - round($calculated)) < 0.0000001)
        {
            return (int) round($calculated);
        }

        return 0;
    }

    /** Only whole points or tenths are allowed, according to the series configuration. */
    protected function valid_score_precision(float $value, bool $decimal_enabled): bool
    {
        if (!is_finite($value))
        {
            return false;
        }
        $units = $decimal_enabled ? 10.0 : 1.0;
        return abs($value * $units - round($value * $units)) < 0.000001;
    }

    /** A discipline needs fractional totals when any of its series uses tenths. */
    protected function competition_has_decimal_scores(array $competition): bool
    {
        foreach ($competition['fields_schema'] as $field)
        {
            if (!empty($field['decimal_enabled']))
            {
                return true;
            }
        }
        return false;
    }

    /** Historical series may contain a now-unused, manually entered shot count. */
    protected function comparable_fields_schema(array $fields): array
    {
        foreach ($fields as &$field)
        {
            unset($field['shots']);
        }
        unset($field);
        return $fields;
    }

    /** Read editable discipline definitions, preserving stable IDs on first-post edits. */
    protected function posted_competitions(): array
    {
        $names = $this->request->variable('osl_comp_name', [''], true);
        $ids = $this->request->variable('osl_comp_id', [0]);
        $field_names = $this->request->variable('osl_comp_field_name', [['']], true);
        $field_mins = $this->request->variable('osl_comp_field_min', [[0.0]]);
        $field_maxs = $this->request->variable('osl_comp_field_max', [[0.0]]);
        $field_decimal = $this->request->variable('osl_comp_field_decimal', [[0]]);
        $field_tenx = $this->request->variable('osl_comp_field_tenx', [[0]]);
        $output = [];
        foreach ($names as $slot => $name)
        {
            if (count($output) >= 20)
            {
                break;
            }
            $name = trim((string) $name);
            if ($name === '')
            {
                continue;
            }
            $fields = [];
            foreach (($field_names[$slot] ?? []) as $field_slot => $field_name)
            {
                if (count($fields) >= 20)
                {
                    break;
                }
                $field_name = trim((string) $field_name);
                if ($field_name !== '')
                {
                    $definition = [
                        'name' => $field_name,
                        'min' => (float) ($field_mins[$slot][$field_slot] ?? 0),
                        'max' => (float) ($field_maxs[$slot][$field_slot] ?? 0),
                    ];
                    // Preserve the JSON representation of existing whole-point series.
                    if (!empty($field_decimal[$slot][$field_slot]))
                    {
                        $definition['decimal_enabled'] = true;
                    }
                    if (!empty($field_tenx[$slot][$field_slot]))
                    {
                        $definition['ten_x_enabled'] = true;
                    }
                    $fields[] = $definition;
                }
            }
            $output[] = [
                'id' => (int) ($ids[$slot] ?? 0),
                'name' => $name,
                'fields_schema' => $fields,
            ];
        }
        return $output;
    }

    protected function submitted_competition_ids(): array
    {
        $raw = $this->request->variable('osl_selected', [0]);
        return array_values(array_unique(array_filter(array_map('intval', $raw), function ($id) { return $id > 0; })));
    }

    protected function result_by_post(int $post_id): array
    {
        $res = $this->db->sql_query('SELECT * FROM ' . $this->results_table . ' WHERE post_id = ' . $post_id);
        $results = [];
        while ($row = $this->db->sql_fetchrow($res))
        {
            $results[(int) $row['competition_id']] = $row;
        }
        $this->db->sql_freeresult($res);
        return $results;
    }

    /** Competitions already reported by this shooter in the currently active round. */
    protected function reported_competitions_for_round(int $league_id, int $user_id, int $round_index): array
    {
        if ($league_id <= 0 || $user_id <= 1 || $round_index <= 0)
        {
            return [];
        }

        $sql = 'SELECT competition_id FROM ' . $this->results_table . '
            WHERE league_id = ' . $league_id . '
            AND user_id = ' . $user_id . '
            AND round_index = ' . $round_index;
        $res = $this->db->sql_query($sql);
        $reported = [];
        while ($row = $this->db->sql_fetchrow($res))
        {
            $reported[(int) $row['competition_id']] = true;
        }
        $this->db->sql_freeresult($res);

        return $reported;
    }

    /** Current club/group is common to every competition in one league. */
    protected function get_shooter_group(int $league_id, int $user_id): string
    {
        if ($league_id <= 0 || $user_id <= 1)
        {
            return '';
        }
        $sql = 'SELECT shooter_group FROM ' . $this->results_table .
            ' WHERE league_id = ' . $league_id .
            ' AND user_id = ' . $user_id .
            ' ORDER BY result_id DESC';
        $res = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($res);
        $this->db->sql_freeresult($res);
        return $row ? (string) $row['shooter_group'] : '';
    }

    protected function league_has_results(int $league_id, int $competition_id = 0): bool
    {
        $where = ' WHERE league_id = ' . $league_id;
        if ($competition_id)
        {
            $where .= ' AND competition_id = ' . $competition_id;
        }
        $res = $this->db->sql_query_limit('SELECT result_id FROM ' . $this->results_table . $where, 1);
        $exists = (bool) $this->db->sql_fetchrow($res);
        $this->db->sql_freeresult($res);
        return $exists;
    }

    public function posting_modify_template_vars($event)
    {
        $mode = $event['mode'];
        $data = $event['post_data'];
        $topic_id = (int) ($event['topic_id'] ?? $data['topic_id'] ?? 0);
        $post_id = (int) ($event['post_id'] ?? $data['post_id'] ?? 0);
        $forum_id = (int) ($event['forum_id'] ?? $data['forum_id'] ?? 0);
        $first_id = $this->get_first_post_id($topic_id, $data);
        $league = $topic_id ? $this->get_cached_league($topic_id) : null;
        $is_edit_first = $mode === 'edit' && $post_id > 0 && $post_id === $first_id;
        $simulated_now = \tennano\osl\service\test_clock::simulated_time($this->request);
        if ($simulated_now !== null)
        {
            $this->template->assign_vars([
                'S_OSL_TEST_CLOCK_ACTIVE' => true,
                'OSL_TEST_CLOCK_DATETIME' => (new \DateTimeImmutable('@' . $simulated_now))
                    ->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d H:i'),
            ]);
        }

        if (($mode === 'post' || $is_edit_first) && $this->creator_permissions->can_create($forum_id))
        {
            $start = $this->request->variable('osl_start_date', $league
                ? $this->format_start_month((int) $league['start_date'])
                : $this->format_start_month($this->osl_now()));
            $this->template->assign_vars([
                'S_OSL_CAN_CREATE_LEAGUE' => true,
                'OSL_ENABLED' => $this->request->is_set_post('osl_form_league') ? $this->request->is_set_post('osl_enabled') : (bool) $league,
                // phpBB already prepares this subject for safe template output.
                // The live preview is updated from the native #subject field.
                'OSL_TITLE' => (string) ($data['post_subject'] ?? ''),
                'OSL_HAS_TOPIC_SUBJECT' => trim((string) ($data['post_subject'] ?? '')) !== '',
                'OSL_SHORT_NAME' => $this->e($this->request->variable('osl_short_name', $league['short_name'] ?? '', true)),
                'OSL_START_DATE' => $this->e($start),
                'OSL_DURATION_MONTHS' => $this->request->variable('osl_duration_months', (int) ($league['duration_months'] ?? 12)),
                'OSL_BEST_ROUNDS' => $this->request->variable('osl_best_rounds', (int) ($league['best_rounds_count'] ?? 8)),
                'OSL_EXTENSION_DAYS' => $this->request->variable('osl_extension_days', (int) ($league['extension_days'] ?? 0)),
                'OSL_GROUPS_ENABLED' => $this->request->is_set_post('osl_form_league')
                    ? $this->request->is_set_post('osl_groups_enabled')
                    : (bool) ($league['groups_enabled'] ?? false),
                'OSL_INSTRUCTIONS_ENABLED' => $this->request->is_set_post('osl_form_league')
                    ? $this->request->is_set_post('osl_instructions_enabled')
                    : ($league ? $this->reply_instructions_enabled($league) : false),
                'OSL_INSTRUCTIONS_TEXT' => $this->e($this->request->is_set_post('osl_form_league')
                    ? $this->request->untrimmed_variable('osl_reply_instructions', '', true)
                    : ($league['reply_instructions'] ?? '')),
            ]);
            $definitions = [];
            if ($this->request->is_set_post('osl_comp_name'))
            {
                $definitions = $this->posted_competitions();
            }
            elseif ($league)
            {
                foreach ($league['competitions'] as $comp)
                {
                    $definitions[] = ['id' => $comp['competition_id'], 'name' => $comp['title'], 'fields_schema' => $comp['fields_schema']];
                }
            }
            if (!$definitions)
            {
                $definitions = [['id' => 0, 'name' => $this->language->lang('OSL_DEFAULT_COMPETITION_NAME'), 'fields_schema' => [['name' => $this->language->lang('OSL_DEFAULT_FIELD_NAME'), 'min' => 0, 'max' => 40]]]];
            }
            foreach ($definitions as $slot => $comp)
            {
                $this->template->assign_block_vars('osl_competitions', [
                    'INDEX' => $slot,
                    'ID' => $comp['id'],
                    'NAME' => $this->e($comp['name']),
                ]);
                foreach ($comp['fields_schema'] as $i => $field)
                {
                    $this->template->assign_block_vars('osl_competitions.fields', [
                        'INDEX' => $i,
                        'NAME' => $this->e($field['name']),
                        'MIN' => $field['min'],
                        'MAX' => $field['max'],
                        'DECIMAL_ENABLED' => !empty($field['decimal_enabled']),
                        'TENX_ENABLED' => !empty($field['ten_x_enabled']),
                    ]);
                }
            }
        }

        $is_result_post = $mode === 'reply' || $mode === 'quote' || ($mode === 'edit' && $post_id !== $first_id);
        if (!$is_result_post || !$league)
        {
            return;
        }
        $can_edit_others = $this->auth->acl_get('m_edit', $forum_id)
            || $this->auth->acl_get('a_')
            || (int) $league['league_author_id'] === (int) $this->user->data['user_id'];
        $is_own = $mode !== 'edit' || (int) ($data['poster_id'] ?? 0) === (int) $this->user->data['user_id'];
        if (!$can_edit_others && !$is_own)
        {
            return;
        }

        // A placeholder belongs to the editor UI only: it is never put in
        // phpBB's message field or included in the submitted post contents.
        if ($mode === 'reply' && $this->reply_instructions_enabled($league)
            && trim((string) ($league['reply_instructions'] ?? '')) !== '')
        {
            $this->template->assign_vars([
                'S_OSL_REPLY_INSTRUCTIONS' => true,
                'OSL_REPLY_INSTRUCTIONS' => $this->e($league['reply_instructions']),
            ]);
        }

        $existing = $mode === 'edit' ? $this->result_by_post($post_id) : [];
        $is_submitted = $this->request->is_set_post('osl_form_results');
        $selected = $is_submitted ? $this->submitted_competition_ids() : array_keys($existing);
        $posted_dates = $this->request->variable('osl_date', ['']);
        $posted_scores = $this->request->variable('osl_score', [['']]);
        $posted_tenx = $this->request->variable('osl_tenx', [['']]);
        $shooter_id = $mode === 'edit' ? (int) ($data['poster_id'] ?? 0) : (int) $this->user->data['user_id'];

        // Current and previous-round availability is computed on the server,
        // using full calendar-month boundaries in Europe/Warsaw.
        $now = $this->osl_now();
        $today = $this->format_date($now);
        $windows = $this->manager->get_submission_periods($league, $now);
        $current_round = (int) $windows['current'];
        $previous_round = (int) $windows['previous'];
        $current_reported = $this->reported_competitions_for_round(
            (int) $league['league_id'], $shooter_id, $current_round
        );
        $previous_reported = $this->reported_competitions_for_round(
            (int) $league['league_id'], $shooter_id, $previous_round
        );
        $posted_previous = $this->request->variable('osl_previous_period', [0]);

        $group = !$league['groups_enabled'] ? '' : ($is_submitted
            ? $this->request->variable('osl_shooter_group', '', true)
            : $this->get_shooter_group((int) $league['league_id'], $shooter_id));
        $this->template->assign_vars([
            'S_OSL_CAN_SUBMIT_RESULT' => true,
            'S_OSL_HAS_GROUPS' => (bool) $league['groups_enabled'],
            'OSL_SHOOTER_GROUP' => $this->e($group),
        ]);

        // phpBB reads SHOW_PANEL from page_data after this event.
        // Default to the results tab for a new reply, but preserve any
        // explicitly selected tab when the form is submitted again.
        if ($mode === 'reply' && $this->request->variable('show_panel', '') === '')
        {
            $page_data = $event['page_data'];
            $page_data['SHOW_PANEL'] = 'osl-result-panel';
            $event['page_data'] = $page_data;
        }

        // Prefix discipline headings only in the result-entry form.
        // Competition names in storage, standings and exports stay unchanged.
        $short_league_name = trim((string) ($league['short_name'] ?? ''));
        foreach ($league['competitions'] as $comp_id => $competition)
        {
            $saved = $existing[$comp_id] ?? null;
            $scores = $saved ? (json_decode($saved['scores_data'], true) ?: []) : [];
            $tenx_scores = $saved ? (json_decode($saved['x_scores_data'] ?? '', true) ?: []) : [];
            $editing_existing = $mode === 'edit' && $saved !== null;
            $can_current = $current_round > 0 && empty($current_reported[$comp_id]);
            $can_previous = $previous_round > 0 && empty($previous_reported[$comp_id]);
            $show_previous = !$editing_existing && $previous_round > 0;
            $use_previous = $show_previous && ($is_submitted
                ? !empty($posted_previous[$comp_id])
                : (!$can_current && $can_previous));
            $may_submit = $editing_existing || $can_current || $can_previous;
            $chosen_round = $editing_existing ? (int) $saved['round_index']
                : ($use_previous ? $previous_round : $current_round);
            $chosen_bounds = $windows['rounds'][$chosen_round] ?? null;
            $default_date = $use_previous && $chosen_bounds
                ? $this->format_date((int) $chosen_bounds['end_ts'])
                : $today;
            $date = $is_submitted ? ($posted_dates[$comp_id] ?? $default_date)
                : ($saved ? $this->format_date((int) $saved['round_date']) : $default_date);
            // Keep historical result edits within their original round.
            $current_bounds = $editing_existing ? $chosen_bounds : ($windows['rounds'][$current_round] ?? null);
            $previous_bounds = $windows['rounds'][$previous_round] ?? null;
            $previous_date_min = $previous_bounds
                ? $this->format_date($this->previous_round_earliest_date($previous_bounds, (int) ($league['extension_days'] ?? 0)))
                : '';
            $this->template->assign_block_vars('osl_result_competitions', [
                'ID' => $comp_id,
                'NAME' => $this->e(($short_league_name !== '' ? $short_league_name . ' ' : '') . $competition['title']),
                'REPORTED_THIS_MONTH' => !$may_submit,
                'NO_OPEN_PERIOD' => $current_round === 0 && $previous_round === 0,
                'SELECTED' => $may_submit && in_array($comp_id, $selected, true),
                'SHOW_PREVIOUS_PERIOD' => $show_previous,
                'PREVIOUS_SELECTED' => $use_previous,
                'PREVIOUS_REPORTED' => !$can_previous,
                'CURRENT_ALLOWED' => $editing_existing || $can_current,
                'PREVIOUS_ALLOWED' => $can_previous,
                'CURRENT_START' => $current_bounds ? $this->format_date((int) $current_bounds['start_ts']) : '',
                'CURRENT_END' => $current_bounds ? $this->format_date(min((int) $current_bounds['end_ts'], $now)) : '',
                'TODAY' => $today,
                'PREVIOUS_START' => $previous_date_min,
                'PREVIOUS_END' => $previous_bounds ? $this->format_date(min((int) $previous_bounds['end_ts'], $now)) : '',
                'DATE_MIN' => $use_previous && !$editing_existing ? $previous_date_min
                    : ($chosen_bounds ? $this->format_date((int) $chosen_bounds['start_ts']) : ''),
                'DATE_MAX' => $chosen_bounds ? $this->format_date(min((int) $chosen_bounds['end_ts'], $now)) : $today,
                'DATE' => $this->e($date),
            ]);
            foreach ($competition['fields_schema'] as $index => $field)
            {
                $val = $is_submitted ? ($posted_scores[$comp_id][$index] ?? '') : ($scores[$index] ?? '');
                $tenx_enabled = !empty($field['ten_x_enabled']);
                $tenx_value = $is_submitted
                    ? ($posted_tenx[$comp_id][$index] ?? '0')
                    : ($tenx_scores[$index] ?? 0);
                $this->template->assign_block_vars('osl_result_competitions.fields', [
                    'INDEX' => $index,
                    'NAME' => $this->e($field['name']),
                    'MIN' => $field['min'],
                    'MAX' => $field['max'],
                    'VALUE' => $this->e($val),
                    'DECIMAL_ENABLED' => !empty($field['decimal_enabled']),
                    'TENX_ENABLED' => $tenx_enabled,
                    'TENX_MAX' => $this->tenx_shot_limit($field),
                    'TENX_VALUE' => $this->e($tenx_value),
                ]);
            }
        }
    }

    /** Read a whole number without silently coercing fractions or text to integers. */
    protected function posted_league_integer(string $name): int
    {
        $value = $this->request->variable($name, '', false, \phpbb\request\request_interface::POST);
        return preg_match('/\A[0-9]+\z/', $value) ? (int) $value : -1;
    }

    public function posting_validate_submission($event)
    {
        if (!$event['submit'])
        {
            return;
        }
        $errors = $event['error'];
        $mode = $event['mode'];
        $data = $event['post_data'];
        $topic_id = (int) ($event['topic_id'] ?? $data['topic_id'] ?? 0);
        $post_id = (int) ($event['post_id'] ?? $data['post_id'] ?? 0);
        $forum_id = (int) ($event['forum_id'] ?? $data['forum_id'] ?? 0);
        $first_id = $this->get_first_post_id($topic_id, $data);
        $league = $topic_id ? $this->get_cached_league($topic_id) : null;

        if (($mode === 'post' || ($mode === 'edit' && $post_id === $first_id)) && $this->request->is_set_post('osl_form_league'))
        {
            if ($league && !$this->request->is_set_post('osl_enabled'))
            {
                $errors[] = $this->language->lang('OSL_ERR_DISABLE');
            }
            if ($this->request->is_set_post('osl_enabled'))
            {
                if (!$this->creator_permissions->can_create($forum_id))
                {
                    $errors[] = $this->language->lang('OSL_ERR_PERMISSION');
                }
                $title = trim((string) ($data['post_subject'] ?? ''));
                if ($title === '')
                {
                    $errors[] = $this->language->lang('OSL_ERR_NO_TITLE');
                }
                $start = $this->parse_start_month($this->request->variable('osl_start_date', ''));
                if (!$start)
                {
                    $errors[] = $this->language->lang('OSL_ERR_DATE_INVALID');
                }
                $duration = $this->posted_league_integer('osl_duration_months');
                $best = $this->posted_league_integer('osl_best_rounds');
                $extension_days = $this->posted_league_integer('osl_extension_days');
                if ($extension_days < 0 || $extension_days > 7)
                {
                    $errors[] = $this->language->lang('OSL_ERR_EXTENSION_DAYS');
                }
                if ($duration < 1 || $duration > 24 || $best < 1 || $best > $duration)
                {
                    $errors[] = $this->language->lang('OSL_ERR_LIMITS');
                }
                $short_name = $this->request->untrimmed_variable('osl_short_name', '', true);
                if (utf8_strlen(html_entity_decode($short_name, ENT_QUOTES, 'UTF-8')) > 20)
                {
                    $errors[] = $this->language->lang('OSL_ERR_SHORT_NAME_LENGTH');
                }
                if (utf8_strlen($this->request->untrimmed_variable('osl_reply_instructions', '', true)) > 10000)
                {
                    $errors[] = $this->language->lang('OSL_ERR_REPLY_INSTRUCTIONS_LENGTH');
                }
                $definitions = $this->posted_competitions();
                if (!$definitions)
                {
                    $errors[] = $this->language->lang('OSL_ERR_NO_COMPETITIONS');
                }
                $seen_names = [];
                $seen_ids = [];
                $retained = [];
                foreach ($definitions as $comp)
                {
                    $name_key = strtolower($comp['name']);
                    if (isset($seen_names[$name_key]))
                    {
                        $errors[] = $this->language->lang('OSL_ERR_DUPLICATE_COMPETITION');
                    }
                    $seen_names[$name_key] = true;
                    if (!$comp['fields_schema'])
                    {
                        $errors[] = $this->language->lang('OSL_ERR_NO_FIELDS');
                    }
                    foreach ($comp['fields_schema'] as $field)
                    {
                        if ($field['min'] > $field['max'])
                        {
                            $errors[] = $this->language->lang('OSL_ERR_INVALID_RANGE');
                        }
                        $decimal_enabled = !empty($field['decimal_enabled']);
                        if (!$this->valid_score_precision((float) $field['min'], $decimal_enabled)
                            || !$this->valid_score_precision((float) $field['max'], $decimal_enabled))
                        {
                            $errors[] = $this->language->lang('OSL_ERR_FIELD_PRECISION', $this->e($field['name']));
                        }
                        if (!empty($field['ten_x_enabled']) && $this->tenx_shot_limit($field) < 1)
                        {
                            $errors[] = $this->language->lang('OSL_ERR_TENX_SHOTS', $this->e($field['name']));
                        }
                    }
                    if ($comp['id'])
                    {
                        if (!$league || !isset($league['competitions'][$comp['id']]) || isset($seen_ids[$comp['id']]))
                        {
                            $errors[] = $this->language->lang('OSL_ERR_INVALID_COMPETITION');
                        }
                        else
                        {
                            $current = $league['competitions'][$comp['id']];
                            if ($this->comparable_fields_schema($current['fields_schema']) != $this->comparable_fields_schema($comp['fields_schema']) && $this->league_has_results((int) $league['league_id'], $comp['id']))
                            {
                                $errors[] = $this->language->lang('OSL_ERR_COMPETITION_LOCKED');
                            }
                        }
                        $seen_ids[$comp['id']] = true;
                        $retained[$comp['id']] = true;
                    }
                }
                if ($league)
                {
                    $league_id = (int) $league['league_id'];
                    if ($this->league_has_results($league_id))
                    {
                        if ($this->format_start_month($start) !== $this->format_start_month((int) $league['start_date'])
                            || $duration !== (int) $league['duration_months'])
                        {
                            $errors[] = $this->language->lang('OSL_ERR_SCHEDULE_LOCKED');
                        }
                        $groups_enabled = $this->request->is_set_post('osl_groups_enabled');
                        if ($groups_enabled !== (bool) $league['groups_enabled'])
                        {
                            $errors[] = $this->language->lang('OSL_ERR_GROUPS_LOCKED');
                        }
                    }
                    foreach ($league['competitions'] as $id => $comp)
                    {
                        if (!isset($retained[$id]) && $this->league_has_results($league_id, (int) $id))
                        {
                            $errors[] = $this->language->lang('OSL_ERR_COMPETITION_LOCKED');
                        }
                    }
                }
            }
        }

        if ($league && $mode !== 'post' && $post_id !== $first_id && $this->request->is_set_post('osl_form_results'))
        {
            $selected = $this->submitted_competition_ids();
            $dates = $this->request->variable('osl_date', ['']);
            $scores = $this->request->variable('osl_score', [['']]);
            $tenx = $this->request->variable('osl_tenx', [['']]);
            $previous_choices = $this->request->variable('osl_previous_period', [0]);
            $now = $this->osl_now();
            $today = $this->format_date($now);
            $windows = $this->manager->get_submission_periods($league, $now);
            $saved_in_this_post = $mode === 'edit' ? $this->result_by_post($post_id) : [];
            $shooter_id = $mode === 'edit' ? (int) ($data['poster_id'] ?? 0) : (int) $this->user->data['user_id'];
            if ($selected && $league['groups_enabled'])
            {
                $group = trim($this->request->variable('osl_shooter_group', '', true));
                if (utf8_strlen(html_entity_decode($group, ENT_QUOTES, 'UTF-8')) > 20)
                {
                    $errors[] = $this->language->lang('OSL_ERR_GROUP_LENGTH');
                }
            }
            foreach ($selected as $id)
            {
                if (!isset($league['competitions'][$id]))
                {
                    $errors[] = $this->language->lang('OSL_ERR_INVALID_COMPETITION');
                    continue;
                }
                $comp = $league['competitions'][$id];
                $date = $this->parse_date((string) ($dates[$id] ?? ''), true);
                $round = $date ? $this->manager->get_round_index_for_date($date, (int) $league['start_date'], (int) $league['duration_months']) : 0;
                // Dates are stored at noon, not at the actual shooting time. Compare local days.
                if ($date && $this->format_date($date) > $today)
                {
                    $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_FUTURE_DATE');
                }
                elseif (!$round)
                {
                    $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_DATE_OUT_OF_RANGE');
                }
                else
                {
                    if (isset($saved_in_this_post[$id]))
                    {
                        // Existing results may be corrected without reopening closed rounds;
                        // editing a post must not move that result to another round.
                        if ($round !== (int) $saved_in_this_post[$id]['round_index'])
                        {
                            $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_PERIOD_CLOSED');
                        }
                    }
                    else
                    {
                        $allowed_round = !empty($previous_choices[$id])
                            ? (int) $windows['previous']
                            : (int) $windows['current'];
                        if (!$allowed_round || $round !== $allowed_round)
                        {
                            $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_PERIOD_CLOSED');
                        }
                        elseif (!empty($previous_choices[$id]))
                        {
                            // HTML date limits are not security checks: validate the late shooting date server-side.
                            $previous_bounds = $windows['rounds'][$allowed_round];
                            $earliest = $this->previous_round_earliest_date(
                                $previous_bounds, (int) ($league['extension_days'] ?? 0)
                            );
                            if ($date < $earliest)
                            {
                                $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang(
                                    'OSL_ERR_PREVIOUS_DATE_LIMIT',
                                    $this->format_date($earliest),
                                    $this->format_date((int) $previous_bounds['end_ts'])
                                );
                            }
                        }
                    }
                    $sql = 'SELECT result_id FROM ' . $this->results_table . '
                        WHERE league_id = ' . (int) $league['league_id'] . '
                        AND competition_id = ' . $id . '
                        AND user_id = ' . $shooter_id . '
                        AND round_index = ' . $round . '
                        AND post_id <> ' . $post_id;
                    $res = $this->db->sql_query_limit($sql, 1);
                    $duplicate = $this->db->sql_fetchrow($res);
                    $this->db->sql_freeresult($res);
                    if ($duplicate)
                    {
                        $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_DUPLICATE_PERIOD');
                    }
                }
                if ($shooter_id <= 1)
                {
                    $errors[] = $this->language->lang('OSL_ERR_PERMISSION');
                }
                foreach ($comp['fields_schema'] as $index => $field)
                {
                    $value = trim((string) ($scores[$id][$index] ?? ''));
                    $decimal_enabled = !empty($field['decimal_enabled']);
                    $normalized = str_replace(',', '.', $value);
                    $valid_format = preg_match($decimal_enabled
                        ? '/^-?[0-9]+(?:\\.[0-9])?$/D'
                        : '/^-?[0-9]+$/D', $normalized) === 1;
                    if (!$valid_format || !is_numeric($normalized)
                        || !$this->valid_score_precision((float) $normalized, $decimal_enabled))
                    {
                        $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang(
                            $decimal_enabled ? 'OSL_ERR_SCORE_PRECISION_DECIMAL' : 'OSL_ERR_SCORE_PRECISION_INTEGER',
                            $this->e($field['name'])
                        );
                    }
                    elseif ((float) $normalized < (float) $field['min'] - 0.000001
                        || (float) $normalized > (float) $field['max'] + 0.000001)
                    {
                        $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_SCORE_RANGE', $this->e($field['name']), $field['min'], $field['max']);
                    }
                    if (!empty($field['ten_x_enabled']))
                    {
                        $x_value = trim((string) ($tenx[$id][$index] ?? '0'));
                        if ($x_value === '')
                        {
                            $x_value = '0';
                        }
                        $max_x = $this->tenx_shot_limit($field);
                        if (!ctype_digit($x_value) || (int) $x_value > $max_x)
                        {
                            $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_TENX_RANGE', $this->e($field['name']), $max_x);
                        }
                    }
                }
            }
        }
        // A result submission constitutes the post content, even without a message.
        // Only waive phpBB's empty-message warning, not unrelated validation errors.
        if ($league
            && in_array($mode, ['reply', 'quote', 'edit'], true)
            && $post_id !== $first_id
            && $this->request->is_set_post('osl_form_results')
            && $this->request->variable('message', '', true) === '')
        {
            // A form marker alone is insufficient: at least one real competition
            // must be selected. Other OSL validation (scores, dates, duplicates)
            // above continues to apply normally.
            foreach ($this->submitted_competition_ids() as $competition_id)
            {
                if (isset($league['competitions'][$competition_id]))
                {
                    $empty_message_error = $this->language->lang('TOO_FEW_CHARS');
                    $errors = array_values(array_filter($errors, static function ($error) use ($empty_message_error) {
                        return $error !== $empty_message_error;
                    }));
                    break;
                }
            }
        }

        $event['error'] = $errors;
    }

    public function submit_post_save_data($event)
    {
        $mode = $event['mode'];
        $data = $event['data'];
        $topic_id = (int) ($data['topic_id'] ?? 0);
        $post_id = (int) ($data['post_id'] ?? 0);
        $forum_id = (int) ($data['forum_id'] ?? 0);
        $first_id = $this->get_first_post_id($topic_id, $data);
        $league = $this->manager->get_league_by_topic($topic_id);

        if (($mode === 'post' || ($mode === 'edit' && $post_id === $first_id)) &&
            $this->creator_permissions->can_create($forum_id) && $this->request->is_set_post('osl_enabled') &&
            $this->request->is_set_post('osl_form_league'))
        {
            $sql_ary = [
                'topic_id' => $topic_id,
                'league_author_id' => $league ? (int) $league['league_author_id'] : (int) $data['poster_id'],
                // Use exactly the subject submitted and accepted by phpBB.
                'title' => (string) $event['subject'],
                'short_name' => $this->request->variable('osl_short_name', '', true),
                'start_date' => $this->parse_start_month($this->request->variable('osl_start_date', '')),
                'duration_months' => $this->posted_league_integer('osl_duration_months'),
                'best_rounds_count' => $this->posted_league_integer('osl_best_rounds'),
                'extension_days' => $this->posted_league_integer('osl_extension_days'),
                'groups_enabled' => json_encode($this->request->is_set_post('osl_groups_enabled')),
                // Keep the text whether or not the instruction is enabled.
                'reply_instructions' => $this->request->is_set_post('osl_reply_instructions')
                    ? $this->request->untrimmed_variable('osl_reply_instructions', '', true)
                    : (string) ($league['reply_instructions'] ?? ''),
                'reply_instructions_enabled' => $this->request->is_set_post('osl_instructions_enabled') ? 1 : 0,
            ];
            $this->db->sql_transaction('begin');
            if ($league)
            {
                $this->db->sql_query('UPDATE ' . $this->leagues_table . ' SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . ' WHERE league_id = ' . (int) $league['league_id']);
                $league_id = (int) $league['league_id'];
            }
            else
            {
                $this->db->sql_query('INSERT INTO ' . $this->leagues_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
                $league_id = (int) $this->db->sql_nextid();
            }
            $keep_ids = [];
            foreach ($this->posted_competitions() as $position => $comp)
            {
                $comp_sql = [
                    'league_id' => $league_id,
                    'title' => $comp['name'],
                    'sort_order' => $position,
                    'fields_schema' => json_encode($comp['fields_schema'], JSON_UNESCAPED_UNICODE),
                ];
                if ($comp['id'])
                {
                    $this->db->sql_query('UPDATE ' . $this->competitions_table . ' SET ' . $this->db->sql_build_array('UPDATE', $comp_sql) . ' WHERE competition_id = ' . $comp['id'] . ' AND league_id = ' . $league_id);
                    $keep_ids[] = $comp['id'];
                }
                else
                {
                    $this->db->sql_query('INSERT INTO ' . $this->competitions_table . ' ' . $this->db->sql_build_array('INSERT', $comp_sql));
                    $keep_ids[] = (int) $this->db->sql_nextid();
                }
            }
            if ($league)
            {
                $where = 'league_id = ' . $league_id;
                if ($keep_ids)
                {
                    $where .= ' AND ' . $this->db->sql_in_set('competition_id', $keep_ids, true);
                }
                $this->db->sql_query('DELETE FROM ' . $this->competitions_table . ' WHERE ' . $where);
            }
            $this->db->sql_transaction('commit');
            return;
        }

        if (!$league || $mode === 'post' || ($mode === 'edit' && $post_id === $first_id) || !$this->request->is_set_post('osl_form_results'))
        {
            return;
        }
        $selected = $this->submitted_competition_ids();
        $old = $this->result_by_post($post_id);
        $dates = $this->request->variable('osl_date', ['']);
        $scores = $this->request->variable('osl_score', [['']]);
        $tenx = $this->request->variable('osl_tenx', [['']]);
        $shooter_id = (int) $data['poster_id'];
        $group = $league['groups_enabled']
            ? trim($this->request->variable('osl_shooter_group', '', true))
            : '';

        // Defense in depth: never change stored results if a selected date is invalid or future.
        $today = $this->format_date($this->osl_now());
        foreach ($selected as $comp_id)
        {
            $date = $this->parse_date((string) ($dates[$comp_id] ?? ''), true);
            if (!$date || $this->format_date($date) > $today)
            {
                return;
            }
        }

        $this->db->sql_transaction('begin');
        foreach ($old as $comp_id => $row)
        {
            if (!in_array($comp_id, $selected, true))
            {
                $this->db->sql_query('DELETE FROM ' . $this->results_table . ' WHERE result_id = ' . (int) $row['result_id']);
            }
        }
        foreach ($selected as $comp_id)
        {
            if (!isset($league['competitions'][$comp_id]))
            {
                continue;
            }
            $date = $this->parse_date((string) ($dates[$comp_id] ?? ''), true);
            $round = $this->manager->get_round_index_for_date($date, (int) $league['start_date'], (int) $league['duration_months']);
            $score_map = [];
            $tenx_map = [];
            $total = 0.0;
            $total_x = 0;
            foreach ($league['competitions'][$comp_id]['fields_schema'] as $index => $field)
            {
                $value = (float) str_replace(',', '.', (string) ($scores[$comp_id][$index] ?? '0'));
                $score_map[$index] = $value;
                $total += $value;
                if (!empty($field['ten_x_enabled']))
                {
                    $x_value = (int) ($tenx[$comp_id][$index] ?? 0);
                    $tenx_map[$index] = $x_value;
                    $total_x += $x_value;
                }
            }
            $sql_ary = [
                'league_id' => (int) $league['league_id'],
                'competition_id' => $comp_id,
                'post_id' => $post_id,
                'user_id' => (int) $data['poster_id'],
                'shooter_group' => $group,
                'round_date' => $date,
                'round_index' => $round,
                'scores_data' => json_encode($score_map, JSON_UNESCAPED_UNICODE),
                'x_scores_data' => json_encode($tenx_map, JSON_UNESCAPED_UNICODE),
                'total_score' => round($total, 2),
                'total_x' => $total_x,
            ];
            if (isset($old[$comp_id]))
            {
                $this->db->sql_query('UPDATE ' . $this->results_table . ' SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . ' WHERE result_id = ' . (int) $old[$comp_id]['result_id']);
            }
            else
            {
                $this->db->sql_query('INSERT INTO ' . $this->results_table . ' ' . $this->db->sql_build_array('INSERT', $sql_ary));
            }
        }
        // Changing the user's club affects all previous results in this league,
        // across all disciplines. It never splits their ranking or point totals.
        if ($selected && $league['groups_enabled'])
        {
            $this->db->sql_query('UPDATE ' . $this->results_table .
                ' SET ' . $this->db->sql_build_array('UPDATE', ['shooter_group' => $group]) .
                ' WHERE league_id = ' . (int) $league['league_id'] .
                ' AND user_id = ' . $shooter_id);
        }
        $this->db->sql_transaction('commit');
    }

    public function delete_posts_remove_results($event)
    {
        $ids = $event['post_ids'];
        if ($ids)
        {
            $this->db->sql_query('DELETE FROM ' . $this->results_table . ' WHERE ' . $this->db->sql_in_set('post_id', $ids));
        }
    }

    public function delete_topics_remove_leagues($event)
    {
        $ids = $event['topic_ids'];
        if (!$ids)
        {
            return;
        }
        $res = $this->db->sql_query('SELECT league_id FROM ' . $this->leagues_table . ' WHERE ' . $this->db->sql_in_set('topic_id', $ids));
        $league_ids = [];
        while ($row = $this->db->sql_fetchrow($res))
        {
            $league_ids[] = (int) $row['league_id'];
        }
        $this->db->sql_freeresult($res);
        if (!$league_ids)
        {
            return;
        }
        $this->db->sql_query('DELETE FROM ' . $this->results_table . ' WHERE ' . $this->db->sql_in_set('league_id', $league_ids));
        $this->db->sql_query('DELETE FROM ' . $this->competitions_table . ' WHERE ' . $this->db->sql_in_set('league_id', $league_ids));
        $this->db->sql_query('DELETE FROM ' . $this->leagues_table . ' WHERE ' . $this->db->sql_in_set('league_id', $league_ids));
    }

    public function viewtopic_preload_league_data($event)
    {
        $topic_id = (int) $event['topic_data']['topic_id'];
        $league = $this->get_cached_league($topic_id);
        if (!$league)
        {
            return;
        }
        $this->cached_rounds[$topic_id] = $this->manager->generate_rounds((int) $league['start_date'], (int) $league['duration_months']);
        $this->cached_standings[$topic_id] = $this->manager->get_league_standings($league);
        $ids = $event['post_list'];
        if ($ids)
        {
            $res = $this->db->sql_query('SELECT * FROM ' . $this->results_table . ' WHERE ' . $this->db->sql_in_set('post_id', $ids));
            while ($row = $this->db->sql_fetchrow($res))
            {
                $this->cached_post_results[(int) $row['post_id']][(int) $row['competition_id']] = $row;
            }
            $this->db->sql_freeresult($res);
        }
    }

    public function viewtopic_modify_post_row($event)
    {
        $row = $event['row'];
        $topic = $event['topic_data'];
        $post_row = $event['post_row'];
        $topic_id = (int) $topic['topic_id'];
        $post_id = (int) $row['post_id'];
        $league = $this->get_cached_league($topic_id);
        if (!$league)
        {
            return;
        }
        $rounds = $this->cached_rounds[$topic_id] ?? [];
        if ($post_id === (int) $topic['topic_first_post_id'])
        {
            $post_row['S_OSL_IS_FIRST_POST'] = true;
            $short_name = trim((string) ($league['short_name'] ?? ''));
            $heading = $short_name !== ''
                ? $this->language->lang('OSL_STANDINGS_SHORT', $short_name)
                : $this->language->lang('OSL_STANDINGS_TITLE');
            $updated = $this->manager->get_results_updated_at((int) $league['league_id']);
            if ($updated !== null)
            {
                $heading = $this->language->lang('OSL_STANDINGS_UPDATED', $heading, $updated->format('d.m.Y'), $updated->format('H:i'));
            }
            $post_row['OSL_STANDINGS_HEADING'] = $this->e($heading);
            // One export group for the entire league, not one per competition.
            $post_row['U_OSL_EXPORT_CSV'] = $this->controller_helper->route('tennano_osl_export', ['topic_id' => $topic_id, 'format' => 'csv']);
            $post_row['U_OSL_EXPORT_PDF'] = $this->controller_helper->route('tennano_osl_export', ['topic_id' => $topic_id, 'format' => 'pdf']);
            $post_row['S_OSL_HAS_GROUPS'] = (bool) $league['groups_enabled'];
            $duration_months = (int) $league['duration_months'];
            $configured_best = (int) $league['best_rounds_count'];
            // 0 means all rounds count towards the best-round total.
            $effective_best = $configured_best > 0 ? min($configured_best, $duration_months) : $duration_months;
            // When all league rounds count, the best total and grand total are identical.
            $show_total_all = $effective_best < $duration_months;
            $post_row['S_OSL_SHOW_TOTAL_ALL'] = $show_total_all;
            $post_row['OSL_BEST_TOTAL_LABEL'] = $this->e($show_total_all
                ? $this->language->lang('OSL_COL_TOTAL_BEST', $effective_best, $duration_months)
                : $this->language->lang('OSL_COL_TOTAL'));

            // Short table headers as in the league score sheet. For leagues longer
            // than 12 months also keep the year to distinguish repeated months.
            $table_month_names = [
                'Jan' => $this->language->lang('OSL_MONTH_JAN'),
                'Feb' => $this->language->lang('OSL_MONTH_FEB'),
                'Mar' => $this->language->lang('OSL_MONTH_MAR'),
                'Apr' => $this->language->lang('OSL_MONTH_APR'),
                'May' => $this->language->lang('OSL_MONTH_MAY'),
                'Jun' => $this->language->lang('OSL_MONTH_JUN'),
                'Jul' => $this->language->lang('OSL_MONTH_JUL'),
                'Aug' => $this->language->lang('OSL_MONTH_AUG'),
                'Sep' => $this->language->lang('OSL_MONTH_SEP'),
                'Oct' => $this->language->lang('OSL_MONTH_OCT'),
                'Nov' => $this->language->lang('OSL_MONTH_NOV'),
                'Dec' => $this->language->lang('OSL_MONTH_DEC'),
            ];
            $post_row['osl_tables'] = [];
            foreach ($league['competitions'] as $comp_id => $comp)
            {
                $table = [
                    'NAME' => $this->e($comp['title']),
                    'HAS_TENX' => $this->manager->competition_has_tenx($comp),
                    'HAS_STANDINGS' => !empty($this->cached_standings[$topic_id][$comp_id]),
                    'rounds' => [],
                    'standings' => [],
                ];
                foreach ($rounds as $round)
                {
                    $label_parts = explode(' ', $round['label'], 2);
                    $short_label = $table_month_names[$label_parts[0]] ?? $label_parts[0];
                    if ($duration_months > 12 && isset($label_parts[1]))
                    {
                        $short_label .= ' ' . $label_parts[1];
                    }
                    $table['rounds'][] = ['LABEL' => $this->e($short_label), 'FULL_DATE' => $round['full_date']];
                }
                foreach (($this->cached_standings[$topic_id][$comp_id] ?? []) as $player)
                {
                    $standing = [
                        'RANK' => $player['rank'],
                        'GROUP' => $this->e($player['group']),
                        'USERNAME' => get_username_string('full', $player['user_id'], $player['username'], $player['user_colour']),
                        'TOTAL_BEST' => $player['total_best'],
                        'TENX_BEST' => $player['tenx_best'],
                        'TOTAL_ALL' => $player['total_all'],
                        'STARTS' => $player['starts'],
                        'AVERAGE' => number_format($player['average'], 2, ',', ''),
                        'scores' => [],
                    ];
                    foreach ($rounds as $index => $round)
                    {
                        $has_round = array_key_exists($index, $player['round_scores']);
                        $standing['scores'][] = [
                            'VALUE' => $has_round ? $player['round_scores'][$index] : '–',
                            'TENX' => $has_round ? (int) ($player['round_tenx'][$index] ?? 0) : '–',
                        ];
                    }
                    $table['standings'][] = $standing;
                }
                $post_row['osl_tables'][] = $table;
            }
        }
        elseif (!empty($this->cached_post_results[$post_id]))
        {
            // The round labels in storage are language-neutral (e.g. "Nov 2026").
            // Translate them only when rendering, using the viewer's phpBB language.
            $month_keys = [
                'Jan' => 'OSL_MONTH_JAN', 'Feb' => 'OSL_MONTH_FEB',
                'Mar' => 'OSL_MONTH_MAR', 'Apr' => 'OSL_MONTH_APR',
                'May' => 'OSL_MONTH_MAY', 'Jun' => 'OSL_MONTH_JUN',
                'Jul' => 'OSL_MONTH_JUL', 'Aug' => 'OSL_MONTH_AUG',
                'Sep' => 'OSL_MONTH_SEP', 'Oct' => 'OSL_MONTH_OCT',
                'Nov' => 'OSL_MONTH_NOV', 'Dec' => 'OSL_MONTH_DEC',
            ];
            $post_row['S_OSL_HAS_POST_RESULT'] = true;
            // Resolve the first post directly so the table link also works
            // when a result is displayed on a later topic page.
            $post_row['U_OSL_STANDINGS'] = $this->e(
                append_sid('viewtopic.php', 'p=' . (int) $topic['topic_first_post_id']) . '#osl-standings'
            );
            $post_row['osl_post_result_groups'] = [];
            $group_indexes = [];
            foreach ($this->cached_post_results[$post_id] as $comp_id => $result)
            {
                if (!isset($league['competitions'][$comp_id]))
                {
                    continue;
                }

                $round_index = (int) $result['round_index'];
                $round_label = $rounds[$round_index]['label'] ?? '#' . $round_index;
                $label_parts = explode(' ', $round_label, 2);
                if (isset($month_keys[$label_parts[0]]))
                {
                    $round_label = $this->language->lang($month_keys[$label_parts[0]])
                        . (isset($label_parts[1]) ? ' ' . $label_parts[1] : '');
                }

                $date = (new \DateTimeImmutable('@' . (int) $result['round_date']))
                    ->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m.Y');
                $group = (string) $result['shooter_group'];
                // A reply can contain results for distinct shooting dates or rounds.
                // Keep those in separate sections rather than showing a false shared date.
                $key = $round_index . '|' . $date . '|' . $group;
                if (!isset($group_indexes[$key]))
                {
                    $group_indexes[$key] = count($post_row['osl_post_result_groups']);
                    $post_row['osl_post_result_groups'][] = [
                        'LEAGUE' => $this->e($league['title']),
                        'DATE' => $date,
                        'ROUND' => $this->e($round_label),
                        'NICK' => $this->e((string) ($row['username'] ?? $row['post_username'] ?? '')),
                        'GROUP' => $this->e($group),
                        'HAS_GROUP' => !empty($league['groups_enabled']) && $group !== '',
                        'competitions' => [],
                    ];
                }

                $post_row['osl_post_result_groups'][$group_indexes[$key]]['competitions'][] = [
                    'NAME' => $this->e($league['competitions'][$comp_id]['title']),
                    'TOTAL' => number_format((float) $result['total_score'],
                        $this->competition_has_decimal_scores($league['competitions'][$comp_id]) ? 1 : 0, '.', ''),
                    'HAS_TENX' => $this->manager->competition_has_tenx($league['competitions'][$comp_id]),
                    'TENX' => (int) ($result['total_x'] ?? 0),
                ];
            }
        }
        $event['post_row'] = $post_row;
    }
}
