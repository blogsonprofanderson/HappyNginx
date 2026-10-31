<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Happy_Nginx_Cache')) :
class Happy_Nginx_Cache {
    private $settings;

    public function __construct(Happy_Nginx_Settings $settings) {
        $this->settings = $settings;

        // Hooks para Smart Purge ao atualizar conteúdo
        if ($this->settings->get('smart_purge_enabled', 1)) {
            add_action('save_post', [$this, 'on_save_post'], 10, 2);
            add_action('deleted_post', [$this, 'on_delete_post'], 10, 1);
            add_action('edit_terms', [$this, 'on_edit_terms'], 10, 2);
            add_action('switch_theme', [$this, 'purge_all']);
            add_action('activated_plugin', [$this, 'purge_all']);
            add_action('deactivated_plugin', [$this, 'purge_all']);
            add_action('wp_update_nav_menu', [$this, 'purge_all']);
            add_action('customize_save_after', [$this, 'purge_all']);
        }

        if ($this->settings->get('purge_on_comment', 1)) {
            add_action('comment_post', [$this, 'on_comment_change'], 10, 2);
            add_action('edit_comment', [$this, 'on_comment_change'], 10, 2);
            add_action('transition_comment_status', [$this, 'on_comment_status_transition'], 10, 3);
        }

        // Cabeçalhos para WooCommerce e usuários logados
        add_action('template_redirect', [$this, 'send_cache_headers'], 99);
    }

    /**
     * Envia cabeçalhos adequados para controle de cache Nginx
     */
    public function send_cache_headers() {
        if (headers_sent()) {
            return;
        }

        // Bypass para usuários logados se ativado
        if ($this->settings->get('cache_logged_in_bypass', 1) && is_user_logged_in()) {
            header('X-Accel-Expires: 0');
            header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
            return;
        }

        // Bypass para WooCommerce páginas sensíveis
        if ($this->settings->get('cache_woocommerce_bypass', 1) && function_exists('is_woocommerce')) {
            if (is_cart() || is_checkout() || is_account_page()) {
                header('X-Accel-Expires: 0');
                header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
                return;
            }
        }
    }

    /**
     * Adiciona o comentário de timestamp no final do HTML
     */
    public function append_timestamp($html) {
        $timestamp = current_time('Y-m-d H:i:s');
        $timezone = wp_timezone_string();
        $execution_time = 0;
        if (defined('HAPPY_NGINX_START_TIME')) {
            $execution_time = round(microtime(true) - HAPPY_NGINX_START_TIME, 4);
        }

        $comment = sprintf(
            "\n<!-- HappyNginx: Página em cache gerada em %s (%s) | Tempo de geração: %ss -->\n",
            esc_html($timestamp),
            esc_html($timezone),
            esc_html($execution_time)
        );

        // Se encontrar </html>, insere logo antes ou logo depois
        if (stripos($html, '</html>') !== false) {
            $html = str_ireplace('</html>', "</html>\n" . trim($comment), $html);
        } else {
            $html .= $comment;
        }

        return $html;
    }

    /**
     * Limpa todo o cache do Nginx
     */
    public function purge_all() {
        $method = $this->settings->get('purge_method', 'direct');
        $success = false;

        if ($method === 'direct') {
            $cache_path = $this->settings->get('cache_path');
            if (empty($cache_path)) {
                $cache_path = Happy_Nginx_Settings::detect_hestia_cache_path();
            }
            $success = $this->delete_cache_dir_contents($cache_path);

            // Se a exclusão direta falhou por permissões e o diretório não é gravável, tenta também via HTTP PURGE
            if (!$success) {
                $this->http_purge_request(home_url('/*'));
            }
        } else {
            $purge_url = home_url('/*');
            $success = $this->http_purge_request($purge_url);
        }

        // Limpa também cache de transients do WP
        wp_cache_flush();

        do_action('happy_nginx_after_purge_all', $success);

        return $success;
    }

    /**
     * Limpa o cache de URLs específicas ou arquivos correspondentes
     */
    public function purge_urls($urls = []) {
        if (empty($urls)) {
            return false;
        }

        $method = $this->settings->get('purge_method', 'direct');

        if ($method === 'direct') {
            // No Nginx FastCGI Cache, o arquivo de cache tem o nome que é um MD5 da chave
            // (geralmente $scheme$request_method$host$request_uri)
            $cache_path = $this->settings->get('cache_path', '/var/run/nginx-cache');
            return $this->delete_cache_for_urls($cache_path, (array) $urls);
        } else {
            $all_ok = true;
            foreach ((array) $urls as $url) {
                if (!$this->http_purge_request($url)) {
                    $all_ok = false;
                }
            }
            return $all_ok;
        }
    }

