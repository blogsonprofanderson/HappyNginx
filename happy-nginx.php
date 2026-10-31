<?php
/**
 * Plugin Name:       HappyNginx
 * Plugin URI:        https://github.com/your-repo/happy-nginx
 * Description:       Gerenciamento de Cache FastCGI do Nginx (HestiaCP), Minify, Preload, Delay JS, Lazy Load e Fontes Locais.
 * Version:           1.0.0
 * Author:            HappyNginx Team
 * Author URI:        https://hestiacp.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       happy-nginx
 * Domain Path:       /languages
 * Requires at least: 5.6
 * Requires PHP:      7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('HAPPY_NGINX_VERSION')) {
    define('HAPPY_NGINX_VERSION', '1.0.0');
}
if (!defined('HAPPY_NGINX_FILE')) {
    define('HAPPY_NGINX_FILE', __FILE__);
}
if (!defined('HAPPY_NGINX_PATH')) {
    define('HAPPY_NGINX_PATH', plugin_dir_path(__FILE__));
}
if (!defined('HAPPY_NGINX_URL')) {
    define('HAPPY_NGINX_URL', plugin_dir_url(__FILE__));
}

// Início da contagem de tempo para o timestamp
if (!defined('HAPPY_NGINX_START_TIME')) {
    define('HAPPY_NGINX_START_TIME', microtime(true));
}

// Carregar classes
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-settings.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-cache.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-preload.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-minify.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-delay-js.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-google-fonts.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-lazyload.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-admin-bar.php';
require_once HAPPY_NGINX_PATH . 'admin/class-happy-nginx-admin.php';

/**
 * Classe principal do HappyNginx
 */
if (!class_exists('HappyNginx')) :
class HappyNginx {
    private static $instance = null;
    public $settings;
    public $cache;
    public $preload;
    public $minify;
    public $delay_js;
    public $google_fonts;
    public $lazyload;
    public $admin_bar;
    public $admin;

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings     = Happy_Nginx_Settings::get_instance();
        $this->cache        = new Happy_Nginx_Cache($this->settings);
        $this->preload      = new Happy_Nginx_Preload($this->settings, $this->cache);
        $this->minify       = new Happy_Nginx_Minify($this->settings);
        $this->delay_js     = new Happy_Nginx_Delay_JS($this->settings);
        $this->google_fonts = new Happy_Nginx_Google_Fonts($this->settings);
        $this->lazyload     = new Happy_Nginx_LazyLoad($this->settings);
        $this->admin_bar    = new Happy_Nginx_Admin_Bar($this->settings, $this->cache, $this->preload);

        if (is_admin()) {
            $this->admin = new Happy_Nginx_Admin($this->settings, $this->cache, $this->preload);
        }

        add_action('init', [$this, 'init_buffer'], 1);
        add_action('shutdown', [$this, 'end_buffer'], 999);
    }

    /**
     * Inicia o buffer de saída para processamento do HTML
     */
    public function init_buffer() {
        if ($this->should_buffer()) {
            ob_start([$this, 'process_buffer']);
        }
    }

    /**
     * Finaliza o buffer caso ainda esteja ativo
     */
    public function end_buffer() {
        if (ob_get_level() > 0 && $this->should_buffer()) {
            // Buffer será automaticamente descarregado
        }
    }

    /**
     * Verifica se a requisição atual deve ter o buffer processado
     */
    public function should_buffer() {
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return false;
        }
        if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
            return false;
        }
        // Se for feed RSS/Atom
        if (is_feed()) {
            return false;
        }
        return true;
    }

    /**
     * Processa a saída completa do HTML passando pelos módulos
     */
    public function process_buffer($html) {
        if (empty($html) || !is_string($html)) {
            return $html;
        }

        // Não processar se não for HTML
        if (stripos($html, '<html') === false && stripos($html, '<!DOCTYPE') === false) {
            return $html;
        }

        // 1. Google Fonts local
        if ($this->settings->get('fonts_local_enabled', 1)) {
            $html = $this->google_fonts->process($html);
        }

        // 2. Lazyload para imagens e vídeos
        if ($this->settings->get('lazyload_enabled', 1)) {
            $html = $this->lazyload->process($html);
        }

        // 3. Delay JavaScript
        if ($this->settings->get('delay_js_enabled', 1)) {
            $html = $this->delay_js->process($html);
        }

        // 4. Minify HTML/CSS/JS inline
        if ($this->settings->get('minify_html_enabled', 1)) {
            $html = $this->minify->process($html);
        }

        // 5. Inserir Timestamp do HappyNginx
        if ($this->settings->get('timestamp_enabled', 1)) {
            $html = $this->cache->append_timestamp($html);
        }

        return $html;
    }
}
endif;

// Inicializar plugin
add_action('plugins_loaded', function() {
    HappyNginx::get_instance();
});

// Ativação e desativação
register_activation_hook(__FILE__, function() {
    require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-settings.php';
    Happy_Nginx_Settings::set_defaults();
    wp_schedule_event(time(), 'daily', 'happy_nginx_scheduled_preload');
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('happy_nginx_scheduled_preload');
});
