<?php
if (!defined('ABSPATH')) {
    exit;
}

class Happy_Nginx_Minify {
    private $settings;

    public function __construct(Happy_Nginx_Settings $settings) {
        $this->settings = $settings;

        if ($this->settings->get('remove_query_strings', 1)) {
            add_filter('script_loader_src', [$this, 'remove_query_string_ver'], 15, 1);
            add_filter('style_loader_src', [$this, 'remove_query_string_ver'], 15, 1);
        }
    }

    /**
     * Remove ?ver= das URLs de scripts e estilos
     */
    public function remove_query_string_ver($src) {
        if (strpos($src, '?ver=') || strpos($src, '&ver=')) {
            $src = remove_query_arg('ver', $src);
        }
        return $src;
    }

    /**
     * Processa a minificação do HTML completo, incluindo blocos de style e script inline
     */
    public function process($html) {
        if (empty($html) || !is_string($html)) {
            return $html;
        }

        // 1. Minificar blocos inline <style> se ativado
        if ($this->settings->get('minify_css_enabled', 1)) {
            $html = preg_replace_callback('/<style\b([^>]*)>(.*?)<\/style>/is', function($matches) {
                $attrs = $matches[1];
                $css = $matches[2];
                return '<style' . $attrs . '>' . $this->minify_css($css) . '</style>';
            }, $html);
        }

        // 2. Minificar blocos inline <script> se ativado (exceto application/ld+json ou outros tipos de dados)
        if ($this->settings->get('minify_js_enabled', 1)) {
            $html = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function($matches) {
                $attrs = $matches[1];
                $js = $matches[2];

                // Pular minificação se for json, template ou dados
                if (stripos($attrs, 'application/ld+json') !== false ||
                    stripos($attrs, 'application/json') !== false ||
                    stripos($attrs, 'text/template') !== false ||
                    empty(trim($js))) {
                    return $matches[0];
                }

                return '<script' . $attrs . '>' . $this->minify_js($js) . '</script>';
            }, $html);
        }

        // 3. Minificar o próprio HTML
        if ($this->settings->get('minify_html_enabled', 1)) {
            $html = $this->minify_html($html);
        }

        return $html;
    }

    /**
     * Minificação de código HTML preservando blocos <pre>, <code>, <textarea> e comentários condicionais/importantes
     */
    public function minify_html($html) {
        // Proteger blocos <pre>, <code>, <textarea> de quebras indevidas
        $placeholders = [];
        $i = 0;

        $protect_patterns = [
            '/<pre\b[^>]*>.*?<\/pre>/is',
            '/<textarea\b[^>]*>.*?<\/textarea>/is',
            '/<code\b[^>]*>.*?<\/code>/is',
        ];

        foreach ($protect_patterns as $pattern) {
            $html = preg_replace_callback($pattern, function($match) use (&$placeholders, &$i) {
                $key = '###HAPPYNGINX_PROTECTED_' . ($i++) . '###';
                $placeholders[$key] = $match[0];
                return $key;
            }, $html);
        }

        // Remover comentários HTML normais, preservando IE conditionals (<!--[if) e HappyNginx
        $html = preg_replace('/<!--(?!\s*(?:\[if [^\]]+]|HappyNginx|<!|>))(?:(?!-->).)*-->/s', '', $html);

        // Reduzir múltiplos espaços em branco entre tags
        $html = preg_replace('/>\s+</', '> <', $html);

        // Remover espaços em branco extras repetidos mantendo um
        $html = preg_replace('/\s{2,}/', ' ', $html);

        // Restaurar blocos protegidos
        if (!empty($placeholders)) {
            $html = strtr($html, $placeholders);
        }

        return trim($html);
    }

    /**
     * Minificação rápida e segura de CSS
     */
    public function minify_css($css) {
        if (empty(trim($css))) {
            return $css;
        }

        // Remover comentários CSS
        $css = preg_replace('!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $css);
        // Remover espaços ao redor de colchetes, dois-pontos, vírgulas e pontos-e-vírgulas
        $css = preg_replace('/\s*([{}|:;,])\s+/', '$1', $css);
        $css = preg_replace('/\s\s+(?![^(]*\))/', ' ', $css);
        $css = str_replace(['; ', ' ;'], ';', $css);
        $css = str_replace(';}', '}', $css); // Remove último ponto-e-vírgula desnecessário
        $css = preg_replace('/\s+/', ' ', $css);

        return trim($css);
    }

    /**
     * Minificação segura de JavaScript inline
     */
    public function minify_js($js) {
        if (empty(trim($js))) {
            return $js;
        }

        // Remove comentários de bloco /* ... */
        $js = preg_replace('!/\*[^*]*\*+([^/*][^*]*\*+)*/!', '', $js);

        // Remove linhas em branco e espaços excessivos
        $lines = explode("\n", $js);
        $cleaned_lines = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            // Ignora comentários de linha única isolados
            if (strpos($trimmed, '//') === 0) {
                continue;
            }
            if (!empty($trimmed)) {
                $cleaned_lines[] = $trimmed;
            }
        }

        $minified = implode("\n", $cleaned_lines);
        return $minified;
    }
}
