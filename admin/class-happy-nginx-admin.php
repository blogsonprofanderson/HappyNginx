<?php
if (!defined('ABSPATH')) {
    exit;
}

class Happy_Nginx_Admin {
    private $settings;
    private $cache;
    private $preload;

    public function __construct(Happy_Nginx_Settings $settings, Happy_Nginx_Cache $cache, Happy_Nginx_Preload $preload) {
        $this->settings = $settings;
        $this->cache    = $cache;
        $this->preload  = $preload;

        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'handle_form_submission']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_settings_page() {
        add_options_page(
            'HappyNginx - Otimização & Cache Nginx',
            'HappyNginx',
            'manage_options',
            'happy-nginx',
            [$this, 'render_settings_page']
        );
    }

    public function enqueue_assets($hook) {
        if ($hook !== 'settings_page_happy-nginx') {
            return;
        }

        wp_enqueue_style(
            'happy-nginx-admin-css',
            HAPPY_NGINX_URL . 'assets/css/admin.css',
            [],
            HAPPY_NGINX_VERSION
        );
    }

    public function handle_form_submission() {
        if (!isset($_POST['happy_nginx_save_settings'])) {
            return;
        }

        check_admin_referer('happy_nginx_settings_nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado.');
        }

        $this->settings->update_all($_POST['happy_nginx']);
        $this->cache->purge_all();

        add_settings_error(
            'happy_nginx_messages',
            'happy_nginx_message',
            'Configurações salvas e cache do Nginx atualizado com sucesso!',
            'updated'
        );
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $options = $this->settings->all();
        $sitemap_info = $this->preload->get_sitemap_info();
        $preload_stats = get_option('happy_nginx_preload_stats', []);
        $cache_path = $options['cache_path'];
        $cache_writable = is_dir($cache_path) && is_writable($cache_path);

        ?>
        <div class="wrap happy-nginx-wrap">
            <div class="happy-nginx-header">
                <h1>⚡ HappyNginx <span class="badge">v<?php echo esc_html(HAPPY_NGINX_VERSION); ?></span></h1>
                <p class="subtitle">Otimização máxima de performance e integração nativa com o Cache FastCGI do Nginx no HestiaCP.</p>
            </div>

            <?php settings_errors('happy_nginx_messages'); ?>

            <div class="happy-nginx-actions-bar">
                <button type="button" class="button button-primary button-large" onclick="happyNginxPurgeAll(event);">
                    ⚡ Limpar Todo o Cache Nginx
                </button>
                <button type="button" class="button button-secondary button-large" onclick="happyNginxPreload(event);">
                    🔥 Pré-carregar Cache (Preload)
                </button>
                <span class="happy-nginx-status">
                    <?php if (is_dir($cache_path)): ?>
                        <span class="status-ok">✔ Pasta Nginx Detectada</span>
                    <?php else: ?>
                        <span class="status-warning">⚠️ Pasta Nginx não encontrada ou sem permissão direta</span>
                    <?php endif; ?>
                </span>
            </div>

            <?php if (!$sitemap_info['exists']): ?>
                <div class="notice notice-warning inline" style="margin: 15px 0; padding: 12px;">
                    <p><strong>Dica de Pré-carregamento:</strong> <?php echo esc_html($sitemap_info['notice']); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="" class="happy-nginx-form">
                <?php wp_nonce_field('happy_nginx_settings_nonce'); ?>

                <div class="happy-nginx-tabs">
                    <div class="happy-nginx-card">
                        <h2>🚀 Cache Nginx (HestiaCP)</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row"><label for="cache_path">Caminho da Pasta de Cache do Nginx</label></th>
                                <td>
                                    <input type="text" id="cache_path" name="happy_nginx[cache_path]" value="<?php echo esc_attr($options['cache_path']); ?>" class="regular-text" />
                                    <p class="description">Padrão no HestiaCP: <code>/var/run/nginx-cache</code> ou personalizado no seu template de vhost Nginx.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Método de Purge</th>
                                <td>
                                    <select name="happy_nginx[purge_method]" id="purge_method">
                                        <option value="direct" <?php selected($options['purge_method'], 'direct'); ?>>Exclusão Direta de Arquivos (Recomendado para HestiaCP)</option>
                                        <option value="http" <?php selected($options['purge_method'], 'http'); ?>>Requisição HTTP PURGE</option>
                                    </select>
                                    <p class="description">A exclusão direta limpa os arquivos em disco sem depender de módulos extras de Nginx.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Smart Purge Automático</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[smart_purge_enabled]" value="1" <?php checked($options['smart_purge_enabled'], 1); ?> />
                                        Limpar automaticamente o cache ao criar, atualizar ou excluir posts, páginas e categorias.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Purge ao Receber Comentários</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[purge_on_comment]" value="1" <?php checked($options['purge_on_comment'], 1); ?> />
                                        Limpar o cache do post afetado quando novos comentários forem publicados ou aprovados.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Timestamp de Geração</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[timestamp_enabled]" value="1" <?php checked($options['timestamp_enabled'], 1); ?> />
                                        Inserir comentário HTML no final do código-fonte com a data/hora e tempo de geração de cada página.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Bypass para Usuários Logados</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[cache_logged_in_bypass]" value="1" <?php checked($options['cache_logged_in_bypass'], 1); ?> />
                                        Enviar cabeçalhos <code>X-Accel-Expires: 0</code> e <code>no-cache</code> para usuários autenticados no WordPress.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Bypass para WooCommerce</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[cache_woocommerce_bypass]" value="1" <?php checked($options['cache_woocommerce_bypass'], 1); ?> />
                                        Desativar cache automaticamente nas páginas de Carrinho, Finalização de Compra (Checkout) e Minha Conta.
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="happy-nginx-card">
                        <h2>🔥 Pré-carregamento de Cache (Preload)</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Sitemap Detectado</th>
                                <td>
                                    <?php if ($sitemap_info['exists']): ?>
                                        <p><strong>Origem:</strong> <?php echo esc_html($sitemap_info['source']); ?> | <code><?php echo esc_html($sitemap_info['url']); ?></code></p>
                                    <?php else: ?>
                                        <p style="color:#d63638;"><strong>Nenhum sitemap detectado!</strong> Recomenda-se ativar o <strong>Yoast SEO</strong>.</p>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="custom_sitemap_url">URL de Sitemap Personalizada (Opcional)</label></th>
                                <td>
                                    <input type="url" id="custom_sitemap_url" name="happy_nginx[custom_sitemap_url]" value="<?php echo esc_attr($options['custom_sitemap_url']); ?>" class="regular-text" placeholder="https://seusite.com/sitemap.xml" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="preload_limit">Limite de URLs por Preload</label></th>
                                <td>
                                    <input type="number" id="preload_limit" name="happy_nginx[preload_limit]" value="<?php echo esc_attr($options['preload_limit']); ?>" min="10" max="1000" class="small-text" /> páginas.
                                </td>
                            </tr>
                            <?php if (!empty($preload_stats)): ?>
                            <tr>
                                <th scope="row">Última Execução</th>
                                <td>
                                    Data: <strong><?php echo esc_html($preload_stats['last_run'] ?? 'N/A'); ?></strong> |
                                    Sucesso: <strong><?php echo esc_html($preload_stats['warmed'] ?? 0); ?></strong> de <?php echo esc_html($preload_stats['total'] ?? 0); ?> URLs.
                                </td>
                            </tr>
                            <?php endif; ?>
                        </table>
                    </div>

