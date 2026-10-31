<?php
if (!defined('ABSPATH')) {
    exit;
}

class Happy_Nginx_Google_Fonts {
    private $settings;
    private $fonts_dir;
    private $fonts_url;

    public function __construct(Happy_Nginx_Settings $settings) {
        $this->settings = $settings;

        $upload = wp_upload_dir();
        $this->fonts_dir = trailingslashit($upload['basedir']) . 'happynginx/fonts/';
        $this->fonts_url = trailingslashit($upload['baseurl']) . 'happynginx/fonts/';

        if (!file_exists($this->fonts_dir)) {
            wp_mkdir_p($this->fonts_dir);
        }
    }

    /**
     * Processa HTML substituindo links do Google Fonts por versões locais
     */
    public function process($html) {
        if (!$this->settings->get('fonts_local_enabled', 1)) {
            return $html;
        }

        // 1. Encontrar tags <link ... href="...fonts.googleapis.com/css..." ...>
        $pattern = '/<link\b[^>]*href=[\'"](https?:\/\/fonts\.googleapis\.com\/css[^\'"]*)[\'"][^>]*>/i';

        $html = preg_replace_callback($pattern, function($matches) {
            $tag = $matches[0];
            $url = html_entity_decode($matches[1]);

            $local_css_url = $this->localize_font_stylesheet($url);
            if ($local_css_url) {
                // Substitui a URL no link mantendo outros atributos (ex: rel='stylesheet')
                return str_replace($matches[1], esc_url($local_css_url), $tag);
            }

            return $tag;
        }, $html);

        // 2. Remover preconnects desnecessários para Google Fonts se agora são locais
        $html = preg_replace('/<link\b[^>]*href=[\'"]https?:\/\/fonts\.(?:gstatic|googleapis)\.com[\'"][^>]*>/i', '', $html);

        return $html;
    }

    /**
     * Baixa a folha de estilo do Google Fonts e os arquivos .woff2, gerando um CSS local
     */
    public function localize_font_stylesheet($google_url) {
        $hash = md5($google_url);
        $css_filename = 'font-' . $hash . '.css';
        $css_filepath = $this->fonts_dir . $css_filename;
        $css_fileurl  = $this->fonts_url . $css_filename;

        // Se já existe e tem menos de 30 dias, usa o em cache
        if (file_exists($css_filepath) && (time() - filemtime($css_filepath) < 30 * DAY_IN_SECONDS)) {
            return $css_fileurl;
        }

        // Fazer requisição com User-Agent moderno para receber formato WOFF2
        $response = wp_remote_get($google_url, [
            'timeout'    => 10,
            'sslverify'  => false,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $css_content = wp_remote_retrieve_body($response);
        if (empty($css_content)) {
            return false;
        }

        // Encontra todas as URLs de fontes (.woff2 / .woff) no CSS
        $font_pattern = '/url\([\'"]?(https?:\/\/[^\'")]+)[\'"]?\)/i';

        $css_content = preg_replace_callback($font_pattern, function($m) {
            $font_url = $m[1];
            $local_font = $this->download_font_file($font_url);
            if ($local_font) {
                return "url('" . esc_url($local_font) . "')";
            }
            return $m[0];
        }, $css_content);

        // Salva o arquivo CSS local
        file_put_contents($css_filepath, $css_content);

        return $css_fileurl;
    }

    /**
     * Faz download do arquivo de fonte individual e retorna sua URL local
     */
    private function download_font_file($url) {
        $ext = pathinfo(wp_parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (empty($ext)) {
            $ext = 'woff2';
        }

        $filename = 'font-' . md5($url) . '.' . $ext;
        $filepath = $this->fonts_dir . $filename;
        $fileurl  = $this->fonts_url . $filename;

        if (file_exists($filepath)) {
            return $fileurl;
        }

        $response = wp_remote_get($url, [
            'timeout'   => 15,
            'sslverify' => false,
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        if (!empty($body)) {
            file_put_contents($filepath, $body);
            return $fileurl;
        }

        return false;
    }
}
