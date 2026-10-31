# HappyNginx ⚡

Plugin de performance e gerenciamento de cache Nginx (FastCGI Cache) desenvolvido especificamente para servidores **HestiaCP**.

---

## 🚀 Recursos Principais

1. **Compatibilidade 100% com HestiaCP:**
   - Limpeza direta de disco no diretório padrão `/var/run/nginx-cache` (com suporte opcional a HTTP PURGE).
   - Sem dependência de módulos de terceiros complexos no Nginx.

2. **Gestão Inteligente de Cache (Smart Purge):**
   - Invalidação automática e seletiva de cache ao publicar, editar ou excluir posts, páginas, categorias, tags e comentários.
   - Limpeza total instantânea ao trocar temas, plugins ou menus.

3. **Timestamp de Geração no HTML:**
   - Adiciona automaticamente um comentário HTML no rodapé de cada página:
     `<!-- HappyNginx: Página em cache gerada em YYYY-MM-DD HH:MM:SS (Timezone) | Tempo de geração: 0.042s -->`

4. **Pré-carregamento de Cache (Preload):**
   - Leitura inteligente de Sitemaps XML (compatível com **Yoast SEO**, sitemap nativo do WordPress ou URL customizada).
   - Indicação visual e recomendação do Yoast SEO caso nenhum sitemap seja detectado.
   - Pré-aquecimento assíncrono do cache Nginx sem envio de cookies de usuário logado.
   - Agendamento automático via WP-Cron e botão manual.

5. **Ações Rápidas na Barra Superior (Admin Bar):**
   - Botão **⚡ Limpar Todo o Cache Nginx**.
   - Botão **📄 Limpar Cache Desta Página**.
   - Botão **🔥 Pré-carregar Cache (Preload)**.

6. **Minificação & Otimização:**
   - Minificação de HTML com preservação de blocos `<pre>`, `<code>` e `<textarea>`.
   - Minificação de blocos de CSS e JavaScript inline.
   - Remoção de query strings (`?ver=x.y.z`) de arquivos estáticos.

7. **Atraso de Scripts (Delay JavaScript):**
   - Posterga a execução e o download de scripts JavaScript até a primeira interação do usuário (scroll, clique, touch, movimentação do mouse).
   - Atinge notas máximas no Google PageSpeed / Lighthouse.
   - Fallback de tempo limite configurável e lista de exceções (ex: jQuery essencial).

8. **Google Fonts Local & LGPD/GDPR:**
   - Intercepta links do Google Fonts, baixa automaticamente as fontes `.woff2` e gera CSS local servido pelo próprio servidor Nginx.
   - Elimina requisições externas e conexões lentas.

9. **Lazy Load de Imagens e Vídeos:**
   - Aplica `loading="lazy"` e `decoding="async"` nativo nas imagens.
   - Lazy load inteligente para vídeos e iframes do YouTube: exibe thumbnail estática com botão play leve, carregando o player real apenas ao clicar.

10. **Bypass Inteligente:**
    - Desativação automática de cache para administradores e usuários autenticados (`X-Accel-Expires: 0`).
    - Desativação em páginas sensíveis do WooCommerce (Carrinho, Checkout e Minha Conta).

---

## 🛠️ Instalação no WordPress

1. Baixe ou envie a pasta do plugin para `wp-content/plugins/happy-nginx`.
2. Acesse o painel do WordPress em **Plugins** > **Plugins Instalados** e ative o **HappyNginx**.
3. Acesse **Configurações** > **HappyNginx** para ajustar as opções conforme sua preferência.

---

## 🧪 Testes Automatizados

Para executar os testes unitários da suíte:
```bash
php tests/test-happy-nginx.php
```
