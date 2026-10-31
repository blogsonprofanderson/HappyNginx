<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Happy_Nginx_Settings')) :
class Happy_Nginx_Settings {
    private static $instance = null;
    const OPTION_KEY = 'happy_nginx_options';

    private $options = [];

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load();
    }

    public static function detect_hestia_cache_path() {
        $host = '';
        if (function_exists('home_url')) {
            $parsed = wp_parse_url(home_url());
            $host = $parsed['host'] ?? '';
        }
        if (empty($host) && !empty($_SERVER['HTTP_HOST'])) {
            $host = explode(':', $_SERVER['HTTP_HOST'])[0];
        }

        // Caminhos oficiais do HestiaCP
        $candidates = [];
        if (!empty($host)) {
            $candidates[] = '/var/cache/nginx/micro/' . $host;
            $candidates[] = '/var/cache/nginx/' . $host;
        }
        $candidates[] = '/var/cache/nginx/micro';
        $candidates[] = '/var/run/nginx-cache';
        $candidates[] = '/var/cache/nginx';

        foreach ($candidates as $dir) {
            if (is_dir($dir)) {
                return $dir;
            }
        }

        // Se nenhum existir fisicamente ainda, o padrão mais moderno do HestiaCP é /var/cache/nginx/micro/$domain
        return !empty($host) ? '/var/cache/nginx/micro/' . $host : '/var/cache/nginx/micro';
    }

    public static function get_default_options() {
        return [
            // Cache Nginx
            'cache_path'               => self::detect_hestia_cache_path(),
            'smart_purge_enabled'      => 1,
            'purge_on_comment'         => 1,
            'purge_method'             => 'direct', // 'direct' ou 'http'
            'http_purge_url'           => 'http://127.0.0.1',
            'timestamp_enabled'        => 1,
            'cache_logged_in_bypass'   => 1,
            'cache_woocommerce_bypass' => 1,

            // Preload
            'preload_enabled'          => 1,
            'preload_interval'         => 'daily', // 'hourly', 'twicedaily', 'daily'
            'preload_limit'            => 100, // padrão 100 URLs
            'custom_sitemap_url'       => '',

            // Minify
            'minify_html_enabled'      => 1,
            'minify_css_enabled'       => 1,
            'minify_js_enabled'        => 1,
            'remove_query_strings'     => 1,

            // Delay JS
            'delay_js_enabled'         => 1,
            'delay_js_timeout'         => 3, // fallback pré-definido em 3 segundos
            'delay_js_exclusions'      => "jquery\njquery-core\nwp-includes/js/jquery",

            // Google Fonts
            'fonts_local_enabled'      => 1,
            'fonts_preload'            => 1,

            // Lazy Load
            'lazyload_enabled'         => 1,
            'lazyload_iframes'         => 1,
            'lazyload_video_preview'   => 1,
        ];
    }

    public static function set_defaults() {
        $existing = get_option(self::OPTION_KEY, []);
        $defaults = self::get_default_options();
        $merged   = wp_parse_args($existing, $defaults);
        update_option(self::OPTION_KEY, $merged);
    }

    public function load() {
        $saved = get_option(self::OPTION_KEY, []);
        $this->options = wp_parse_args($saved, self::get_default_options());
    }

    public function get($key, $default = null) {
        if (isset($this->options[$key])) {
            return $this->options[$key];
        }
        return $default;
    }

    public function set($key, $value) {
        $this->options[$key] = $value;
    }

    public function save() {
        return update_option(self::OPTION_KEY, $this->options);
    }

    public function update_all(array $new_options) {
        $defaults = self::get_default_options();
        $sanitized = [];

        // Lista de campos que são estritamente inteiros (não checkboxes)
        $integer_fields = ['preload_limit', 'delay_js_timeout'];

        // Lista de campos com texto longo/multilinhas
        $textarea_fields = ['delay_js_exclusions'];

        foreach ($defaults as $key => $default_val) {
            if (in_array($key, $integer_fields, true)) {
                // Campo numérico inteiro
                $sanitized[$key] = isset($new_options[$key]) ? max(1, (int) $new_options[$key]) : (int) $default_val;
            } elseif (in_array($key, $textarea_fields, true)) {
                // Campo de textarea
                $sanitized[$key] = isset($new_options[$key]) ? sanitize_textarea_field($new_options[$key]) : $default_val;
            } elseif (is_bool($default_val) || ($default_val === 0 || $default_val === 1)) {
                // Checkbox booleano
                $sanitized[$key] = !empty($new_options[$key]) ? 1 : 0;
            } else {
                // Campo de texto geral
                $sanitized[$key] = isset($new_options[$key]) ? sanitize_text_field($new_options[$key]) : $default_val;
            }
        }

        $this->options = $sanitized;
        return $this->save();
    }

    public function all() {
        return $this->options;
    }
}

endif;
