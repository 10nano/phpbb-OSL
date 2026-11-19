<?php

namespace tennano\osl\controller;

use Symfony\Component\HttpFoundation\Response;

/** Download all competition tables in one league as CSV or PDF. */
class export_controller
{
    private $manager;
    private $writer;
    private $pdf;
    private $db;
    private $auth;
    private $user;
    private $language;

    public function __construct(
        \tennano\osl\service\league_manager $manager,
        \tennano\osl\service\export_writer $writer,
        \tennano\osl\service\pdf_writer $pdf,
        \phpbb\db\driver\driver_interface $db,
        \phpbb\auth\auth $auth,
        \phpbb\user $user,
        \phpbb\language\language $language
    ) {
        $this->manager = $manager;
        $this->writer = $writer;
        $this->pdf = $pdf;
        $this->db = $db;
        $this->auth = $auth;
        $this->user = $user;
        $this->language = $language;
    }

    public function download($topic_id, $format)
    {
        $topic_id = (int) $topic_id;
        $format = strtolower((string) $format);
        $formats = ['csv' => 'text/csv; charset=UTF-8',
            'pdf' => 'application/pdf'];
        if ($topic_id <= 0 || !isset($formats[$format]))
        {
            return new Response('Unknown export format.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        // Never expose rankings from private, password-protected or unapproved topics.
        $sql = 'SELECT t.topic_id, t.forum_id, t.topic_visibility, f.forum_password
            FROM ' . TOPICS_TABLE . ' t
            INNER JOIN ' . FORUMS_TABLE . ' f ON f.forum_id = t.forum_id
            WHERE t.topic_id = ' . $topic_id;
        $res = $this->db->sql_query($sql);
        $topic = $this->db->sql_fetchrow($res);
        $this->db->sql_freeresult($res);
        if (!$topic)
        {
            return new Response('League not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $forum_id = (int) $topic['forum_id'];
        if (!$this->auth->acl_get('f_read', $forum_id) || !$this->auth->acl_get('f_list', $forum_id))
        {
            return new Response('Access denied.', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ((int) $topic['topic_visibility'] !== ITEM_APPROVED && !$this->auth->acl_get('m_approve', $forum_id))
        {
            return new Response('Access denied.', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        if ($topic['forum_password'] !== '' && method_exists($this->user, 'get_passworded_forums')
            && in_array($forum_id, $this->user->get_passworded_forums(), true))
        {
            return new Response('Forum password required.', 403, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $league = $this->manager->get_league_by_topic($topic_id);
        if (!$league)
        {
            return new Response('League not found.', 404, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $labels = [
            'rank' => $this->language->lang('OSL_COL_RANK'),
            'group' => $this->language->lang('OSL_COL_GROUP'),
            'nick' => $this->language->lang('OSL_COL_NICK'),
            'sum' => $this->language->lang('OSL_COL_TOTAL'),
            'best' => $this->language->lang('OSL_COL_TOTAL_BEST'),
            'sum_x' => $this->language->lang('OSL_COL_TENX_SUM'),
            'all' => $this->language->lang('OSL_COL_TOTAL_ALL'),
            'average' => $this->language->lang('OSL_COL_AVG'),
            'starts' => $this->language->lang('OSL_COL_STARTS'),
        ];
        $rounds = $this->manager->generate_rounds((int) $league['start_date'], (int) $league['duration_months']);
        $standings = $this->manager->get_league_standings($league);
        $report = $this->writer->build($league, $rounds, $standings, $labels);
        try
        {
            switch ($format)
            {
                case 'pdf': $body = $this->pdf->pdf($report); break;
                default: $body = $this->writer->csv($report); break;
            }
        }
        catch (\Throwable $e)
        {
            // Avoid exposing internal paths and stack traces to forum visitors.
            return new Response('League export is unavailable. Please contact an administrator.', 503,
                ['Content-Type' => 'text/plain; charset=UTF-8']);
        }
        $response = new Response($body, 200);
        $response->headers->set('Content-Type', $formats[$format]);
        $response->headers->set('Content-Disposition', 'attachment; filename="osl-league-' . $topic_id . '.' . $format . '"');
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
