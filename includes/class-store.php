<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class HttpError extends \RuntimeException {
    public int $status;
    public function __construct(string $message, int $status = 400) { parent::__construct($message); $this->status = $status; }
}

final class Store {
    public static function table(string $name): string {
        global $wpdb;
        if (!in_array($name, ['boards','lists','cards','members','activity','notifications'], true)) { throw new HttpError('Tabela inválida.', 500); }
        return $wpdb->prefix . 'visitas_' . $name;
    }

    public static function row(string $table, int $id): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table($table) . ' WHERE id=%d', $id), ARRAY_A) ?: null;
    }

    public static function insert(string $table, array $data): int {
        global $wpdb;
        if ($wpdb->insert(self::table($table), $data) === false) { throw new HttpError('Não foi possível salvar. Tente novamente.', 500); }
        return (int) $wpdb->insert_id;
    }

    public static function update(string $table, array $data, array $where): int {
        global $wpdb;
        $r = $wpdb->update(self::table($table), $data, $where);
        if ($r === false) { throw new HttpError('Não foi possível salvar. Tente novamente.', 500); }
        return $r;
    }

    public static function json($value): array {
        return is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
    }

    public static function name($value): string {
        $name = sanitize_text_field(substr((string) $value, 0, 190));
        if ($name === '') { throw new HttpError('Informe o nome.', 422); }
        return $name;
    }

    public static function transaction(callable $fn) {
        global $wpdb;
        $wpdb->query('START TRANSACTION');
        try { $result = $fn(); $wpdb->query('COMMIT'); return $result; }
        catch (\Throwable $e) { $wpdb->query('ROLLBACK'); throw $e; }
    }

    public static function member(int $board, int $user): ?array {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table('members') . ' WHERE board_id=%d AND user_id=%d', $board, $user), ARRAY_A) ?: null;
    }

    public static function lists(int $board): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . self::table('lists') . ' WHERE board_id=%d ORDER BY position,id', $board), ARRAY_A);
    }

    public static function create_board(array $data): int {
        return self::transaction(static function () use ($data) {
            $now = current_time('mysql', true);
            $seed = !empty($data['seed']);
            $templates = $seed ? [['id'=>wp_generate_uuid4(), 'title'=>'Primeiros passos', 'items'=>[
                ['id'=>wp_generate_uuid4(),'text'=>'Enviar uma mensagem de boas-vindas'],
                ['id'=>wp_generate_uuid4(),'text'=>'Fazer o primeiro contato'],
                ['id'=>wp_generate_uuid4(),'text'=>'Convidar para um novo encontro'],
            ]]] : [];
            $id = self::insert('boards', [
                'name'=>self::name($data['name'] ?? ''), 'description'=>sanitize_textarea_field($data['description'] ?? ''),
                'color'=>sanitize_hex_color($data['color'] ?? '') ?: '#001BE1',
                'visibility'=>($data['visibility'] ?? '') === 'private' ? 'private' : 'team',
                'initial_list_id'=>0, 'schema_json'=>'[]', 'templates_json'=>wp_json_encode($templates),
                'created_by'=>get_current_user_id(), 'created_at'=>$now, 'updated_at'=>$now,
            ]);
            $names = $seed ? ['Novos visitantes','Primeiro contato','Em acompanhamento','Conectados'] : ['Novos visitantes'];
            $colors = ['#001BE1','#D4972A','#8052D6','#16826B'];
            foreach ($names as $i => $name) {
                $list = self::insert('lists', ['board_id'=>$id, 'name'=>$name, 'color'=>$colors[$i], 'position'=>$i]);
                if ($i === 0) { self::update('boards', ['initial_list_id'=>$list], ['id'=>$id]); }
            }
            return $id;
        });
    }

    public static function board_data(array $b): array {
        global $wpdb;
        $b['fields'] = self::json($b['schema_json']);
        $b['templates'] = self::json($b['templates_json']);
        unset($b['schema_json'], $b['templates_json']);
        $b['lists'] = self::lists((int) $b['id']);
        $b['can_manage'] = false;
        try { Access::board((int) $b['id'], true); $b['can_manage'] = true; } catch (HttpError $e) {}
        $m = self::member((int) $b['id'], get_current_user_id());
        $b['read_only'] = !Access::admin() && $m && $m['role'] === 'viewer';
        if ($b['can_manage']) { $b['members'] = $wpdb->get_results($wpdb->prepare('SELECT user_id,role FROM ' . self::table('members') . ' WHERE board_id=%d', $b['id']), ARRAY_A); }
        return $b;
    }

    public static function schema(array $fields): array {
        $clean = []; $ids = [];
        foreach (array_slice($fields, 0, 40) as $f) {
            $type = $f['type'] ?? 'text';
            if (!in_array($type, ['text','textarea','number','date','checkbox','select'], true)) { throw new HttpError('Tipo de atributo inválido.', 422); }
            $id = sanitize_key($f['id'] ?? wp_generate_uuid4());
            if (!$id || isset($ids[$id])) { throw new HttpError('Os atributos precisam de identificadores únicos.', 422); }
            $ids[$id] = true;
            $options = array_values(array_unique(array_filter(array_map('sanitize_text_field', array_slice((array) ($f['options'] ?? []), 0, 60)))));
            if ($type === 'select' && !$options) { throw new HttpError('Informe ao menos uma opção para o atributo de seleção.', 422); }
            $clean[] = ['id'=>$id,'label'=>self::name($f['label'] ?? ''),'type'=>$type,'required'=>!empty($f['required']),'options'=>$options];
        }
        return $clean;
    }

    public static function checklists(array $lists, bool $template = false): array {
        $clean = []; $ids = [];
        foreach (array_slice($lists, 0, 20) as $list) {
            $id = sanitize_key($list['id'] ?? wp_generate_uuid4());
            if (!$id || isset($ids[$id])) { throw new HttpError('Os checklists precisam de identificadores únicos.', 422); }
            $ids[$id] = true; $items = []; $item_ids = [];
            foreach (array_slice((array) ($list['items'] ?? []), 0, 100) as $item) {
                $iid = sanitize_key($item['id'] ?? wp_generate_uuid4());
                if (!$iid || isset($item_ids[$iid])) { throw new HttpError('Os itens precisam de identificadores únicos.', 422); }
                $item_ids[$iid] = true;
                $items[] = ['id'=>$iid,'text'=>self::name($item['text'] ?? ''),'done'=>$template ? false : !empty($item['done'])];
            }
            $clean[] = ['id'=>$id,'title'=>self::name($list['title'] ?? ''),'items'=>$items];
        }
        return $clean;
    }

    public static function attributes(array $input, array $schema): array {
        $out = [];
        foreach ($schema as $field) {
            $v = $input[$field['id']] ?? '';
            if (!is_scalar($v) && $v !== null) { throw new HttpError('Valor inválido em ' . $field['label'] . '.', 422); }
            if ($field['type'] === 'checkbox') { $v = rest_sanitize_boolean($v); }
            elseif ($field['type'] === 'number') {
                if ($v !== '' && !is_numeric($v)) { throw new HttpError('Informe um número em ' . $field['label'] . '.', 422); }
                $v = $v === '' ? '' : (float) $v;
            } elseif ($field['type'] === 'date') { $v = self::date((string) $v); }
            else { $v = sanitize_textarea_field(substr((string) $v, 0, 4000)); }
            if ($field['type'] === 'select' && $v !== '' && !in_array($v, $field['options'], true)) { throw new HttpError('Opção inválida em ' . $field['label'] . '.', 422); }
            if ($field['required'] && ($v === '' || $v === null || $v === false)) { throw new HttpError('Preencha ' . $field['label'] . '.', 422); }
            $out[$field['id']] = $v;
        }
        return $out;
    }

    public static function date(string $value): ?string {
        if ($value === '') { return null; }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) { throw new HttpError('Data inválida. Use ano-mês-dia.', 422); }
        return $value;
    }

    public static function datetime(string $value): ?string {
        if ($value === '') { return null; }
        try { $d = new \DateTimeImmutable($value); }
        catch (\Exception $e) { throw new HttpError('Data e hora inválidas.', 422); }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value)) { throw new HttpError('Data e hora inválidas.', 422); }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public static function phone(string $value): string {
        if (!$value) { return ''; }
        $number = preg_replace('/\D+/', '', $value);
        if (!str_starts_with(trim($value), '+') && in_array(strlen($number), [10,11], true)) { $number = '55' . $number; }
        if (!preg_match('/^[1-9][0-9]{7,14}$/', $number)) { throw new HttpError('Informe um telefone válido com DDD ou código do país.', 422); }
        return '+' . $number;
    }

    public static function card_data(array $c): array {
        foreach (['labels','attributes','checklists'] as $key) { $c[$key] = self::json($c[$key . '_json']); unset($c[$key . '_json']); }
        foreach (['start_at','due_at','created_at','updated_at'] as $key) { if ($c[$key]) { $c[$key] = str_replace(' ','T',$c[$key]) . 'Z'; } }
        return $c;
    }

    public static function save_card(array $input, ?array $old = null): array {
        global $wpdb;
        $b = Access::board_write((int) ($old['board_id'] ?? $input['board_id'] ?? 0), $old ? 'edit_cards' : 'create_cards');
        $board_id = (int) $b['id'];
        $data = $old ? array_intersect_key($old, array_flip(['title','email','phone','birthday','visit_date','start_at','due_at','completed','archived','assigned_to','description','source','address','consent_email','consent_sms','labels_json','attributes_json','checklists_json','list_id','position'])) : [
            'list_id'=>(int) $b['initial_list_id'], 'position'=>1000, 'email'=>'', 'phone'=>'', 'birthday'=>null,
            'visit_date'=>current_time('Y-m-d'), 'start_at'=>null, 'due_at'=>null, 'completed'=>0, 'archived'=>0,
            'assigned_to'=>0, 'description'=>'', 'source'=>'', 'address'=>'', 'consent_email'=>0, 'consent_sms'=>0,
            'labels_json'=>'[]', 'attributes_json'=>'{}', 'checklists_json'=>wp_json_encode(self::checklists(self::json($b['templates_json']))),
        ];
        if (!$old || array_key_exists('title', $input)) { $data['title'] = self::name($input['title'] ?? ''); }
        foreach (['email','phone','source','description','address'] as $k) {
            if (!array_key_exists($k, $input)) { continue; }
            if ($k === 'email') {
                if ($input[$k] && !is_email($input[$k])) { throw new HttpError('E-mail inválido.', 422); }
                $data[$k] = strtolower(sanitize_email($input[$k]));
            } elseif ($k === 'phone') { $data[$k] = self::phone((string) $input[$k]); }
            else { $data[$k] = sanitize_textarea_field(substr((string) $input[$k], 0, $k === 'source' ? 190 : 20000)); }
        }
        foreach (['birthday','visit_date'] as $k) { if (array_key_exists($k,$input)) { $data[$k] = self::date((string) $input[$k]); } }
        foreach (['start_at','due_at'] as $k) { if (array_key_exists($k,$input)) { $data[$k] = self::datetime((string) $input[$k]); } }
        if ($data['start_at'] && $data['due_at'] && $data['due_at'] < $data['start_at']) { throw new HttpError('O prazo não pode ser anterior à data inicial.', 422); }
        foreach (['completed','archived','consent_email','consent_sms'] as $k) { if (array_key_exists($k,$input)) { $data[$k] = (int) rest_sanitize_boolean($input[$k]); } }
        if (array_key_exists('assigned_to',$input)) {
            $uid = absint($input['assigned_to']);
            if ($uid) {
                if (!self::can_assign($board_id, $uid)) { throw new HttpError('O responsável precisa ter acesso de edição ao quadro.', 422); }
            }
            if (!Access::can('view_all_cards') && !Access::can('manage_boards') && !Access::admin() && $uid && $uid !== get_current_user_id()) {
                throw new HttpError('Seu perfil só permite atribuir cartões a você.', 403);
            }
            $data['assigned_to'] = $uid;
        }
        if ($old && array_key_exists('list_id',$input)) { $data['list_id'] = absint($input['list_id']); }
        $list = self::row('lists', (int) $data['list_id']);
        if (!$list || (int) $list['board_id'] !== $board_id) { throw new HttpError('A lista não pertence ao quadro.', 422); }
        if (array_key_exists('labels',$input)) { $data['labels_json'] = wp_json_encode(array_values(array_unique(array_filter(array_map(static fn($s)=>sanitize_text_field(substr((string)$s,0,40)), array_slice((array)$input['labels'],0,12)))))); }
        if (!$old || array_key_exists('attributes',$input)) { $data['attributes_json'] = wp_json_encode(self::attributes((array)($input['attributes'] ?? []), self::json($b['schema_json']))); }
        if (array_key_exists('checklists',$input)) { $data['checklists_json'] = wp_json_encode(self::checklists((array)$input['checklists'])); }
        if (!$old || (int) $data['list_id'] !== (int) $old['list_id'] || isset($input['before_id'])) {
            $before = !empty($input['before_id']) ? self::row('cards', absint($input['before_id'])) : null;
            if ($before && ((int)$before['board_id'] !== $board_id || (int)$before['list_id'] !== (int)$data['list_id'] || !Access::sees_card($before) || ($old && $before['id'] === $old['id']))) {
                throw new HttpError('Posição de destino inválida.', 422);
            }
            // Integer positions are shifted only at the destination; no hidden-card identifiers are exposed.
            $data['position'] = $before ? (int)$before['position'] : (int)$wpdb->get_var($wpdb->prepare('SELECT COALESCE(MAX(position),0)+1000 FROM ' . self::table('cards') . ' WHERE list_id=%d', $data['list_id']));
        }
        $now = current_time('mysql', true);
        $id = self::transaction(static function () use ($old,$input,$data,$now,$board_id) {
            global $wpdb;
            if ($old) {
                if (!isset($input['version']) || (int)$input['version'] !== (int)$old['version']) { throw new HttpError('Este cartão foi alterado. Feche e abra novamente para carregar a versão atual.', 409); }
                $changed = self::update('cards', array_merge($data, ['version'=>(int)$old['version']+1,'updated_at'=>$now]), ['id'=>(int)$old['id'],'version'=>(int)$input['version']]);
                if (!$changed) { throw new HttpError('O cartão foi alterado por outra pessoa. Atualize para continuar.', 409); }
                $id = (int)$old['id'];
            } else {
                $id = self::insert('cards', array_merge($data, ['board_id'=>$board_id,'version'=>1,'created_by'=>get_current_user_id(),'created_at'=>$now,'updated_at'=>$now]));
            }
            if (!empty($input['before_id'])) {
                $wpdb->query($wpdb->prepare('UPDATE ' . self::table('cards') . ' SET position=position+1 WHERE list_id=%d AND position>=%d AND id<>%d', $data['list_id'], $data['position'], $id));
            }
            self::activity($id, $old ? 'updated' : 'created', $old ? 'Cartão atualizado.' : 'Visitante cadastrado.');
            if ($old && (int)$old['list_id'] !== (int)$data['list_id']) { $l=self::row('lists',(int)$data['list_id']); self::activity($id,'moved','Movido para ' . $l['name'] . '.'); }
            if ($old && ((int)$old['consent_email'] !== (int)$data['consent_email'] || (int)$old['consent_sms'] !== (int)$data['consent_sms'])) { self::activity($id,'consent','Preferências de comunicação atualizadas.'); }
            return $id;
        });
        $new = self::row('cards', $id);
        if (!$old) { Notifications::event('welcome', $new); }
        if ((int)$new['assigned_to'] && (!$old || (int)$old['assigned_to'] !== (int)$new['assigned_to'])) { Notifications::event('assignment',$new); }
        return self::card_data($new);
    }

    public static function can_assign(int $board_id, int $user_id): bool {
        $u = get_userdata($user_id); $b = self::row('boards',$board_id); $m = self::member($board_id,$user_id);
        if (!$u || !$b || !Access::can('access',$user_id) || !Access::can('edit_cards',$user_id)) { return false; }
        if (!Access::admin($u) && $m && $m['role'] === 'viewer') { return false; }
        return Access::admin($u) || Access::can('view_all_boards',$user_id) || $b['visibility'] === 'team' || (int)$b['created_by'] === $user_id || (bool)$m;
    }

    public static function activity(int $id, string $kind, string $content): void {
        self::insert('activity',['card_id'=>$id,'user_id'=>get_current_user_id(),'kind'=>$kind,'content'=>$content,'created_at'=>current_time('mysql',true)]);
    }

    public static function staff(): array {
        $staff = [];
        foreach (get_users(['number'=>-1,'orderby'=>'display_name']) as $u) {
            if (!Access::can('access',(int)$u->ID)) { continue; }
            $staff[]=['id'=>(int)$u->ID,'name'=>$u->display_name,'roles'=>array_values($u->roles)];
        }
        return $staff;
    }
}
