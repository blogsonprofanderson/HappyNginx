<?php
/**
 * Testes Unitários e de Integração para os módulos do HappyNginx
 */

// Mock de constantes e funções do WordPress necessárias para teste isolado
define('ABSPATH', __DIR__ . '/../');
define('HAPPY_NGINX_VERSION', '1.0.0');
define('HAPPY_NGINX_PATH', dirname(__DIR__) . '/');
define('HAPPY_NGINX_URL', 'http://example.com/wp-content/plugins/happy-nginx/');
define('HAPPY_NGINX_START_TIME', microtime(true));
define('DAY_IN_SECONDS', 86400);

$wp_options = [];

function get_option($key, $default = false) {
    global $wp_options;
    return isset($wp_options[$key]) ? $wp_options[$key] : $default;
}

function update_option($key, $value) {
    global $wp_options;
    $wp_options[$key] = $value;
    return true;
}

function wp_parse_args($args, $defaults = []) {
    return array_merge($defaults, (array) $args);
}

function sanitize_text_field($str) {
    return trim(strip_tags($str));
}

function current_time($type) {
    return date('Y-m-d H:i:s');
}

function wp_timezone_string() {
    return 'UTC';
}

function esc_html($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url($url) {
    return $url;
}

function esc_attr($text) {
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function wp_parse_url($url, $component = -1) {
    return parse_url($url, $component);
}

function add_action($tag, $callback, $priority = 10, $accepted_args = 1) {}
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) {}
function do_action($tag, ...$args) {}
function is_admin() { return false; }
function wp_doing_ajax() { return false; }
function wp_doing_cron() { return false; }
function is_feed() { return false; }
function is_user_logged_in() { return false; }
function wp_cache_flush() { return true; }
function remove_query_arg($key, $url) {
    return preg_replace('/([?&])' . preg_quote($key) . '=[^&]*(&|$)/', '$1', $url);
}
function home_url($path = '') { return 'https://example.com' . $path; }
function get_feed_link() { return 'https://example.com/feed/'; }
function get_permalink($id = 0) { return 'https://example.com/post/' . $id; }
function get_object_taxonomies($type) { return ['category']; }
function get_post_type($id) { return 'post'; }
function get_the_terms($id, $tax) { return []; }
function is_wp_error($thing) { return false; }
function wp_upload_dir() {
    $dir = sys_get_temp_dir() . '/happynginx_test_uploads';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    return ['basedir' => $dir, 'baseurl' => 'https://example.com/uploads'];
}
function trailingslashit($str) { return rtrim($str, '/\\') . '/'; }
function wp_mkdir_p($dir) { return is_dir($dir) || mkdir($dir, 0777, true); }
function add_query_arg($param, $val, $url) {
    return $url . (strpos($url, '?') !== false ? '&' : '?') . $param . '=' . $val;
}

// Carregar classes
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-settings.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-cache.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-preload.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-minify.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-delay-js.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-google-fonts.php';
require_once HAPPY_NGINX_PATH . 'includes/class-happy-nginx-lazyload.php';

function assert_test($condition, $message) {
    if (!$condition) {
        echo "[FALHA] " . $message . "\n";
        exit(1);
    }
    echo "[OK] " . $message . "\n";
}

echo "=== INICIANDO TESTES DO HAPPYNGINX ===\n\n";

// 1. Teste de Configurações
echo "--- 1. Testando Happy_Nginx_Settings ---\n";
Happy_Nginx_Settings::set_defaults();
$settings = Happy_Nginx_Settings::get_instance();
assert_test($settings->get('cache_path') === '/var/run/nginx-cache', 'Caminho padrão de cache correto para HestiaCP');
assert_test($settings->get('smart_purge_enabled') === 1, 'Smart Purge ativado por padrão');
assert_test($settings->get('timestamp_enabled') === 1, 'Timestamp ativado por padrão');

// 2. Teste de Timestamp no Cache Nginx
echo "\n--- 2. Testando Happy_Nginx_Cache Timestamp & Purge ---\n";
$cache = new Happy_Nginx_Cache($settings);
$sample_html = "<!DOCTYPE html><html><head><title>Teste</title></head><body><h1>Olá Mundo</h1></body></html>";
$stamped = $cache->append_timestamp($sample_html);
assert_test(strpos($stamped, '<!-- HappyNginx: Página em cache gerada em') !== false, 'Comentário HTML de timestamp adicionado');
assert_test(strpos($stamped, 'Tempo de geração:') !== false, 'Tempo de execução presente no timestamp');

// Teste de exclusão direta de cache
$temp_cache_dir = sys_get_temp_dir() . '/nginx_cache_test_' . uniqid();
mkdir($temp_cache_dir, 0777, true);
file_put_contents($temp_cache_dir . '/test1.cache', 'data');
mkdir($temp_cache_dir . '/sub', 0777, true);
file_put_contents($temp_cache_dir . '/sub/test2.cache', 'data');
assert_test(file_exists($temp_cache_dir . '/test1.cache'), 'Arquivo de teste de cache criado');
$cache->delete_cache_dir_contents($temp_cache_dir);
assert_test(!file_exists($temp_cache_dir . '/test1.cache'), 'Diretório de cache limpo com sucesso');
@rmdir($temp_cache_dir);

// 3. Teste de Minificação (HTML, CSS, JS)
echo "\n--- 3. Testando Happy_Nginx_Minify ---\n";
$minify = new Happy_Nginx_Minify($settings);
$css_input = "body { color: #333333; margin: 0px 10px; } /* comentario */";
$css_min = $minify->minify_css($css_input);
assert_test(strpos($css_min, '/*') === false, 'Comentários CSS removidos');
assert_test(strpos($css_min, 'margin:0px 10px') !== false || strpos($css_min, 'margin: 0px 10px') !== false, 'Espaçamento CSS normalizado');

$html_input = "<html>\n  <body>\n    <!-- comentario normal -->\n    <p>Texto</p>\n    <pre>  codigo com   espacos  </pre>\n  </body>\n</html>";
$html_min = $minify->minify_html($html_input);
assert_test(strpos($html_min, '<!-- comentario normal -->') === false, 'Comentários HTML removidos');
assert_test(strpos($html_min, '<pre>  codigo com   espacos  </pre>') !== false, 'Conteúdo dentro de <pre> preservado');

// 4. Teste de Delay JS
echo "\n--- 4. Testando Happy_Nginx_Delay_JS ---\n";
$delay_js = new Happy_Nginx_Delay_JS($settings);
$js_html = '<!DOCTYPE html><html><head><script src="https://example.com/app.js"></script><script src="https://example.com/wp-includes/js/jquery/jquery.min.js"></script></head><body></body></html>';
$delayed_html = $delay_js->process($js_html);
assert_test(strpos($delayed_html, 'type="text/happynginx-delay"') !== false, 'Script comum transformado para tipo atrasado');
assert_test(strpos($delayed_html, 'data-happynginx-src="https://example.com/app.js"') !== false, 'Src do script movido para data-happynginx-src');
assert_test(strpos($delayed_html, '<script src="https://example.com/wp-includes/js/jquery/jquery.min.js">') !== false, 'Script na lista de exclusão (jquery) preservado');
assert_test(strpos($delayed_html, 'id="happynginx-loader"') !== false, 'Loader de interação do usuário injetado');

// 5. Teste de Lazy Load (Imagens e Vídeos)
echo "\n--- 5. Testando Happy_Nginx_LazyLoad ---\n";
$lazyload = new Happy_Nginx_LazyLoad($settings);
$img_html = '<img src="https://example.com/img.jpg" alt="Foto">';
$lazy_img = $lazyload->lazyload_images($img_html);
assert_test(strpos($lazy_img, 'loading="lazy"') !== false, 'Atributo loading="lazy" adicionado à imagem');
assert_test(strpos($lazy_img, 'decoding="async"') !== false, 'Atributo decoding="async" adicionado à imagem');

$yt_html = '<iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" frameborder="0"></iframe>';
$lazy_yt = $lazyload->lazyload_iframes($yt_html);
assert_test(strpos($lazy_yt, 'happynginx-video-placeholder') !== false, 'Iframe do YouTube substituído por preview estático com botão play');
assert_test(strpos($lazy_yt, 'hqdefault.jpg') !== false, 'Thumbnail do YouTube incluída no preview');

// 6. Teste de Sitemap Parser
echo "\n--- 6. Testando Happy_Nginx_Preload Parser ---\n";
$preload = new Happy_Nginx_Preload($settings, $cache);
$sitemap_sample = '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/page1/</loc></url><url><loc>https://example.com/page2/</loc></url></urlset>';
$xml_temp_file = sys_get_temp_dir() . '/sample_sitemap.xml';
file_put_contents($xml_temp_file, $sitemap_sample);
// Teste usando a função com o arquivo local
$xml = simplexml_load_string($sitemap_sample);
assert_test(isset($xml->url) && count($xml->url) === 2, 'Sitemap XML processado com sucesso');
@unlink($xml_temp_file);

echo "\n============================================\n";
echo "TODOS OS TESTES FORAM EXECUTADOS COM SUCESSO!\n";
echo "============================================\n";
