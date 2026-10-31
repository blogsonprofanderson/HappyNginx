<?php
if (!defined('ABSPATH')) {
    exit;
}

class Happy_Nginx_LazyLoad {
    private $settings;

    public function __construct(Happy_Nginx_Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Processa imagens e vídeos / iframes no HTML
     */
    public function process($html) {
        if (!$this->settings->get('lazyload_enabled', 1)) {
            return $html;
        }

        // 1. Lazy load em imagens <img>
        $html = $this->lazyload_images($html);

        // 2. Lazy load em iframes de vídeo (YouTube, Vimeo) com preview placeholder
        if ($this->settings->get('lazyload_iframes', 1)) {
            $html = $this->lazyload_iframes($html);
        }

        return $html;
    }

    /**
     * Aplica loading="lazy" e decoding="async" em todas as tags <img> que ainda não possuem
     */
    public function lazyload_images($html) {
        return preg_replace_callback('/<img\b([^>]*)>/i', function($matches) {
            $attrs = $matches[1];

            // Pula se tiver data-no-lazy ou class contendo skip-lazy
            if (stripos($attrs, 'skip-lazy') !== false || stripos($attrs, 'data-no-lazy') !== false) {
                return $matches[0];
            }

            // Se não tem loading, adiciona loading="lazy"
            if (stripos($attrs, 'loading=') === false) {
                $attrs .= ' loading="lazy"';
            }

            // Se não tem decoding, adiciona decoding="async"
            if (stripos($attrs, 'decoding=') === false) {
                $attrs .= ' decoding="async"';
            }

            return '<img' . $attrs . '>';
        }, $html);
    }

    /**
     * Transforma iframes do YouTube / Vimeo em previews interativos com botão play
     */
    public function lazyload_iframes($html) {
        $use_preview = $this->settings->get('lazyload_video_preview', 1);

        $has_video_preview = false;

        $html = preg_replace_callback('/<iframe\b([^>]*)>(.*?)<\/iframe>/is', function($matches) use ($use_preview, &$has_video_preview) {
            $attrs = $matches[1];
            $content = $matches[2];

            // Pula se já tiver data-no-lazy
            if (stripos($attrs, 'skip-lazy') !== false || stripos($attrs, 'data-no-lazy') !== false) {
                return $matches[0];
            }

            // Detecta src do iframe
            if (!preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $attrs, $src_match)) {
                return $matches[0];
            }

            $src = $src_match[1];

            // Se a opção de preview estiver ligada e for YouTube
            if ($use_preview && (stripos($src, 'youtube.com') !== false || stripos($src, 'youtu.be') !== false)) {
                $video_id = $this->extract_youtube_id($src);
                if ($video_id) {
                    $has_video_preview = true;
                    return $this->get_youtube_preview_html($video_id, $src);
                }
            }

            // Fallback: adiciona loading="lazy" ao iframe
            if (stripos($attrs, 'loading=') === false) {
                $attrs .= ' loading="lazy"';
            }

            return '<iframe' . $attrs . '>' . $content . '</iframe>';
        }, $html);

        // Se injetamos algum preview de vídeo, adicionar estilos e script mínimos no rodapé
        if ($has_video_preview) {
            $helper_code = $this->get_video_helper_assets();
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $helper_code . "\n</body>", $html);
            } else {
                $html .= $helper_code;
            }
        }

        return $html;
    }

    /**
     * Extrai ID de vídeo do YouTube
     */
    private function extract_youtube_id($url) {
        if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/ ]{11})/i', $url, $match)) {
            return $match[1];
        }
        return false;
    }

    /**
     * Cria bloco HTML responsivo com thumbnail e botão de play
     */
    private function get_youtube_preview_html($video_id, $original_url) {
        $thumb_url = 'https://i.ytimg.com/vi/' . esc_attr($video_id) . '/hqdefault.jpg';
        $autoplay_url = add_query_arg('autoplay', '1', $original_url);

        return sprintf(
            '<div class="happynginx-video-placeholder" data-src="%s" style="position:relative;width:100%%;padding-bottom:56.25%%;background:#000;cursor:pointer;background-image:url(\'%s\');background-position:center;background-size:cover;display:block;">' .
                '<button type="button" aria-label="Reproduzir Vídeo" class="happynginx-video-play-btn" style="position:absolute;top:50%%;left:50%%;transform:translate(-50%%,-50%%);width:68px;height:48px;background:rgba(33,33,33,0.8);border:none;border-radius:14%%;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background 0.2s;">' .
                    '<svg height="24" viewBox="0 0 24 24" width="24" fill="#ffffff"><path d="M8 5v14l11-7z"></path></svg>' .
                '</button>' .
            '</div>',
            esc_url($autoplay_url),
            esc_url($thumb_url)
        );
    }

    private function get_video_helper_assets() {
        return <<<HTML
<style>
.happynginx-video-placeholder:hover .happynginx-video-play-btn { background: #f00 !important; }
</style>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var placeholders = document.querySelectorAll('.happynginx-video-placeholder');
    placeholders.forEach(function(el) {
        el.addEventListener('click', function() {
            var src = this.getAttribute('data-src');
            var iframe = document.createElement('iframe');
            iframe.setAttribute('src', src);
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute('allow', 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture');
            iframe.setAttribute('allowfullscreen', '1');
            iframe.style.position = 'absolute';
            iframe.style.top = '0';
            iframe.style.left = '0';
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            this.innerHTML = '';
            this.appendChild(iframe);
        });
    });
});
</script>
HTML;
    }
}
