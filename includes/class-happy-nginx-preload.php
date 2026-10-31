<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Happy_Nginx_Preload')) :
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

        if (!empty($sitemap_info['url'])) {
            $urls = $this->parse_sitemap($sitemap_info['url'], $limit);
        }

        // Sempre incluir a Homepage
        $urls[] = home_url('/');

        // Se o sitemap retornou poucas URLs ou falhou, complementa com posts e páginas reais
        if (count($urls) < $limit) {
            $remaining = $limit - count($urls);
            $posts = get_posts([
                'numberposts' => $remaining,
                'post_status' => 'publish',
                'post_type'   => 'any',
            ]);

            if (!empty($posts)) {
                foreach ($posts as $p) {
                    $link = get_permalink($p->ID);
                    if ($link) {
                        $urls[] = $link;
                    }
                }
            }

            $categories = get_categories(['number' => 50, 'hide_empty' => false]);
            if (!empty($categories) && !is_wp_error($categories)) {
                foreach ($categories as $cat) {
                    $cat_link = get_term_link($cat);
                    if (!is_wp_error($cat_link)) {
                        $urls[] = $cat_link;
                    }
                }
            }
        }

        $clean_urls = array_values(array_unique(array_filter($urls)));
        return array_slice($clean_urls, 0, $limit);
    }

    /**
     * Faz o parse do XML de Sitemap com suporte a namespaces e regex fallback
     */
    public function parse_sitemap($url, $limit = 100, $depth = 0) {
        if ($depth > 2 || $limit <= 0) {
            return [];
        }

        $response = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => false,
            'headers'   => [
                'User-Agent' => 'HappyNginx Preloader/1.0',
            ]
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        $xml_content = wp_remote_retrieve_body($response);
        if (empty($xml_content)) {
            return [];
        }

        $urls = [];

        // Suporte a XML com namespaces e simplexml
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_content);
        if ($xml !== false) {
            // Se possui namespaces (ex: sitemap padrão http://www.sitemaps.org/schemas/sitemap/0.9)
            $namespaces = $xml->getNamespaces(true);
            if (!empty($namespaces)) {
                $ns_prefix = key($namespaces);
                $xml->registerXPathNamespace('s', current($namespaces));

                // 1. Verificar sitemap index
                $sitemaps = $xml->xpath('//s:sitemap/s:loc');
                if (!empty($sitemaps)) {
                    foreach ($sitemaps as $loc) {
                        $sub_url = trim((string)$loc);
                        if (!empty($sub_url)) {
                            $sub_urls = $this->parse_sitemap($sub_url, $limit - count($urls), $depth + 1);
                            $urls = array_merge($urls, $sub_urls);
                            if (count($urls) >= $limit) {
                                break;
                            }
                        }
                    }
                }

                // 2. Verificar urlset
                $url_nodes = $xml->xpath('//s:url/s:loc');
                if (!empty($url_nodes)) {
                    foreach ($url_nodes as $loc) {
                        $urls[] = trim((string)$loc);
                        if (count($urls) >= $limit) {
                            break;
                        }
                    }
                }
            } else {
                // XML sem namespace explícito
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
            }
            libxml_clear_errors();
        }

        // Fallback via Regex se o XML falhar no parser ou não retornar URLs
        if (empty($urls)) {
            // Se encontrar tags de sub-sitemaps
            if (preg_match_all('/<sitemap\b[^>]*>.*?<loc>(.*?)<\/loc>.*?<\/sitemap>/is', $xml_content, $sub_matches)) {
                foreach ($sub_matches[1] as $sub_url) {
                    $sub_url = trim($sub_url);
                    $sub_urls = $this->parse_sitemap($sub_url, $limit - count($urls), $depth + 1);
                    $urls = array_merge($urls, $sub_urls);
                    if (count($urls) >= $limit) {
                        break;
                    }
                }
            }

            // Se encontrar tags <loc> de URLs
            if (empty($urls) && preg_match_all('/<loc>\s*(https?:\/\/[^\s<]+)\s*<\/loc>/i', $xml_content, $matches)) {
                foreach ($matches[1] as $matched_url) {
                    // Ignora se terminar com .xml (que seria outro sitemap)
                    if (!preg_match('/\.xml(\?.*)?$/i', $matched_url)) {
                        $urls[] = trim($matched_url);
                        if (count($urls) >= $limit) {
                            break;
                        }
                    }
                }
            }
        }

        return array_unique(array_filter($urls));
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
                'timeout'    => 10,
                'sslverify'  => false,
                'user-agent' => 'HappyNginx-Preloader/1.0',
                'cookies'    => [], // Garante explicitamente que nenhuma sessão de admin seja enviada
                'headers'    => [
                    'Accept-Encoding' => 'gzip, deflate',
                    'Cache-Control'   => 'max-age=0',
                ]
            ]);

            if (is_wp_error($response)) {
                $failed++;
            } else {
                $code = (int) wp_remote_retrieve_response_code($response);
                // Aceita códigos 2xx e 3xx (redirecionamentos como barra final /) como sucesso
                if ($code >= 200 && $code < 400) {
                    $warmed++;
                } else {
                    $failed++;
                }
            }

            // Pequeno intervalo para não sobrecarregar o servidor
            usleep(50000); // 0.05 segundo
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

endif;
