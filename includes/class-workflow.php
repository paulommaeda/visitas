<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

/** Checklist rules, immutable registration-based forecasts and per-step execution dates. */
final class Workflow {
    public static function settings(array $input = []): array {
        $defaults = ['enabled'=>false, 'sync_due'=>true, 'require_order'=>true, 'complete_card'=>false,
            'deadline_mode'=>'activation', 'weekdays'=>[1,2,3,4,5], 'holidays'=>[],
            'notes_enabled'=>true, 'notes_label'=>'Atividades e observações', 'timezone'=>wp_timezone_string()];
        $out = array_merge($defaults, array_intersect_key($input, $defaults));
        foreach (['enabled','sync_due','require_order','complete_card','notes_enabled'] as $key) { $out[$key] = rest_sanitize_boolean($out[$key]); }
        if (!in_array($out['deadline_mode'], ['activation','registration'], true)) { throw new HttpError('Escolha como começa o prazo das etapas.', 422); }
        if (!is_array($out['weekdays']) || !$out['weekdays']) { throw new HttpError('Selecione pelo menos um dia útil da semana.', 422); }
        $days = [];
        foreach ($out['weekdays'] as $day) {
            if (!is_scalar($day) || !preg_match('/^[1-7]$/D', (string)$day)) { throw new HttpError('Dia da semana inválido.', 422); }
            $days[] = (int)$day;
        }
        $out['weekdays'] = array_values(array_unique($days)); sort($out['weekdays']);
        if (!is_array($out['holidays']) || count($out['holidays']) > 500) { throw new HttpError('Informe até 500 feriados ou dias sem expediente.', 422); }
        $holidays = [];
        foreach ($out['holidays'] as $date) {
            if (!is_string($date) || !Store::date($date)) { throw new HttpError('Informe os feriados no formato ano-mês-dia.', 422); }
            $holidays[] = $date;
        }
        $out['holidays'] = array_values(array_unique($holidays)); sort($out['holidays']);
        $out['notes_label'] = self::text($out['notes_label'], 100) ?: $defaults['notes_label'];
        if (!is_string($out['timezone'])) { throw new HttpError('Fuso horário inválido.', 422); }
        try { new \DateTimeZone($out['timezone']); } catch (\Exception $e) { throw new HttpError('Fuso horário inválido.', 422); }
        return $out;
    }

    public static function text($value, int $length): string {
        if (!is_string($value) && !is_numeric($value)) { throw new HttpError('Informe um texto válido para a atividade ou observação.', 422); }
        return sanitize_textarea_field(substr((string)$value, 0, $length));
    }

    public static function rule(array $input, ?int $board_id = null): array {
        $amount = $input['amount'] ?? 0; $unit = $input['unit'] ?? 'business_days';
        if (!is_scalar($amount) || !is_numeric($amount) || !is_finite((float)$amount)
            || !in_array($unit, ['hours','days','business_days'], true) || (float)$amount < 0
            || (float)$amount > ($unit === 'hours' ? 8760 : 365)
            || ($unit !== 'hours' && (float)$amount != (int)$amount)) {
            throw new HttpError('Use um prazo de 0 a 365 dias inteiros, ou de 0 a 8760 horas.', 422);
        }
        $target = $input['target_list_id'] ?? 0;
        if (!is_scalar($target) || !preg_match('/^\d+$/D', (string)$target)) { throw new HttpError('Lista de destino inválida.', 422); }
        if ((int)$target && $board_id !== null) {
            $list = Store::row('lists', (int)$target);
            if (!$list || (int)$list['board_id'] !== $board_id) { throw new HttpError('O destino do checklist deve ser uma lista deste quadro.', 422); }
        }
        return ['amount'=>round((float)$amount, 2), 'unit'=>$unit, 'target_list_id'=>(int)$target, 'require_note'=>rest_sanitize_boolean($input['require_note'] ?? false)];
    }

