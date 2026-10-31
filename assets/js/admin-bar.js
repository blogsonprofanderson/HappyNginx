/**
 * HappyNginx - Admin Bar Actions
 */
function happyNginxPurgeAll(e) {
    if (e && e.preventDefault) e.preventDefault();
    if (!confirm('Deseja limpar todo o cache do Nginx agora?')) return;

    var originalText = jQuery('#wp-admin-bar-happy-nginx-purge-all .ab-item').text();
    jQuery('#wp-admin-bar-happy-nginx-purge-all .ab-item').text('⏳ Limpando...');

    jQuery.post(happyNginxData.ajax_url, {
        action: 'happy_nginx_purge_all',
        nonce: happyNginxData.nonce
    }, function(res) {
        jQuery('#wp-admin-bar-happy-nginx-purge-all .ab-item').text(originalText);
        if (res.success) {
            alert(res.data.message || 'Cache limpo com sucesso!');
        } else {
            alert('Erro ao limpar cache: ' + (res.data ? res.data.message : 'Falha desconhecida'));
        }
    }).fail(function() {
        jQuery('#wp-admin-bar-happy-nginx-purge-all .ab-item').text(originalText);
        alert('Erro ao conectar com o servidor.');
    });
}

function happyNginxPurgeCurrent(e) {
    if (e && e.preventDefault) e.preventDefault();

    var originalText = jQuery('#wp-admin-bar-happy-nginx-purge-current .ab-item').text();
    jQuery('#wp-admin-bar-happy-nginx-purge-current .ab-item').text('⏳ Limpando página...');

    jQuery.post(happyNginxData.ajax_url, {
        action: 'happy_nginx_purge_current',
        nonce: happyNginxData.nonce,
        url: window.location.href
    }, function(res) {
        jQuery('#wp-admin-bar-happy-nginx-purge-current .ab-item').text(originalText);
        if (res.success) {
            alert(res.data.message || 'Página limpa!');
            window.location.reload();
        } else {
            alert('Erro: ' + (res.data ? res.data.message : 'Falha'));
        }
    }).fail(function() {
        jQuery('#wp-admin-bar-happy-nginx-purge-current .ab-item').text(originalText);
        alert('Erro ao conectar com o servidor.');
    });
}

function happyNginxPreload(e) {
    if (e && e.preventDefault) e.preventDefault();
    if (!confirm('Iniciar pré-carregamento do cache via Sitemap? Pode levar alguns instantes.')) return;

    var originalText = jQuery('#wp-admin-bar-happy-nginx-preload .ab-item').text();
    jQuery('#wp-admin-bar-happy-nginx-preload .ab-item').text('⏳ Pré-carregando...');

    jQuery.post(happyNginxData.ajax_url, {
        action: 'happy_nginx_preload_cache',
        nonce: happyNginxData.nonce
    }, function(res) {
        jQuery('#wp-admin-bar-happy-nginx-preload .ab-item').text(originalText);
        if (res.success) {
            alert(res.data.message);
        } else {
            alert('Erro no preload: ' + (res.data ? res.data.message : 'Falha'));
        }
    }).fail(function() {
        jQuery('#wp-admin-bar-happy-nginx-preload .ab-item').text(originalText);
        alert('Erro ao conectar com o servidor.');
    });
}
