<?php

namespace tennano\osl\service;

/** Build league exports without changing scoring or adding Composer dependencies. */
class export_writer
{
    public function build(array $league, array $rounds, array $standings, array $labels): array
    {
        $count = (int) $league['duration_months'];
        $best = (int) $league['best_rounds_count'];
        $effective = $best > 0 ? min($best, $count) : $count;
        $all = $effective >= $count;
        $report = ['title' => (string) $league['title'], 'competitions' => []];
        foreach ($league['competitions'] as $compId => $comp)
        {
            $hasX = false;
            foreach ($comp['fields_schema'] as $field)
            {
                if (!empty($field['ten_x_enabled'])) { $hasX = true; break; }
            }
            $columns = [
                ['key' => 'rank', 'title' => $labels['rank'], 'type' => 'number'],
            ];
            if (!empty($league['groups_enabled']))
            {
                $columns[] = ['key' => 'group', 'title' => $labels['group'], 'type' => 'text'];
            }
            $columns[] = ['key' => 'nick', 'title' => $labels['nick'], 'type' => 'text'];
            $columns[] = ['key' => 'sum', 'title' => $all ? $labels['sum'] : sprintf($labels['best'], $effective, $count), 'type' => 'number'];
            if ($hasX) { $columns[] = ['key' => 'sum_x', 'title' => $labels['sum_x'], 'type' => 'number']; }
            if (!$all) { $columns[] = ['key' => 'all', 'title' => $labels['all'], 'type' => 'number']; }
            $columns[] = ['key' => 'average', 'title' => $labels['average'], 'type' => 'number'];
            $columns[] = ['key' => 'starts', 'title' => $labels['starts'], 'type' => 'number'];
            foreach ($rounds as $index => $round)
            {
                $label = (string) $round['label'];
                // Localize the neutral month label for both PDF and CSV.
                $parts = explode(' ', $label, 2);
                $month = $labels['months'][$parts[0]] ?? $parts[0];
                // Keep the year when a league contains repeated calendar months.
                $label = $month . ($count > 12 && isset($parts[1]) ? ' ' . $parts[1] : '');
                $columns[] = ['key' => 'p' . $index, 'title' => $label . ($hasX ? ' PKT' : ''), 'type' => 'number'];
                if ($hasX) { $columns[] = ['key' => 'x' . $index, 'title' => $label . ' 10X', 'type' => 'number']; }
            }
            $rows = [];
            foreach (($standings[$compId] ?? []) as $player)
            {
                $record = [
                    'rank' => (int) $player['rank'],
                    'group' => (string) $player['group'],
                    'nick' => (string) $player['username'],
                    'sum' => (float) $player['total_best'],
                    'sum_x' => (int) $player['tenx_best'],
                    'all' => (float) $player['total_all'],
                    'average' => round((float) $player['average'], 2),
                    'starts' => (int) $player['starts'],
                ];
                foreach ($rounds as $index => $_round)
                {
                    $record['p' . $index] = array_key_exists($index, $player['round_scores']) ? (float) $player['round_scores'][$index] : null;
                    if ($hasX)
                    {
                        $record['x' . $index] = array_key_exists($index, $player['round_scores']) ? (int) ($player['round_tenx'][$index] ?? 0) : null;
                    }
                }
                $rows[] = $record;
            }
            $report['competitions'][] = ['title' => (string) $comp['title'], 'columns' => $columns, 'rows' => $rows];
        }
        return $report;
    }

    private function csv_cell($value, bool $is_text = true): string
    {
        $text = $value === null ? '' : (string) $value;
        // Protect spreadsheet applications from formula injection in user-controlled names.
        if ($is_text && (preg_match('/^[\x00-\x20]*[=+@\-]/u', $text) || preg_match('/^[\x00-\x1F]/u', $text)))
        {
            $text = "'" . $text;
        }
        return '"' . str_replace('"', '""', $text) . '"';
    }

    public function csv(array $report): string
    {
        $out = "\xEF\xBB\xBF";
        $out .= $this->csv_cell($report['title']) . "\r\n\r\n";
        foreach ($report['competitions'] as $comp)
        {
            $out .= $this->csv_cell($comp['title']) . "\r\n";
            $headers = [];
            foreach ($comp['columns'] as $column) { $headers[] = $this->csv_cell($column['title']); }
            $out .= implode(';', $headers) . "\r\n";
            foreach ($comp['rows'] as $row)
            {
                $values = [];
                foreach ($comp['columns'] as $column)
                {
                    $value = $row[$column['key']] ?? null;
                    $values[] = $this->csv_cell($value, $column['type'] === 'text');
                }
                $out .= implode(';', $values) . "\r\n";
            }
            $out .= "\r\n";
        }
        return $out;
    }

}
