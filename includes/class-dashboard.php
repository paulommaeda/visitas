<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Dashboard {
    private static function period(string $month): array {
        if ($month === 'all') { return []; }
        if (!preg_match('/^[1-9][0-9]{3}-(0[1-9]|1[0-2])$/D', $month)) { throw new HttpError('Selecione um mês válido.', 422); }
        $start = new \DateTimeImmutable($month.'-01', wp_timezone());
        $end = $start->modify('+1 month');
        $utc = new \DateTimeZone('UTC');
        return ['start'=>$start->format('Y-m-d'), 'end'=>$end->format('Y-m-d'),
            'utc_start'=>$start->setTimezone($utc)->format('Y-m-d H:i:s'), 'utc_end'=>$end->setTimezone($utc)->format('Y-m-d H:i:s')];
    }

    /** Dates without a time are local dates; timestamp fallbacks are stored in UTC. */
    private static function during(array $period, string $date, string $timestamp): string {
        global $wpdb;
        if (!$period) { return '1=1'; }
        return $wpdb->prepare("(($date >= %s AND $date < %s) OR ($date IS NULL AND $timestamp >= %s AND $timestamp < %s))",
            $period['start'], $period['end'], $period['utc_start'], $period['utc_end']);
    }

    private static function filters(\WP_REST_Request $r): array {
        global $wpdb;
        Access::require_cap('view_dashboard');
        $month = $r->get_param('month') ?? wp_date('Y-m');
        if (!is_string($month)) { throw new HttpError('Selecione um mês válido.', 422); }
        $period = self::period($month);
        $board = $r->get_param('board_id') ?? '0';
        if (!is_scalar($board) || !preg_match('/^\d+$/D', (string)$board)) { throw new HttpError('Selecione um quadro válido.', 422); }
        $boards = [];
        if ((int)$board) { $boards[] = Access::board((int)$board); }
        else {
            foreach ($wpdb->get_results('SELECT * FROM '.Store::table('boards').' ORDER BY name,id', ARRAY_A) as $b) {
                try { Access::board((int)$b['id']); $boards[] = $b; } catch (HttpError $e) { /* Respect private boards. */ }
            }
        }
        $ids = array_map('intval', array_column($boards, 'id'));
        $archived = $r->get_param('include_archived') === '1';
        $scope = $ids ? 'c.board_id IN ('.implode(',', $ids).')' : '1=0';
        if (!$archived) { $scope .= ' AND c.archived=0'; }
        $scope .= Access::card_scope_sql('c');
        return ['month'=>$month, 'period'=>$period, 'board_id'=>(int)$board, 'include_archived'=>$archived, 'boards'=>$boards, 'scope'=>$scope];
    }

    private static function cohort(array $filters): string {
        return $filters['scope'].' AND '.self::during($filters['period'], 'c.visit_date', 'c.created_at');
    }

    public static function summary(\WP_REST_Request $r): array {
        global $wpdb;
        $f = self::filters($r); $cards = Store::table('cards'); $activity = Store::table('activity');
        $cohort = self::cohort($f); $scope = $f['scope'];
        $activity_period = self::during($f['period'], 'a.occurred_on', 'a.created_at');
        $now = current_time('mysql', true); $deadline = Workflow::deadline_sql('c');
        $totals = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) AS visitors, COALESCE(SUM(c.completed=1),0) AS completed,
            COALESCE(SUM(c.completed=0 AND {$deadline}<%s),0) AS overdue FROM $cards c WHERE $cohort", $now), ARRAY_A);
        $followup = $wpdb->get_row("SELECT COUNT(DISTINCT a.card_id) AS accompanied,
            COALESCE(SUM(a.kind='visit'),0) AS visits, COUNT(DISTINCT CASE WHEN a.kind='visit' THEN a.card_id END) AS visited_people
            FROM $activity a INNER JOIN $cards c ON c.id=a.card_id WHERE $scope AND a.kind IN ('visit','comment','checklist_note') AND $activity_period", ARRAY_A);
        $totals = array_map('intval', array_merge($totals, $followup));
        $counts = [];
        foreach ($wpdb->get_results("SELECT c.list_id,COUNT(*) AS total FROM $cards c WHERE $cohort GROUP BY c.list_id", ARRAY_A) as $row) {
            $counts[(int)$row['list_id']] = (int)$row['total'];
        }
        $stages = [];
        if ($f['boards']) {
            $ids = implode(',', array_map('intval', array_column($f['boards'], 'id')));
            $names = array_column($f['boards'], 'name', 'id');
            foreach ($wpdb->get_results('SELECT * FROM '.Store::table('lists')." WHERE board_id IN ($ids) ORDER BY board_id,position,id", ARRAY_A) as $l) {
                $stages[] = ['id'=>(int)$l['id'], 'name'=>$l['name'], 'board_id'=>(int)$l['board_id'], 'board_name'=>$names[$l['board_id']],
                    'color'=>$l['color'], 'total'=>$counts[(int)$l['id']] ?? 0];
            }
        }
        // Two aggregate queries for all six months, rather than loading every card/activity.
        $anchor = new \DateTimeImmutable(($f['month'] === 'all' ? wp_date('Y-m') : $f['month']).'-01', wp_timezone());
        $trend = []; $visitor_columns = []; $followup_columns = [];
        for ($i=0; $i<6; $i++) {
            $month = $anchor->modify('-'.(5-$i).' months')->format('Y-m');
            $p = self::period($month);
            $visitor_columns[] = 'COALESCE(SUM(CASE WHEN '.self::during($p, 'c.visit_date', 'c.created_at')." THEN 1 ELSE 0 END),0) AS m$i";
            $followup_columns[] = 'COUNT(DISTINCT CASE WHEN '.self::during($p, 'a.occurred_on', 'a.created_at')." THEN a.card_id END) AS m$i";
            $trend[] = ['month'=>$month];
        }
        $v = $wpdb->get_row('SELECT '.implode(',', $visitor_columns)." FROM $cards c WHERE $scope", ARRAY_A);
        $a = $wpdb->get_row('SELECT '.implode(',', $followup_columns)." FROM $activity a INNER JOIN $cards c ON c.id=a.card_id WHERE $scope AND a.kind IN ('visit','comment','checklist_note')", ARRAY_A);
        foreach ($trend as $i=>&$point) { $point['visitors'] = (int)$v['m'.$i]; $point['accompanied'] = (int)$a['m'.$i]; }
        unset($point);
        $recent = $wpdb->get_results("SELECT a.*,c.title,c.board_id,l.name AS list_name,u.display_name AS author
            FROM $activity a INNER JOIN $cards c ON c.id=a.card_id LEFT JOIN ".Store::table('lists')." l ON l.id=c.list_id
            LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id WHERE $scope AND a.kind IN ('visit','comment','checklist_note') AND $activity_period ORDER BY a.id DESC LIMIT 6", ARRAY_A);
        return ['filters'=>['month'=>$f['month'], 'board_id'=>$f['board_id'], 'include_archived'=>$f['include_archived']],
            'totals'=>$totals, 'stages'=>$stages, 'trend'=>$trend, 'recent'=>$recent, 'generated_at'=>gmdate('c')];
    }

    public static function visitors(\WP_REST_Request $r): array {
        global $wpdb;
        $f = self::filters($r); $group = $r->get_param('group') ?? 'visitors';
        if (!in_array($group, ['visitors','accompanied','visited','overdue','completed'], true)) { throw new HttpError('Indicador inválido.', 422); }
        $where = self::cohort($f);
        if (in_array($group, ['accompanied','visited'], true)) {
            $kind = $group === 'visited' ? "a.kind='visit'" : "a.kind IN ('visit','comment','checklist_note')";
            $where = $f['scope'].' AND EXISTS (SELECT 1 FROM '.Store::table('activity').' a WHERE a.card_id=c.id AND '.$kind.' AND '.self::during($f['period'], 'a.occurred_on', 'a.created_at').')';
        }
        if ($group === 'overdue') { $where .= $wpdb->prepare(' AND c.completed=0 AND '.Workflow::deadline_sql('c').'<%s', current_time('mysql', true)); }
        if ($group === 'completed') { $where .= ' AND c.completed=1'; }
        $list = $r->get_param('list_id') ?? '0';
        if (!is_scalar($list) || !preg_match('/^\d+$/D', (string)$list)) { throw new HttpError('Etapa inválida.', 422); }
        if ((int)$list) {
            $l = Store::row('lists', (int)$list);
            if (!$l) { throw new HttpError('Etapa não encontrada.', 404); }
            Access::board((int)$l['board_id']);
            $where .= $wpdb->prepare(' AND c.list_id=%d', (int)$list);
        }
        $q = $r->get_param('q') ?? '';
        if (!is_string($q)) { throw new HttpError('Busca inválida.', 422); }
        $q = sanitize_text_field(substr($q, 0, 190));
        if ($q !== '') {
            $like = '%'.$wpdb->esc_like($q).'%';
            $where .= $wpdb->prepare(' AND (c.title LIKE %s OR c.email LIKE %s OR c.phone LIKE %s)', $like, $like, $like);
        }
        $page = max(1, min(1000000, absint($r->get_param('page') ?: 1))); $size = 20;
        $cards = Store::table('cards');
        $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM $cards c WHERE $where");
        $items = $wpdb->get_results("SELECT c.id,c.board_id,c.title,c.email,c.phone,c.visit_date,c.created_at,c.description,c.assigned_to,c.completed,c.archived,
            l.name AS list_name,b.name AS board_name,u.display_name AS assignee FROM $cards c
            INNER JOIN ".Store::table('boards')." b ON b.id=c.board_id LEFT JOIN ".Store::table('lists')." l ON l.id=c.list_id
            LEFT JOIN {$wpdb->users} u ON u.ID=c.assigned_to WHERE $where ORDER BY c.title,c.id".$wpdb->prepare(' LIMIT %d OFFSET %d', $size, ($page-1)*$size), ARRAY_A);
        return ['items'=>$items, 'total'=>$total, 'page'=>$page, 'has_more'=>$page*$size<$total];
    }

    public static function followups(\WP_REST_Request $r): array {
        global $wpdb;
        $card = Access::card((int)$r['id']);
        $page = max(1, min(1000000, absint($r->get_param('page') ?: 1)));
        $items = $wpdb->get_results($wpdb->prepare('SELECT a.*,u.display_name AS author FROM '.Store::table('activity')." a LEFT JOIN {$wpdb->users} u ON u.ID=a.user_id
            WHERE a.card_id=%d AND a.kind IN ('visit','comment','checklist_note') ORDER BY a.id DESC LIMIT 21 OFFSET %d", $card['id'], ($page-1)*20), ARRAY_A);
        $can_comment = false;
        try { Access::card((int)$card['id'], 'comment'); $can_comment = true; } catch (HttpError $e) {}
        return ['items'=>array_slice($items, 0, 20), 'page'=>$page, 'has_more'=>count($items)>20, 'can_comment'=>$can_comment];
    }

    public static function visit(\WP_REST_Request $r): array {
        $c = Access::card((int)$r['id'], 'comment'); $in = Api::body($r);
        if (!is_string($in['date'] ?? null) || !is_string($in['content'] ?? null)) { throw new HttpError('Informe a data e as observações da visita.', 422); }
        $date = Store::date($in['date']);
        $text = trim(sanitize_textarea_field(substr($in['content'], 0, 8000)));
        if (!$date || $date > wp_date('Y-m-d') || $text === '') { throw new HttpError('Informe uma data até hoje e as observações da visita realizada.', 422); }
        Store::activity((int)$c['id'], 'visit', $text, $date);
        return ['saved'=>true];
    }
}