                    <div class="happy-nginx-card">
                        <h2>📦 Otimização e Minificação</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Minificar HTML</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[minify_html_enabled]" value="1" <?php checked($options['minify_html_enabled'], 1); ?> />
                                        Remover espaços em branco e comentários supérfluos do código HTML.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Minificar CSS Inline</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[minify_css_enabled]" value="1" <?php checked($options['minify_css_enabled'], 1); ?> />
                                        Comprimir blocos <code>&lt;style&gt;</code> no HTML da página.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Minificar JS Inline</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[minify_js_enabled]" value="1" <?php checked($options['minify_js_enabled'], 1); ?> />
                                        Comprimir blocos <code>&lt;script&gt;</code> no HTML da página.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Remover Query Strings (?ver=)</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[remove_query_strings]" value="1" <?php checked($options['remove_query_strings'], 1); ?> />
                                        Remover a versão das URLs de arquivos CSS e JS para melhorar o cache no navegador e proxies.
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="happy-nginx-card">
                        <h2>⏳ Atraso de Scripts (Delay JavaScript)</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Ativar Delay JS</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[delay_js_enabled]" value="1" <?php checked($options['delay_js_enabled'], 1); ?> />
                                        Atrasar o carregamento e execução dos scripts JavaScript até a primeira interação do usuário (scroll, clique, toque). Melhora drasticamente a nota no PageSpeed.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="delay_js_timeout">Tempo Limite de Espera (Fallback)</label></th>
                                <td>
                                    <input type="number" id="delay_js_timeout" name="happy_nginx[delay_js_timeout]" value="<?php echo esc_attr($options['delay_js_timeout']); ?>" min="2" max="15" class="small-text" /> segundos.
                                    <p class="description">Caso o usuário não interaja com a página, os scripts serão carregados automaticamente após este tempo.</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="delay_js_exclusions">Scripts Excluídos do Delay (Um por linha)</label></th>
                                <td>
                                    <textarea id="delay_js_exclusions" name="happy_nginx[delay_js_exclusions]" rows="4" class="large-text code"><?php echo esc_textarea($options['delay_js_exclusions']); ?></textarea>
                                    <p class="description">Palavras-chave ou URLs de scripts que NÃO devem ser atrasados (ex: jQuery essencial).</p>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="happy-nginx-card">
                        <h2>🔤 Google Fonts Local & Privacidade</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Carregar Fontes Localmente</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[fonts_local_enabled]" value="1" <?php checked($options['fonts_local_enabled'], 1); ?> />
                                        Baixar automaticamente as fontes do Google Fonts (.woff2) e servi-las diretamente do seu servidor Nginx. Melhora o tempo de carregamento e atende aos requisitos de LGPD/GDPR.
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>

                    <div class="happy-nginx-card">
                        <h2>🖼️ Lazy Load (Imagens & Vídeos)</h2>
                        <table class="form-table">
                            <tr>
                                <th scope="row">Lazy Load em Imagens</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[lazyload_enabled]" value="1" <?php checked($options['lazyload_enabled'], 1); ?> />
                                        Adicionar automaticamente atributos nativos <code>loading="lazy"</code> e <code>decoding="async"</code> em todas as imagens.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Lazy Load em Vídeos e Iframes</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[lazyload_iframes]" value="1" <?php checked($options['lazyload_iframes'], 1); ?> />
                                        Carregar iframes de vídeos apenas quando necessários.
                                    </label>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">Preview com Play em Vídeos do YouTube</th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="happy_nginx[lazyload_video_preview]" value="1" <?php checked($options['lazyload_video_preview'], 1); ?> />
                                        Exibir imagem de prévia estática e carregar o player do YouTube apenas quando o usuário clicar no botão play (economia gigante de JS e requisições).
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <p class="submit">
                    <input type="submit" name="happy_nginx_save_settings" id="submit" class="button button-primary button-hero" value="Salvar Configurações e Limpar Cache" />
                </p>
            </form>
        </div>
        <?php
    }
}
