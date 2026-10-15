<?php
namespace Shalom\Visitas;
defined('ABSPATH') || exit;

final class Access {
    public static function capabilities(): array {
        return [
            'access' => 'Entrar no portal', 'view_all_boards' => 'Acessar todos os quadros privados',
            'create_boards' => 'Criar quadros', 'manage_boards' => 'Gerenciar listas, modelos e membros',
            'create_cards' => 'Cadastrar visitantes e cartões', 'edit_cards' => 'Editar e mover cartões',
            'view_all_cards' => 'Ver todos os cartões dos quadros permitidos', 'delete_cards' => 'Excluir cartões',
            'comment' => 'Registrar interações e comentários', 'notify' => 'Enviar comunicações aos visitantes',
            'manage_users' => 'Gerenciar a equipe e seus perfis', 'view_logs' => 'Consultar notificações',
        ];
    }

    public static function role_defaults(): array {
        $all = array_keys(self::capabilities());
        return [
            'visitas_pastor' => ['name'=>'Pastor — Visitas','caps'=>$all],
            'visitas_coordenacao' => ['name'=>'Coordenação — Visitas','caps'=>array_diff($all, ['view_all_boards'])],
            'visitas_recepcao' => ['name'=>'Recepção — Visitas','caps'=>['access','create_cards','edit_cards','view_all_cards','comment']],
            'visitas_acompanhamento' => ['name'=>'Acompanhamento — Visitas','caps'=>['access','edit_cards','comment','notify']],
            'visitas_observador' => ['name'=>'Observador — Visitas','caps'=>['access','view_all_cards']],
        ];
    }

    public static function install(): void {
        foreach (self::role_defaults() as $key => $data) {
            if (!get_role($key)) {
                $caps = ['read'=>true];
                foreach ($data['caps'] as $cap) { $caps['visitas_' . $cap] = true; }
                add_role($key, $data['name'], $caps);
            }
        }
        $admin = get_role('administrator');
        if ($admin) { foreach (self::capabilities() as $key => $name) { $admin->add_cap('visitas_' . $key); } }
    }

    public static function admin(?\WP_User $user = null): bool {
        $user = $user ?: wp_get_current_user();
        return in_array('administrator', $user->roles, true) || (is_multisite() && is_super_admin($user->ID));
    }

    public static function can(string $cap, ?int $user_id = null): bool {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        return $user && (self::admin($user) || user_can($user, 'visitas_' . $cap));
    }

    public static function require_cap(string $cap): void {
        if (!self::can($cap)) { throw new HttpError('Seu perfil não permite esta ação.', 403); }
    }

    public static function boot(): void {
        add_action('admin_init', static function () {
            if (!self::admin() && !wp_doing_ajax() && !wp_doing_cron()) {
                wp_safe_redirect(Portal::url()); exit;
            }
        }, 0);
        add_filter('show_admin_bar', static fn($show) => self::admin() ? $show : false);
        add_filter('login_redirect', static fn($to, $requested, $user) => $user instanceof \WP_User && !self::admin($user) ? Portal::url() : $to, 10, 3);
        add_filter('authenticate', static function ($user) {
            if ($user instanceof \WP_User && !self::can('access', (int) $user->ID)) {
                return new \WP_Error('visitas_denied', 'Seu usuário não tem acesso ao portal. Procure a administração.');
            }
            return $user;
        }, 99);
    }

    public static function roles(): array {
        $roles = [];
        foreach (wp_roles()->roles as $key => $role) {
            if (!str_starts_with($key, 'visitas_')) { continue; }
            $caps = [];
            foreach (self::capabilities() as $cap => $label) { $caps[$cap] = !empty($role['capabilities']['visitas_' . $cap]); }
            $roles[] = ['key'=>$key, 'name'=>$role['name'], 'caps'=>$caps];
        }
        return $roles;
    }

    public static function board(int $id, bool $manage = false): array {
        $b = Store::row('boards', $id);
        if (!$b) { throw new HttpError('Quadro não encontrado.', 404); }
        $member = Store::member($id, get_current_user_id());
        $allowed = self::admin() || self::can('view_all_boards') || (int) $b['created_by'] === get_current_user_id() || $b['visibility'] === 'team' || $member;
        if (!$allowed) { throw new HttpError('Você não tem acesso a este quadro.', 403); }
        if ($manage && !(self::admin() || (self::can('manage_boards') && (!$member || $member['role'] !== 'viewer')) || ($member && $member['role'] === 'manager'))) {
            throw new HttpError('Você não pode gerenciar este quadro.', 403);
        }
        return $b;
    }

    public static function board_write(int $id, string $cap): array {
        self::require_cap($cap);
        $b = self::board($id);
        $m = Store::member($id, get_current_user_id());
        if ($m && $m['role'] === 'viewer' && !self::admin()) { throw new HttpError('Seu acesso a este quadro é de leitura.', 403); }
        return $b;
    }

    public static function sees_card(array $card): bool {
        return self::can('view_all_cards') || (int) $card['assigned_to'] === get_current_user_id() || (int) $card['created_by'] === get_current_user_id();
    }

    public static function card(int $id, ?string $cap = null): array {
        $c = Store::row('cards', $id);
        if (!$c) { throw new HttpError('Cartão não encontrado.', 404); }
        if ($cap) { self::board_write((int) $c['board_id'], $cap); } else { self::board((int) $c['board_id']); }
        if (!self::sees_card($c)) { throw new HttpError('Você só pode acessar cartões atribuídos a você ou criados por você.', 403); }
        return $c;
    }
}