    /**
     * Exclui todos os arquivos dentro do diretório de cache
     */
    public function delete_cache_dir_contents($dir) {
        if (!is_dir($dir) || !is_readable($dir)) {
            return false;
        }

        try {
            $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);

            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            return true;
        } catch (Exception $e) {
            error_log('HappyNginx: Erro ao esvaziar pasta de cache: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Localiza e remove arquivos de cache específicos correspondentes às URLs no HestiaCP
     */
    public function delete_cache_for_urls($cache_dir, array $urls) {
        if (!is_dir($cache_dir) || !is_readable($cache_dir)) {
            // Se não puder ler o diretório por permissões, tenta esvaziar se possível ou retorna false
            return false;
        }

        // Possíveis chaves FastCGI comuns no HestiaCP / Nginx
        // Padrão Hestia: fastcgi_cache_key "$scheme$request_method$host$request_uri";
        $schemes = ['http', 'https'];
        $methods = ['GET', 'HEAD'];

        $hashes = [];
        foreach ($urls as $url) {
            $parsed = wp_parse_url($url);
            if (!$parsed || empty($parsed['host'])) {
                continue;
            }
            $host = $parsed['host'];
            $uri = isset($parsed['path']) ? $parsed['path'] : '/';
            if (isset($parsed['query'])) {
                $uri .= '?' . $parsed['query'];
            }

            foreach ($schemes as $scheme) {
                foreach ($methods as $method) {
                    $key = $scheme . $method . $host . $uri;
                    $hashes[] = md5($key);
                }
            }
        }

        if (empty($hashes)) {
            return false;
        }

        try {
            $it = new RecursiveDirectoryIterator($cache_dir, RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::LEAVES_ONLY);

            $deleted = 0;
            foreach ($files as $file) {
                $filename = $file->getFilename();
                if (in_array($filename, $hashes, true)) {
                    @unlink($file->getRealPath());
                    $deleted++;
                }
            }
            return $deleted > 0;
        } catch (Exception $e) {
            error_log('HappyNginx: Erro ao buscar cache de URLs: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Envia requisição HTTP PURGE para a URL
     */
    public function http_purge_request($url) {
        $response = wp_remote_request($url, [
            'method'    => 'PURGE',
            'sslverify' => false,
            'timeout'   => 5,
            'headers'   => [
                'X-Purge-Method' => 'HappyNginx',
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('HappyNginx HTTP PURGE Error: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        return ($code >= 200 && $code < 300) || $code === 404;
    }

    /**
     * Hook chamado ao salvar/atualizar post
     */
    public function on_save_post($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if ($post->post_status !== 'publish') {
            return;
        }

        $urls = $this->get_related_urls_for_post($post_id);
        $this->purge_urls($urls);

        // Se a opção de purgar tudo na atualização de posts estiver forçada ou se for homepage estática
        $frontpage_id = (int) get_option('page_on_front');
        if ($post_id === $frontpage_id) {
            $this->purge_all();
        }
    }

    /**
     * Hook chamado ao excluir post
     */
    public function on_delete_post($post_id) {
        $urls = $this->get_related_urls_for_post($post_id);
        $this->purge_urls($urls);
    }

    /**
     * Hook chamado ao editar termos de taxonomia (categorias, tags)
     */
    public function on_edit_terms($term_id, $taxonomy) {
        $term_link = get_term_link($term_id, $taxonomy);
        $urls = [home_url('/')];
        if (!is_wp_error($term_link)) {
            $urls[] = $term_link;
        }
        $this->purge_urls($urls);
    }

    /**
     * Hook chamado ao adicionar comentário
     */
    public function on_comment_change($comment_id, $comment_object) {
        $post_id = is_object($comment_object) ? $comment_object->comment_post_ID : 0;
        if ($post_id > 0) {
            $urls = [get_permalink($post_id)];
            $this->purge_urls($urls);
        }
    }

    public function on_comment_status_transition($new_status, $old_status, $comment) {
        if ($new_status !== $old_status && isset($comment->comment_post_ID)) {
            $urls = [get_permalink($comment->comment_post_ID)];
            $this->purge_urls($urls);
        }
    }

    /**
     * Obtém todas as URLs afetadas por um post (post URL, homepage, categorias, tags, feed)
     */
    public function get_related_urls_for_post($post_id) {
        $urls = [];

        // 1. URL do próprio post
        $permalink = get_permalink($post_id);
        if ($permalink) {
            $urls[] = $permalink;
        }

        // 2. Homepage e Feed
        $urls[] = home_url('/');
        $urls[] = get_feed_link();

        // 3. Categorias e Tags associadas
        $taxonomies = get_object_taxonomies(get_post_type($post_id));
        foreach ($taxonomies as $taxonomy) {
            $terms = get_the_terms($post_id, $taxonomy);
            if (!empty($terms) && !is_wp_error($terms)) {
                foreach ($terms as $term) {
                    $link = get_term_link($term, $taxonomy);
                    if (!is_wp_error($link)) {
                        $urls[] = $link;
                    }
                }
            }
        }

        // 4. Página de Blog (posts page)
        $posts_page_id = (int) get_option('page_for_posts');
        if ($posts_page_id > 0) {
            $urls[] = get_permalink($posts_page_id);
        }

        // 5. Sitemap URLs para garantir atualização dos índices
        $urls[] = home_url('/wp-sitemap.xml');
        $urls[] = home_url('/sitemap_index.xml');

        return array_unique(array_filter($urls));
    }
}

endif;
