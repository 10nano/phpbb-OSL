<?php

namespace tennano\osl\service;

class league_manager
{
    protected $db;
    protected $leagues_table;
    protected $competitions_table;
    protected $results_table;

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        string $leagues_table,
        string $competitions_table,
        string $results_table
    ) {
        $this->db = $db;
        $this->leagues_table = $leagues_table;
        $this->competitions_table = $competitions_table;
        $this->results_table = $results_table;
    }

    /** Each round is a full calendar month in the Europe/Warsaw time zone. */
    public function generate_rounds(int $start_timestamp, int $duration_months): array
    {
        $months = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $zone = new \DateTimeZone('Europe/Warsaw');
        $start = (new \DateTimeImmutable('@' . $start_timestamp))->setTimezone($zone);
        // Normalize even historical start dates to the first of their month.
        // First-of-month arithmetic handles February, leap years and year changes.
        $base = $start->modify('first day of this month')->setTime(0, 0, 0);
        $boundaries = [];
        for ($i = 0; $i <= $duration_months; $i++)
        {
            $boundaries[$i] = $base->modify('+' . $i . ' months');
        }
        $rounds = [];
        for ($i = 0; $i < $duration_months; $i++)
        {
            $from = $boundaries[$i];
            $to = $boundaries[$i + 1]->modify('-1 second');
            $rounds[$i + 1] = [
                'index' => $i + 1,
                'label' => $months[(int) $from->format('n')] . ' ' . $from->format('Y'),
                'full_date' => $from->format('d.m.Y') . ' – ' . $to->format('d.m.Y'),
                'start_ts' => $from->getTimestamp(),
                'end_ts' => $to->getTimestamp(),
            ];
        }
        return $rounds;
    }

    /**
     * The current round is open while it is running. For up to seven extra
     * calendar days after a round ends, that round can also be submitted as
     * the previous round. This includes the final round of the league.
     */
    public function get_submission_periods(array $league, int $now): array
    {
        $rounds = $this->generate_rounds((int) $league['start_date'], (int) $league['duration_months']);
        $current = $this->get_round_index_for_date($now, (int) $league['start_date'], (int) $league['duration_months']);
        $previous = 0;
        $extra_days = max(0, min(7, (int) ($league['extension_days'] ?? 0)));
        if ($extra_days > 0)
        {
            $zone = new \DateTimeZone('Europe/Warsaw');
            foreach ($rounds as $index => $round)
            {
                $next_start = (int) $round['end_ts'] + 1;
                $end_grace = (new \DateTimeImmutable('@' . $next_start))
                    ->setTimezone($zone)
                    ->modify('+' . $extra_days . ' days')
                    ->getTimestamp();
                if ($now >= $next_start && $now < $end_grace)
                {
                    $previous = (int) $index;
                    break;
                }
            }
        }

        return ['current' => $current, 'previous' => $previous, 'rounds' => $rounds];
    }

    public function get_round_index_for_date(int $result_timestamp, int $start_timestamp, int $duration_months): int
    {
        foreach ($this->generate_rounds($start_timestamp, $duration_months) as $index => $round)
        {
            if ($result_timestamp >= $round['start_ts'] && $result_timestamp <= $round['end_ts'])
            {
                return $index;
            }
        }
        return 0;
    }

    public function get_league_by_topic(int $topic_id): ?array
    {
        $sql = 'SELECT * FROM ' . $this->leagues_table . ' WHERE topic_id = ' . $topic_id;
        $res = $this->db->sql_query($sql);
        $league = $this->db->sql_fetchrow($res);
        $this->db->sql_freeresult($res);
        if (!$league)
        {
            return null;
        }
        // Old installations stored a list of allowed groups. New installations
        // store a boolean in the same TEXT column, without requiring a migration.
        $group_option = json_decode((string) $league['groups_enabled'], true);
        $league['groups_enabled'] = is_array($group_option)
            ? !empty($group_option)
            : ($group_option === true);
        $league['competitions'] = $this->get_competitions((int) $league['league_id']);
        return $league;
    }

    public function get_competitions(int $league_id): array
    {
        $sql = 'SELECT * FROM ' . $this->competitions_table . ' WHERE league_id = ' . $league_id . ' ORDER BY sort_order ASC, competition_id ASC';
        $res = $this->db->sql_query($sql);
        $items = [];
        while ($row = $this->db->sql_fetchrow($res))
        {
            $row['fields_schema'] = json_decode($row['fields_schema'], true) ?: [];
            $items[(int) $row['competition_id']] = $row;
        }
        $this->db->sql_freeresult($res);
        return $items;
    }

    /** Whether a discipline has at least one series with an inner-ten counter. */
    public function competition_has_tenx(array $competition): bool
    {
        foreach ($competition['fields_schema'] as $field)
        {
            if (!empty($field['ten_x_enabled']))
            {
                return true;
            }
        }
        return false;
    }

    /** Returns competition_id => standings. Independent scores and ranking per competition. */
    public function get_league_standings(array $league): array
    {
        $players = [];
        foreach ($league['competitions'] as $competition_id => $competition)
        {
            $players[$competition_id] = [];
        }
        $sql = 'SELECT r.*, u.username, u.user_colour
            FROM ' . $this->results_table . ' r
            LEFT JOIN ' . USERS_TABLE . ' u ON r.user_id = u.user_id
            WHERE r.league_id = ' . (int) $league['league_id'] . '
            ORDER BY r.result_id ASC';
        $res = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($res))
        {
            $comp_id = (int) $row['competition_id'];
            if (!isset($players[$comp_id]))
            {
                continue;
            }
            // Ranking is per user and discipline, never per club/group.
            $key = (int) $row['user_id'];
            if (!isset($players[$comp_id][$key]))
            {
                $players[$comp_id][$key] = [
                    'user_id' => (int) $row['user_id'],
                    'username' => $row['username'] ?: 'Deleted user',
                    'user_colour' => $row['user_colour'] ?: '',
                    'group' => $row['shooter_group'],
                    'scores' => [],
                ];
            }
            // Keep the most recent group if historical rows differ. New writes
            // synchronize the group across all of the user's league results.
            $players[$comp_id][$key]['group'] = (string) $row['shooter_group'];
            $players[$comp_id][$key]['scores'][(int) $row['round_index']] = [
                'score' => (float) $row['total_score'],
                'ten_x' => (int) ($row['total_x'] ?? 0),
            ];
        }
        $this->db->sql_freeresult($res);

        $standings = [];
        foreach ($players as $comp_id => $player_data)
        {
            $standings[$comp_id] = $this->calculate_standings($player_data, (int) $league['best_rounds_count']);
        }
        return $standings;
    }

    protected function calculate_standings(array $players_data, int $best_rounds_count): array
    {
        $standings = [];
        foreach ($players_data as $player)
        {
            // A start keeps its ordinary points and the independent 10X count.
            $rounds = $player['scores'];
            $starts = count($rounds);
            $numeric = array_values($rounds);
            usort($numeric, static function ($a, $b) {
                $cmp = $b['score'] <=> $a['score'];
                return $cmp ?: ($b['ten_x'] <=> $a['ten_x']);
            });
            // If scores tie at the best-N boundary, prefer the start with more 10X.
            $best = $best_rounds_count > 0 ? array_slice($numeric, 0, $best_rounds_count) : $numeric;
            $round_scores = [];
            $round_tenx = [];
            foreach ($rounds as $index => $entry)
            {
                $round_scores[$index] = $entry['score'];
                $round_tenx[$index] = $entry['ten_x'];
            }
            // Scores are stored with two decimal places. Normalize sums
            // before ranking so 0.1 + 0.2 ties exactly with 0.3.
            $total_all = round(array_sum(array_column($numeric, 'score')), 2);
            $total_best = round(array_sum(array_column($best, 'score')), 2);
            $standings[] = [
                'user_id' => $player['user_id'],
                'username' => $player['username'],
                'user_colour' => $player['user_colour'],
                'group' => $player['group'],
                'round_scores' => $round_scores,
                'round_tenx' => $round_tenx,
                'total_best' => $total_best,
                'tenx_best' => array_sum(array_column($best, 'ten_x')),
                'total_all' => $total_all,
                'starts' => $starts,
                'average' => $starts ? round($total_all / $starts, 8) : 0,
                'highest_single' => $starts ? $numeric[0]['score'] : 0,
                'rank' => 0,
            ];
        }

        usort($standings, static function ($a, $b) {
            // Points decide the standings. 10X is the first tie-breaker,
            // ahead of total points from all starts, average, and best result.
            foreach (['total_best', 'tenx_best', 'total_all', 'average', 'highest_single'] as $key)
            {
                $cmp = $b[$key] <=> $a[$key];
                if ($cmp !== 0)
                {
                    return $cmp;
                }
            }
            return strnatcasecmp($a['username'], $b['username']);
        });

        $prev = null;
        $rank = 0;
        foreach ($standings as $pos => &$player)
        {
            $signature = [$player['total_best'], $player['tenx_best'], $player['total_all'], $player['average'], $player['highest_single']];
            if ($prev === null || $signature !== $prev)
            {
                $rank = $pos + 1;
            }
            $player['rank'] = $rank;
            $prev = $signature;
        }
        unset($player);
        return $standings;
    }

}
