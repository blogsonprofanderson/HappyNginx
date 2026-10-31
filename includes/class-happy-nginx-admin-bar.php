<?php
if (!defined('ABSPATH')) {
    exit;
}

class Happy_Nginx_Admin_Bar {
    private $settings;
    private $cache;
    private $preload;

    public function __construct(Happy_Nginx_Settings $settings, Happy_Nginx_Cache $cache, Happy_Nginx_Preload $preload) {
        $this->settings = $settings;
        $this->cache    = $cache;
        $this->preload  = $preload;

        add_action('admin_bar_menu', [$this, 'add_admin_bar_items'], 100);
        add_action('wp_ajax_happy_nginx_purge_all', [$this, 'ajax_purge_all']);
        add_action('wp_ajax_happy_nginx_purge_current', [$this, 'ajax_purge_current']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_admin_bar_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_bar_assets']);
    }

    public function add_admin_bar_items(WP_Admin_Bar $admin_bar) {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Nó principal do HappyNginx
        $admin_bar->add_node([
            'id'    => 'happy-nginx',
            'title' => '<span class="ab-icon dashicons dashicons-performance" style="margin-top:2px;"></span> HappyNginx',
            'href'  => admin_url('options-general.php?page=happy-nginx'),
            'meta'  => ['title' => 'Gerenciamento de Cache HappyNginx (HestiaCP)'],
        ]);

        // Limpar Todo o Cache
        $admin_bar->add_node([
            'id'     => 'happy-nginx-purge-all',
            'parent' => 'happy-nginx',
            'title'  => '⚡ Limpar Todo o Cache Nginx',
            'href'   => '#',
            'meta'   => [
                'class'   => 'happy-nginx-purge-all-btn',
                'onclick' => 'happyNginxPurgeAll(event);',
            ],
        ]);

        // Se estiver visualizando uma página pública, opção de limpar a página atual
        if (!is_admin()) {
            $admin_bar->add_node([
                'id'     => 'happy-nginx-purge-current',
                'parent' => 'happy-nginx',
                'title'  => '📄 Limpar Cache Desta Página',
                'href'   => '#',
                'meta'   => [
                    'class'   => 'happy-nginx-purge-current-btn',
                    'onclick' => 'happyNginxPurgeCurrent(event);',
                ],
            ]);
        }

        // Pré-carregar Cache (Preload)
        $admin_bar->add_node([
            'id'     => 'happy-nginx-preload',
            'parent' => 'happy-nginx',
            'title'  => '🔥 Pré-carregar Cache (Preload)',
            'href'   => '#',
            'meta'   => [
                'class'   => 'happy-nginx-preload-btn',
                'onclick' => 'happyNginxPreload(event);',
            ],
        ]);

        // Configurações
        $admin_bar->add_node([
            'id'     => 'happy-nginx-settings-link',
            'parent' => 'happy-nginx',
            'title'  => '⚙️ Configurações',
            'href'   => admin_url('options-general.php?page=happy-nginx'),
        ]);
    }

    public function enqueue_admin_bar_assets() {
        if (!current_user_can('manage_options')) {
            return;
        }

        wp_enqueue_script(
            'happy-nginx-admin-bar',
            HAPPY_NGINX_URL . 'assets/js/admin-bar.js',
            ['jquery'],
            HAPPY_NGINX_VERSION,
            true
        );

        wp_localize_script('happy-nginx-admin-bar', 'happyNginxData', [
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('happy_nginx_admin_nonce'),
            'current_url' => !is_admin() ? home_url(add_query_arg([], $GLOBALS['wp']->request)) : '',
        ]);
    }

    public function ajax_purge_all() {
        check_ajax_referer('happy_nginx_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
        }

        $res = $this->cache->purge_all();

        wp_send_json_success([
            'message' => 'Cache do Nginx limpo com sucesso!',
            'result'  => $res,
        ]);
    }

    public function ajax_purge_current() {
        check_ajax_referer('happy_nginx_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
        }

        $url = isset($_POST['url']) ? esc_url_raw($_POST['url']) : '';
        if (empty($url)) {
            $url = wp_get_referer();
        }

        $res = $this->cache->purge_urls([$url]);

        wp_send_json_success([
            'message' => 'Cache da página atual limpo com sucesso!',
            'url'     => $url,
            'result'  => $res,
        ]);
    }
}