    public static function add_time(\DateTimeImmutable $start, array $rule, array $settings): \DateTimeImmutable {
        $amount = $rule['amount'];
        if (!$amount) { return $start; }
        if ($rule['unit'] === 'hours') { return $start->setTimestamp($start->getTimestamp() + (int)round($amount * HOUR_IN_SECONDS)); }
        $date = $start->setTimezone(new \DateTimeZone($settings['timezone']));
        if ($rule['unit'] === 'days') { return $date->modify('+'.(int)$amount.' days'); }
        $holidays = array_fill_keys($settings['holidays'], true);
        for ($left=(int)$amount, $guard=0; $left>0; $guard++) {
            if ($guard > 8000) { throw new HttpError('O calendário não permite calcular este prazo. Revise os dias úteis e feriados.', 422); }
            $date = $date->modify('+1 day');
            if (in_array((int)$date->format('N'), $settings['weekdays'], true) && !isset($holidays[$date->format('Y-m-d')])) { $left--; }
        }
        return $date;
    }

    private static function iso(\DateTimeImmutable $date): string { return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'); }
    private static function index(array $lists): array {
        $out = [];
        foreach ($lists as $list) { foreach ($list['items'] as $item) { $out[$list['id'].'/'.$item['id']] = $item; } }
        return $out;
    }

    /** Returning events keeps rules and history inside the card's optimistic transaction. */
    public static function apply(array $lists, ?array $old, array $board, string $now, bool $use_board = false, bool $disable = false): array {
        $previous_lists = $old ? Store::json($old['checklists_json']) : [];
        $previous = self::index($previous_lists);
        $stored = $old ? Store::json($old['workflow_json'] ?? '') : [];
        $settings = self::settings($old && !$use_board ? $stored : Store::json($board['workflow_json'] ?? ''));
        if ($disable) { $settings['enabled'] = false; }
        if ($use_board && $old) {
            $model = self::index(Store::json($board['templates_json']));
            foreach ($lists as &$list) { foreach ($list['items'] as &$item) {
                if (isset($model[$list['id'].'/'.$item['id']])) {
                    $source = $model[$list['id'].'/'.$item['id']];
                    $item['rule'] = self::rule($source['rule'] ?? [], (int)$board['id']);
                    $item['instructions'] = self::text($source['instructions'] ?? '', 4000);
                }
            } unset($item); } unset($list);
        }
        $events = []; $target = 0; $pending_seen = false; $all_done = true; $count = 0; $budget = 0;
        $cursor = new \DateTimeImmutable(($old['created_at'] ?? $now).' UTC');
        $has_duration = false; $active = null;
        $previous_list_notes = array_column($previous_lists, 'notes', 'id');
        foreach ($lists as &$list) {
            $old_list = null;
            foreach ($previous_lists as $candidate) { if ($candidate['id'] === $list['id']) { $old_list = $candidate; break; } }
            foreach (['notes_at','notes_by'] as $key) { $list[$key] = $old_list[$key] ?? null; }
            if ($list['notes'] !== ($previous_list_notes[$list['id']] ?? '')) {
                $list['notes_at'] = str_replace(' ', 'T', $now).'Z'; $list['notes_by'] = get_current_user_id();
                $events[] = ['kind'=>$list['notes'] !== '' ? 'checklist_note' : 'checklist_changed', 'content'=>$list['title'].': '.($list['notes'] ?: 'Observações removidas.')];
            }
            foreach ($list['items'] as &$item) {
                $count++; $before = $previous[$list['id'].'/'.$item['id']] ?? [];
                if ((int)$item['assigned_to'] !== (int)($before['assigned_to'] ?? 0)) {
                    $person = $item['assigned_to'] ? get_userdata($item['assigned_to']) : null;
                    $events[] = ['kind'=>'checklist_assignment', 'content'=>$list['title'].' / '.$item['text'].': '.($person ? 'Responsável pela tarefa: '.$person->display_name.'.' : 'Responsável pela tarefa removido.')];
                }
                $changed_done = (bool)$item['done'] !== (bool)($before['done'] ?? false);
                foreach (['started_at','completed_at','completed_by','notes_at','notes_by'] as $key) { $item[$key] = $before[$key] ?? null; }
                $rule = $item['rule'];
                $budget += $rule['unit'] === 'hours' ? $rule['amount']/24 : $rule['amount'];
                if ($budget > 3660) { throw new HttpError('A soma dos tempos de execução ultrapassa 3.660 dias de etapas.', 422); }
                if ($item['notes'] !== ($before['notes'] ?? '')) {
                    $item['notes_at'] = str_replace(' ', 'T', $now).'Z'; $item['notes_by'] = get_current_user_id();
                    $events[] = ['kind'=>$item['notes'] !== '' ? 'checklist_note' : 'checklist_changed', 'content'=>$list['title'].' / '.$item['text'].': '.($item['notes'] ?: 'Observações removidas.')];
                }
                if ($changed_done) {
                    if ($item['done']) {
                        if ($settings['enabled'] && $settings['require_order'] && $pending_seen) { throw new HttpError('Conclua os itens anteriores antes de marcar “'.$item['text'].'”.', 422); }
                        if ($rule['require_note'] && trim($item['notes']) === '') { throw new HttpError('Registre uma observação antes de concluir “'.$item['text'].'”.', 422); }
                        $item['completed_at'] = str_replace(' ', 'T', $now).'Z'; $item['completed_by'] = get_current_user_id();
                        if ($settings['enabled'] && $rule['target_list_id']) { $target = $rule['target_list_id']; }
                    } else { $item['completed_at'] = null; $item['completed_by'] = null; }
                    $events[] = ['kind'=>'checklist_done', 'content'=>($item['done'] ? 'Concluído: ' : 'Reaberto: ').$list['title'].' / '.$item['text'].'.'];
                }
                $item['planned_start_at'] = $settings['enabled'] ? self::iso($cursor) : null;
                if ($settings['enabled'] && $rule['amount']) { $cursor = self::add_time($cursor, $rule, $settings); $has_duration = true; }
                $item['planned_due_at'] = $settings['enabled'] && $rule['amount'] ? self::iso($cursor) : null;
                $item['execution_due_at'] = null;
                if (!$item['done']) { $pending_seen = true; $all_done = false; }
                if ($settings['enabled'] && ((!$item['done'] && $active === null) || ($changed_done && $item['done']))) {
                    if (!$item['started_at']) { $item['started_at'] = str_replace(' ', 'T', $old ? $now : ($old['created_at'] ?? $now)).'Z'; }
                }
                if ($settings['enabled'] && $rule['amount']) {
                    $item['execution_due_at'] = $settings['deadline_mode'] === 'registration' ? $item['planned_due_at']
                        : ($item['started_at'] ? self::iso(self::add_time(new \DateTimeImmutable($item['started_at']), $rule, $settings)) : null);
                }
                if ($settings['enabled'] && !$item['done'] && $active === null) {
                    $active = ['checklist_id'=>$list['id'], 'item_id'=>$item['id'], 'text'=>$item['text'], 'assigned_to'=>$item['assigned_to'], 'due_at'=>$item['execution_due_at'], 'started_at'=>$item['started_at']];
                }
            } unset($item);
        } unset($list);
        $settings['planned_due_at'] = $settings['enabled'] && $has_duration ? self::iso($cursor) : null;
        $settings['active'] = $active;
        return ['checklists'=>$lists, 'workflow'=>$settings, 'target_list_id'=>$target, 'events'=>$events,
            'step_due_at'=>$active && $active['due_at'] ? (new \DateTimeImmutable($active['due_at']))->format('Y-m-d H:i:s') : null,
            'complete'=>$settings['enabled'] && $settings['complete_card'] ? ($count > 0 && $all_done) : null];
    }

    public static function references_list(array $lists, int $id): bool {
        foreach ($lists as $list) { foreach ($list['items'] ?? [] as $item) { if ((int)($item['rule']['target_list_id'] ?? 0) === $id) { return true; } } }
        return false;
    }

    public static function deadline(array $card): ?string {
        $step = $card['step_due_at'] ?? null; $final = $card['due_at'] ?? null;
        return $step && (!$final || $step < $final) ? $step : $final;
    }
    public static function deadline_sql(string $alias = ''): string {
        $prefix = $alias === 'c' ? 'c.' : '';
        $step = $prefix.'step_due_at'; $final = $prefix.'due_at';
        return "(CASE WHEN $step IS NOT NULL AND ($final IS NULL OR $step<$final) THEN $step ELSE $final END)";
    }
    public static function due_hash(array $card): string {
        $key = self::deadline($card).':'.$card['assigned_to'];
        if (!empty($card['step_due_at']) && self::deadline($card) === $card['step_due_at']) {
            $flow = Store::json($card['workflow_json'] ?? '');
            $key .= ':'.($flow['active']['checklist_id'] ?? '').'/'.($flow['active']['item_id'] ?? '');
        }
        return md5($key);
    }
}
