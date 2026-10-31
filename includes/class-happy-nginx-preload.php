<?php
if (!defined('ABSPATH')) {
    exit;
}

class Happy_Nginx_Preload {
    private $settings;
    private $cache;

    public function __construct(Happy_Nginx_Settings $settings, Happy_Nginx_Cache $cache) {
        $this->settings = $settings;
        $this->cache    = $cache;

        // Cron agendado
        add_action('happy_nginx_scheduled_preload', [$this, 'run_preload']);

        // Ajax para preload manual a partir do Admin / Admin Bar
        add_action('wp_ajax_happy_nginx_preload_cache', [$this, 'ajax_preload_cache']);
    }

    /**
     * Retorna a URL do sitemap a ser utilizado
     */
    public function get_sitemap_info() {
        $custom = trim($this->settings->get('custom_sitemap_url', ''));
        if (!empty($custom)) {
            return [
                'url'    => $custom,
                'source' => 'personalizado',
                'exists' => true,
            ];
        }

        // Testar sitemap do Yoast SEO
        $yoast_url = home_url('/sitemap_index.xml');
        // Testar sitemap nativo do WP (desde WP 5.5)
        $wp_url = home_url('/wp-sitemap.xml');

        if (defined('WPSEO_VERSION') || $this->check_url_exists($yoast_url)) {
            return [
                'url'    => $yoast_url,
                'source' => 'Yoast SEO',
                'exists' => true,
            ];
        }

        if ($this->check_url_exists($wp_url)) {
            return [
                'url'    => $wp_url,
                'source' => 'WordPress Nativo',
                'exists' => true,
            ];
        }

        // Se nenhum sitemap for detectado
        return [
            'url'    => '',
            'source' => 'nenhum',
            'exists' => false,
            'notice' => 'Nenhum Sitemap XML detectado! Para que o pré-carregamento automático encontre todas as suas páginas com máxima eficiência, recomendamos instalar e ativar o plugin Yoast SEO.',
        ];
    }

    private function check_url_exists($url) {
        $response = wp_remote_head($url, [
            'timeout'   => 3,
            'sslverify' => false,
        ]);
        if (is_wp_error($response)) {
            return false;
        }
        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 400);
    }

    /**
     * Coleta as URLs a partir do sitemap XML ou fallback
     */
    public function collect_urls($limit = 100) {
        $urls = [];
        $sitemap_info = $this->get_sitemap_info();

        if ($sitemap_info['exists'] && !empty($sitemap_info['url'])) {
            $urls = $this->parse_sitemap($sitemap_info['url'], $limit);
        }

        // Fallback para posts e páginas do WordPress caso o sitemap não retorne nada
        if (empty($urls)) {
            $urls[] = home_url('/');

            // Posts e Páginas recentes
            $posts = get_posts([
                'numberposts' => $limit,
                'post_status' => 'publish',
                'post_type'   => ['post', 'page'],
            ]);

            foreach ($posts as $p) {
                $urls[] = get_permalink($p->ID);
            }

            // Categorias
            $categories = get_categories(['number' => 20]);
            foreach ($categories as $cat) {
                $urls[] = get_term_link($cat);
            }
        }

        return array_unique(array_filter($urls));
    }

    /**
     * Faz o parse do XML de Sitemap
     */
    public function parse_sitemap($url, $limit = 100, $depth = 0) {
        if ($depth > 2) { // Evitar recursão infinita
            return [];
        }

        $response = wp_remote_get($url, [
            'timeout'   => 10,
            'sslverify' => false,
            'headers'   => [
                'User-Agent' => 'HappyNginx Preloader',
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $xml_content = wp_remote_retrieve_body($response);
        if (empty($xml_content)) {
            return [];
        }

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);
        if ($xml === false) {
            libxml_clear_errors();
            return [];
        }

        $urls = [];

        // Se for sitemap index (contém <sitemap>)
        if (isset($xml->sitemap)) {
            foreach ($xml->sitemap as $sitemap_item) {
                if (isset($sitemap_item->loc)) {
                    $sub_urls = $this->parse_sitemap((string)$sitemap_item->loc, $limit - count($urls), $depth + 1);
                    $urls = array_merge($urls, $sub_urls);
                    if (count($urls) >= $limit) {
                        break;
                    }
                }
            }
        }

        // Se for urlset (contém <url>)
        if (isset($xml->url)) {
            foreach ($xml->url as $url_item) {
                if (isset($url_item->loc)) {
                    $urls[] = (string)$url_item->loc;
                    if (count($urls) >= $limit) {
                        break;
                    }
                }
            }
        }

        return $urls;
    }

    /**
     * Executa o pré-carregamento (Preload) acessando cada URL via GET sem cookies para gerar o cache
     */
    public function run_preload() {
        $limit = (int) $this->settings->get('preload_limit', 100);
        $urls  = $this->collect_urls($limit);

        $warmed = 0;
        $failed = 0;

        foreach ($urls as $url) {
            $response = wp_remote_get($url, [
                'timeout'    => 5,
                'sslverify'  => false,
                'user-agent' => 'HappyNginx-Cache-Preloader/1.0',
                // Sem enviar cookies de admin para que gere o cache público
                'headers'    => [
                    'Accept-Encoding' => 'gzip, deflate',
                ]
            ]);

            if (is_wp_error($response)) {
                $failed++;
            } else {
                $code = wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 400) {
                    $warmed++;
                } else {
                    $failed++;
                }
            }

            // Pequeno intervalo para não sobrecarregar o servidor
            usleep(100000); // 0.1 segundo
        }

        $stats = [
            'last_run' => current_time('mysql'),
            'total'    => count($urls),
            'warmed'   => $warmed,
            'failed'   => $failed,
        ];
        update_option('happy_nginx_preload_stats', $stats);

        return $stats;
    }

    /**
     * Endpoint Ajax para preload
     */
    public function ajax_preload_cache() {
        check_ajax_referer('happy_nginx_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permissão negada.']);
        }

        $stats = $this->run_preload();

        wp_send_json_success([
            'message' => sprintf(
                'Preload concluído com sucesso! %d de %d páginas foram pré-carregadas no Nginx.',
                $stats['warmed'],
                $stats['total']
            ),
            'stats'   => $stats,
        ]);
    }
}
