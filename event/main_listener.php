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

    protected function format_date(int $timestamp): string
    {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('Y-m-d');
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
                    // Fields without 10X keep their existing JSON representation.
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

        if (($mode === 'post' || $is_edit_first) && $this->creator_permissions->can_create($forum_id))
        {
            $start = $this->request->variable('osl_start_date', $league ? $this->format_date((int) $league['start_date']) : date('Y') . '-01-01');
            $this->template->assign_vars([
                'S_OSL_CAN_CREATE_LEAGUE' => true,
                'OSL_ENABLED' => $this->request->is_set_post('osl_form_league') ? $this->request->is_set_post('osl_enabled') : (bool) $league,
                'OSL_TITLE' => $this->e($this->request->variable('osl_title', $league['title'] ?? '', true)),
                'OSL_SHORT_NAME' => $this->e($this->request->variable('osl_short_name', $league['short_name'] ?? '', true)),
                'OSL_START_DATE' => $this->e($start),
                'OSL_DURATION_MONTHS' => $this->request->variable('osl_duration_months', (int) ($league['duration_months'] ?? 12)),
                'OSL_BEST_ROUNDS' => $this->request->variable('osl_best_rounds', (int) ($league['best_rounds_count'] ?? 8)),
                'OSL_GROUPS_ENABLED' => $this->request->is_set_post('osl_form_league')
                    ? $this->request->is_set_post('osl_groups_enabled')
                    : (bool) ($league['groups_enabled'] ?? false),
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

        $existing = $mode === 'edit' ? $this->result_by_post($post_id) : [];
        $is_submitted = $this->request->is_set_post('osl_form_results');
        $selected = $is_submitted ? $this->submitted_competition_ids() : array_keys($existing);
        $posted_dates = $this->request->variable('osl_date', ['']);
        $posted_scores = $this->request->variable('osl_score', [['']]);
        $posted_tenx = $this->request->variable('osl_tenx', [['']]);
        $shooter_id = $mode === 'edit' ? (int) ($data['poster_id'] ?? 0) : (int) $this->user->data['user_id'];

        // Only lock new submissions. Editing an existing post must remain possible.
        // Resolve the active round using the same date boundaries as duplicate checks.
        $reported_competitions = [];
        if ($mode !== 'edit')
        {
            $active_round = $this->manager->get_round_index_for_date(
                time(),
                (int) $league['start_date'],
                (int) $league['duration_months']
            );
            $reported_competitions = $this->reported_competitions_for_round(
                (int) $league['league_id'],
                $shooter_id,
                $active_round
            );
        }

        $group = !$league['groups_enabled'] ? '' : ($is_submitted
            ? $this->request->variable('osl_shooter_group', '', true)
            : $this->get_shooter_group((int) $league['league_id'], $shooter_id));
        $this->template->assign_vars([
            'S_OSL_CAN_SUBMIT_RESULT' => true,
            'S_OSL_HAS_GROUPS' => (bool) $league['groups_enabled'],
            'OSL_SHOOTER_GROUP' => $this->e($group),
        ]);
        foreach ($league['competitions'] as $comp_id => $competition)
        {
            $saved = $existing[$comp_id] ?? null;
            $scores = $saved ? (json_decode($saved['scores_data'], true) ?: []) : [];
            $tenx_scores = $saved ? (json_decode($saved['x_scores_data'] ?? '', true) ?: []) : [];
            $date = $is_submitted ? ($posted_dates[$comp_id] ?? date('Y-m-d')) : ($saved ? $this->format_date((int) $saved['round_date']) : date('Y-m-d'));
            $this->template->assign_block_vars('osl_result_competitions', [
                'ID' => $comp_id,
                'NAME' => $this->e($competition['title']),
                'REPORTED_THIS_MONTH' => !empty($reported_competitions[$comp_id]),
                'SELECTED' => empty($reported_competitions[$comp_id]) && in_array($comp_id, $selected, true),
                'DATE' => $this->e($date),
            ]);
            foreach ($competition['fields_schema'] as $index => $field)
            {
                $val = $is_submitted ? ($posted_scores[$comp_id][$index] ?? '') : ($scores[$field['name']] ?? '');
                $tenx_enabled = !empty($field['ten_x_enabled']);
                $tenx_value = $is_submitted
                    ? ($posted_tenx[$comp_id][$index] ?? '0')
                    : ($tenx_scores[$field['name']] ?? 0);
                $this->template->assign_block_vars('osl_result_competitions.fields', [
                    'INDEX' => $index,
                    'NAME' => $this->e($field['name']),
                    'MIN' => $field['min'],
                    'MAX' => $field['max'],
                    'VALUE' => $this->e($val),
                    'TENX_ENABLED' => $tenx_enabled,
                    'TENX_MAX' => $this->tenx_shot_limit($field),
                    'TENX_VALUE' => $this->e($tenx_value),
                ]);
            }
        }
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
                $title = trim($this->request->variable('osl_title', '', true));
                if ($title === '')
                {
                    $errors[] = $this->language->lang('OSL_ERR_NO_TITLE');
                }
                $start = $this->parse_date($this->request->variable('osl_start_date', ''));
                if (!$start)
                {
                    $errors[] = $this->language->lang('OSL_ERR_DATE_INVALID');
                }
                $duration = $this->request->variable('osl_duration_months', 12);
                $best = $this->request->variable('osl_best_rounds', 8);
                if ($duration < 1 || $duration > 24 || $best < 0 || $best > $duration)
                {
                    $errors[] = $this->language->lang('OSL_ERR_LIMITS');
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
                    $field_names = [];
                    foreach ($comp['fields_schema'] as $field)
                    {
                        if ($field['min'] > $field['max'])
                        {
                            $errors[] = $this->language->lang('OSL_ERR_INVALID_RANGE');
                        }
                        if (!empty($field['ten_x_enabled']) && $this->tenx_shot_limit($field) < 1)
                        {
                            $errors[] = $this->language->lang('OSL_ERR_TENX_SHOTS', $this->e($field['name']));
                        }
                        if (isset($field_names[$field['name']]))
                        {
                            $errors[] = $this->language->lang('OSL_ERR_DUPLICATE_FIELD');
                        }
                        $field_names[$field['name']] = true;
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
                        if ($start !== (int) $league['start_date'] || $duration !== (int) $league['duration_months'])
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
            $shooter_id = $mode === 'edit' ? (int) ($data['poster_id'] ?? 0) : (int) $this->user->data['user_id'];
            if ($selected && $league['groups_enabled'])
            {
                $group = trim($this->request->variable('osl_shooter_group', '', true));
                if (utf8_strlen($group) > 100)
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
                if (!$round)
                {
                    $errors[] = $this->e($comp['title']) . ': ' . $this->language->lang('OSL_ERR_DATE_OUT_OF_RANGE');
                }
                else
                {
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
                    if ($value === '' || !is_numeric(str_replace(',', '.', $value)) ||
                        (float) str_replace(',', '.', $value) < $field['min'] ||
                        (float) str_replace(',', '.', $value) > $field['max'])
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
                'title' => $this->request->variable('osl_title', '', true),
                'short_name' => $this->request->variable('osl_short_name', '', true),
                'start_date' => $this->parse_date($this->request->variable('osl_start_date', '')),
                'duration_months' => $this->request->variable('osl_duration_months', 12),
                'best_rounds_count' => $this->request->variable('osl_best_rounds', 8),
                'groups_enabled' => json_encode($this->request->is_set_post('osl_groups_enabled')),
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
                $score_map[$field['name']] = $value;
                $total += $value;
                if (!empty($field['ten_x_enabled']))
                {
                    $x_value = (int) ($tenx[$comp_id][$index] ?? 0);
                    $tenx_map[$field['name']] = $x_value;
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
            $post_row['OSL_LEAGUE_TITLE'] = $this->e($league['title']);
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
                'Jan' => 'Jan', 'Feb' => 'Feb', 'Mar' => 'Mar', 'Apr' => 'Apr',
                'May' => 'May', 'Jun' => 'Jun', 'Jul' => 'Jul', 'Aug' => 'Aug',
                'Sep' => 'Sep', 'Oct' => 'Oct', 'Nov' => 'Nov', 'Dec' => 'Dec',
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
            $post_row['S_OSL_HAS_POST_RESULT'] = true;
            $post_row['osl_post_results'] = [];
            foreach ($this->cached_post_results[$post_id] as $comp_id => $result)
            {
                if (!isset($league['competitions'][$comp_id]))
                {
                    continue;
                }
                $details = json_decode($result['scores_data'], true) ?: [];
                $x_details = json_decode($result['x_scores_data'] ?? '', true) ?: [];
                $part = [
                    'NAME' => $this->e($league['competitions'][$comp_id]['title']),
                    'ROUND' => $this->e($rounds[(int) $result['round_index']]['label'] ?? '#' . $result['round_index']),
                    'DATE' => (new \DateTimeImmutable('@' . (int) $result['round_date']))->setTimezone(new \DateTimeZone('Europe/Warsaw'))->format('d.m.Y'),
                    'GROUP' => $this->e($result['shooter_group']),
                    'TOTAL' => $result['total_score'],
                    'fields' => [],
                ];
                foreach ($league['competitions'][$comp_id]['fields_schema'] as $field)
                {
                    $name = $field['name'];
                    if (!array_key_exists($name, $details))
                    {
                        continue;
                    }
                    $part['fields'][] = [
                        'NAME' => $this->e($name),
                        'VALUE' => $this->e($details[$name]),
                        'HAS_TENX' => !empty($field['ten_x_enabled']),
                        'TENX' => (int) ($x_details[$name] ?? 0),
                    ];
                }
                $post_row['osl_post_results'][] = $part;
            }
        }
        $event['post_row'] = $post_row;
    }
}
