<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Happy_Nginx_Delay_JS')) :
class Happy_Nginx_Delay_JS {
    private $settings;

    public function __construct(Happy_Nginx_Settings $settings) {
        $this->settings = $settings;
    }

    /**
     * Processa scripts na página transformando seu tipo em text/happynginx-delay
     * e injeta o loader ultraleve que carrega e executa os scripts na primeira interação do usuário.
     */
    public function process($html) {
        if (!$this->settings->get('delay_js_enabled', 1)) {
            return $html;
        }

        $exclusions_raw = $this->settings->get('delay_js_exclusions', "jquery\njquery-core\nwp-includes/js/jquery");
        $exclusions = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $exclusions_raw))));

        // Intercepta tags <script>
        $has_delayed = false;
        $html = preg_replace_callback('/<script\b([^>]*)>(.*?)<\/script>/is', function($matches) use ($exclusions, &$has_delayed) {
            $attrs = $matches[1];
            $content = $matches[2];

            // Ignora se for script de delay do próprio HappyNginx
            if (stripos($attrs, 'happynginx-loader') !== false) {
                return $matches[0];
            }

            // Ignora scripts de dados (json, ld+json, template)
            if (stripos($attrs, 'application/ld+json') !== false ||
                stripos($attrs, 'application/json') !== false ||
                stripos($attrs, 'text/template') !== false ||
                stripos($attrs, 'data-nodelay') !== false) {
                return $matches[0];
            }

            // Verifica se está na lista de exclusão
            foreach ($exclusions as $exclusion) {
                if (!empty($exclusion)) {
                    if (stripos($attrs, $exclusion) !== false || stripos($content, $exclusion) !== false) {
                        return $matches[0];
                    }
                }
            }

            $has_delayed = true;

            // Transforma type="text/javascript" ou adiciona type="text/happynginx-delay"
            if (preg_match('/type=[\'"]([^\'"]+)[\'"]/i', $attrs, $type_match)) {
                $attrs = str_replace($type_match[0], 'type="text/happynginx-delay"', $attrs);
            } else {
                $attrs .= ' type="text/happynginx-delay"';
            }

            // Se for script externo (tem src), renomeia src para data-happynginx-src para evitar download imediato
            if (preg_match('/src=[\'"]([^\'"]+)[\'"]/i', $attrs, $src_match)) {
                $attrs = str_replace($src_match[0], 'data-happynginx-src="' . esc_url($src_match[1]) . '"', $attrs);
            }

            return '<script' . $attrs . '>' . $content . '</script>';
        }, $html);

        // Se algum script foi atrasado, injeta o script loader logo antes de </body>
        if ($has_delayed) {
            $timeout = (int) $this->settings->get('delay_js_timeout', 5);
            $loader_script = $this->get_loader_script($timeout);

            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $loader_script . "\n</body>", $html);
            } else {
                $html .= $loader_script;
            }
        }

        return $html;
    }

    /**
     * Retorna o script em JavaScript puro para ouvir interações e executar os scripts atrasados
     */
    private function get_loader_script($timeout = 5) {
        $timeout_ms = max(1000, $timeout * 1000);
        return <<<HTML
<script id="happynginx-loader">
(function() {
    var executed = false;
    var userEvents = ["keydown", "mousemove", "touchmove", "touchstart", "touchend", "wheel", "click", "scroll"];

    function triggerDelayedScripts() {
        if (executed) return;
        executed = true;

        userEvents.forEach(function(event) {
            window.removeEventListener(event, triggerDelayedScripts, {passive: true});
        });

        var scripts = document.querySelectorAll('script[type="text/happynginx-delay"]');
        var scriptIndex = 0;

        function loadNextScript() {
            if (scriptIndex >= scripts.length) {
                window.dispatchEvent(new Event('happynginx-scripts-loaded'));
                return;
            }

            var oldScript = scripts[scriptIndex++];
            var newScript = document.createElement('script');

            // Copia todos os atributos
            for (var i = 0; i < oldScript.attributes.length; i++) {
                var attr = oldScript.attributes[i];
                if (attr.name !== 'type' && attr.name !== 'data-happynginx-src') {
                    newScript.setAttribute(attr.name, attr.value);
                }
            }

            var src = oldScript.getAttribute('data-happynginx-src');
            if (src) {
                newScript.src = src;
                newScript.onload = newScript.onerror = loadNextScript;
                oldScript.parentNode.replaceChild(newScript, oldScript);
            } else {
                newScript.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(newScript, oldScript);
                loadNextScript();
            }
        }

        loadNextScript();
    }

    userEvents.forEach(function(event) {
        window.addEventListener(event, triggerDelayedScripts, {passive: true});
    });

    // Fallback de tempo limite caso o usuário não interaja
    setTimeout(triggerDelayedScripts, {$timeout_ms});
})();
</script>
HTML;
    }
}

endif;
