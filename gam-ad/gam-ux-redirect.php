<?php
/**
 * Plugin Name: RedirectID AD
 * Description: Cria cards de apps (nome/ícone/tamanho/preço/nota) e URLs internas /go/<token> para acionar regras de interstitial do site. NÃO exibe anúncios. Metabox com múltiplos apps e posição (parágrafo) por app.
 * Version: 2.0.0
 * Author: TwoD
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class RedirectID_AD {
  const OPT = 'rid_settings';
  const QV  = 'rid_go_token';
  const PMK = '_rid_apps'; // post meta com a lista de apps

  public function __construct(){
    // Admin
    add_action('admin_menu', [$this,'menu']);
    add_action('admin_init', [$this,'register_settings']);
    add_action('add_meta_boxes', [$this,'metabox']);
    add_action('admin_enqueue_scripts', [$this,'admin_assets']);
    add_action('save_post', [$this,'save_apps'], 20, 3);

    // Front
    add_action('wp_enqueue_scripts', [$this,'front_assets']);
    add_filter('the_content', [$this,'inject_cards'], 8);

    // Shortcode para teste manual
    add_shortcode('rid_app_card', [$this,'shortcode_single']);

    // /go/<token>
    add_action('init', [$this,'register_go_endpoint']);
    add_action('template_redirect', [$this,'handle_go_request']);

    register_activation_hook(__FILE__, [$this,'on_activate']);
    register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
  }

  # ===== Admin =====
  public function menu(){
    add_options_page('RedirectID AD', 'RedirectID AD', 'manage_options', 'redirectid-ad', [$this,'settings_page']);
  }
  public function register_settings(){
    register_setting(self::OPT, self::OPT);
    add_settings_section('general','Opções', '__return_false', self::OPT);

    add_settings_field('token_ttl','Tempo de vida do token (/go/) em minutos', function(){
      $o = get_option(self::OPT, []);
      $v = intval($o['token_ttl'] ?? 10);
      echo '<input type="number" min="1" step="1" style="width:120px" name="'.self::OPT.'[token_ttl]" value="'.$v.'">';
    }, self::OPT, 'general');

    add_settings_field('hold_ms','Espera na /go/ (ms)', function(){
      $o = get_option(self::OPT, []);
      $v = intval($o['hold_ms'] ?? 150);
      echo '<input type="number" min="0" step="50" style="width:120px" name="'.self::OPT.'[hold_ms]" value="'.$v.'">';
      echo '<p class="description">0 = redireciona imediatamente; 100–300ms ajuda regras de interstitial por navegação interna.</p>';
    }, self::OPT, 'general');

    add_settings_field('primary_color','Cor do botão (fallback)', function(){
      $o = get_option(self::OPT, []);
      $v = esc_attr($o['primary_color'] ?? '#3b82f6');
      echo '<input type="color" name="'.self::OPT.'[primary_color]" value="'.$v.'">';
      echo '<p class="description">Tentaremos detectar a cor primária do tema automaticamente; esta é usada como fallback.</p>';
    }, self::OPT, 'general');
  }
  public function settings_page(){
    echo '<div class="wrap"><h1>RedirectID AD</h1><p>Cria cards de apps + URLs internas <code>/go/&lt;token&gt;</code>. NÃO exibe anúncios — as regras de interstitial ficam no seu script do site.</p>';
    echo '<form method="post" action="options.php">';
    settings_fields(self::OPT); do_settings_sections(self::OPT); submit_button(); echo '</form></div>';
  }

  public function metabox(){
    add_meta_box('rid_apps', 'Apps (RedirectID)', [$this,'metabox_html'], ['post'], 'side', 'high');
  }
  public function admin_assets($hook){
    if (in_array($hook, ['post.php','post-new.php'], true)){
      wp_enqueue_style('rid-admin', plugin_dir_url(__FILE__).'assets/rid-admin.css', [], '2.0.0');
      wp_enqueue_script('rid-admin', plugin_dir_url(__FILE__).'assets/rid-admin.js', [], '2.0.0', true);
    }
  }
  private function count_paragraphs($post){
    $html = wpautop($post->post_content);
    return substr_count($html, '</p>');
  }
  public function metabox_html($post){
    $apps = get_post_meta($post->ID, self::PMK, true);
    if (!is_array($apps)) $apps = [];
    $countP = $this->count_paragraphs($post);
    wp_nonce_field('rid_apps','rid_apps_nonce');
    ?>
    <div id="rid-box" data-paragraphs="<?php echo esc_attr($countP); ?>">
      <div class="rid-head">
        <button type="button" class="button button-primary" id="rid-add">+ adicionar app</button>
        <small>Parágrafos detectados: <?php echo intval($countP); ?></small>
      </div>
      <div id="rid-list"></div>
      <input type="hidden" name="rid_apps_json" id="rid_apps_json" value="<?php echo esc_attr(wp_json_encode($apps)); ?>">
      <template id="rid-item-tpl">
        <div class="rid-item" draggable="true">
          <span class="rid-drag">↕</span>
          <a class="rid-del" href="#" title="remover">×</a>
          <p><label>Google Play</label><input type="url" class="rid-gplay" placeholder="https://play.google.com/store/apps/details?id=..."></p>
          <p><label>Apple Store</label><input type="url" class="rid-appstore" placeholder="https://apps.apple.com/app/id..."></p>
          <p><label>Inserir</label>
            <select class="rid-pos"></select>
          </p>
        </div>
      </template>
    </div>
    <?php
  }
  public function save_apps($post_id, $post, $update){
    if (wp_is_post_revision($post_id) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) return;
    if (!isset($_POST['rid_apps_nonce']) || !wp_verify_nonce($_POST['rid_apps_nonce'],'rid_apps')) return;
    if (!current_user_can('edit_post',$post_id)) return;

    $json = isset($_POST['rid_apps_json']) ? wp_unslash($_POST['rid_apps_json']) : '[]';
    $arr  = json_decode($json, true);
    $out  = [];
    if (is_array($arr)){
      foreach ($arr as $it){
        $g = isset($it['gplay']) ? esc_url_raw($it['gplay']) : '';
        $a = isset($it['appstore']) ? esc_url_raw($it['appstore']) : '';
        $p = isset($it['pos']) ? intval($it['pos']) : 0;
        if ($g || $a) $out[] = ['gplay'=>$g,'appstore'=>$a,'pos'=>$p];
      }
    }
    update_post_meta($post_id, self::PMK, $out);
  }

  # ===== Front =====
  public function front_assets(){
    wp_enqueue_style('rid-front', plugin_dir_url(__FILE__).'assets/rid-front.css', [], '2.0.0');

    // cor do tema fallback
    $o = get_option(self::OPT, []);
    $primary = function_exists('sanitize_hex_color') ? sanitize_hex_color($o['primary_color'] ?? '#3b82f6') : ($o['primary_color'] ?? '#3b82f6');
    wp_add_inline_style('rid-front', ":root{--rid-primary: {$primary};}");

    // pegamos a cor primária real do tema (se existir) e sobrescrevemos
    wp_enqueue_script('rid-front', plugin_dir_url(__FILE__).'assets/rid-front.js', [], '2.0.0', true);
  }

  // ==== Helpers (lojas) ====
  private function parse_gplay_id($v){
    if (!$v) return null;
    if (preg_match('~^https?://~i',$v)){ $q=[]; parse_str(parse_url($v, PHP_URL_QUERY) ?: '', $q); return $q['id'] ?? null; }
    return $v;
  }
  private function parse_appstore_id($v){
    if (!$v) return null;
    if (preg_match('/id(\d+)/', $v, $m)) return $m[1];
    return preg_match('/^\d+$/',$v) ? $v : null;
  }
  private function clean_play_title($name){
    return preg_replace('/\s+[–-]\s+Apps on Google Play$/i','', $name);
  }

  // Busca nome/ícone/rating/size/price (Play+iOS). Preferimos o nome da Play.
  private function enrich_meta($gplayUrl, $appstoreUrl){
    $key = 'rid_meta_'.md5($gplayUrl.'|'.$appstoreUrl);
    if ($cached = get_transient($key)) return $cached;
    $out = [];

    // Play
    if ($gplayUrl){
      $id = $this->parse_gplay_id($gplayUrl);
      if ($id){
        $url = "https://play.google.com/store/apps/details?id={$id}&hl=en&gl=US";
        $r = wp_remote_get($url, ['timeout'=>8,'headers'=>['User-Agent'=>'Mozilla/5.0']]);
        if (!is_wp_error($r)){
          $html = wp_remote_retrieve_body($r);
          if (preg_match('/property="og:title"\s+content="([^"]+)"/i',$html,$m))
            $out['name'] = $this->clean_play_title(html_entity_decode($m[1]));
          if (preg_match('/property="og:image"\s+content="([^"]+)"/i',$html,$m))
            $out['icon'] = html_entity_decode($m[1]);
          if (preg_match('/"aggregateRating":\{"@type":"AggregateRating","ratingValue":"([^"]+)"/',$html,$m))
            $out['rating'] = $m[1];
          $out['gplay'] = "https://play.google.com/store/apps/details?id={$id}";
        }
      }
    }
    // iOS
    if ($appstoreUrl){
      $id = $this->parse_appstore_id($appstoreUrl);
      if ($id){
        $r = wp_remote_get("https://itunes.apple.com/lookup?id={$id}&country=us", ['timeout'=>8]);
        if (!is_wp_error($r)){
          $j = json_decode(wp_remote_retrieve_body($r), true);
          if (!empty($j['results'][0])){
            $it = $j['results'][0];
            if (empty($out['name']) && !empty($it['trackName'])) $out['name'] = $it['trackName'];
            if (empty($out['icon']) && !empty($it['artworkUrl100'])) $out['icon'] = str_replace('100x100bb','512x512bb',$it['artworkUrl100']);
            if (!empty($it['averageUserRating'])) $out['rating'] = $out['rating'] ?? $it['averageUserRating'];
            if (!empty($it['fileSizeBytes'])) $out['size'] = round($it['fileSizeBytes']/1048576,1).'MB';
            if (isset($it['price'])) $out['price'] = ($it['price']>0 ? '$'.$it['price'] : 'Free');
            if (!empty($it['trackViewUrl'])) $out['appstore'] = $it['trackViewUrl'];
          }
        }
      }
    }

    set_transient($key, $out, 12 * HOUR_IN_SECONDS);
    return $out;
  }

  private function build_go($url, $name=''){
    $o = get_option(self::OPT, []);
    $ttl = max(1, intval($o['token_ttl'] ?? 10));
    $token = wp_generate_password(20, false, false);
    $data = ['url'=>esc_url_raw($url), 'name'=>sanitize_text_field($name), 'ts'=>time()];
    set_transient('rid_'.$token, $data, $ttl * MINUTE_IN_SECONDS);
    return home_url('/go/'.$token.'/');
  }

  // Render do card (1 app)
  private function render_card($gplayUrl, $appstoreUrl){
    $m = $this->enrich_meta($gplayUrl, $appstoreUrl);
    $name = $m['name'] ?? 'Aplicativo';
    $icon = $m['icon'] ?? '';
    $rating = '';
    if (!empty($m['rating'])){
      $num = floatval(str_replace(',', '.', (string)$m['rating']));
      if ($num > 0) $rating = number_format($num, 1, ',', '');
    }
    $size = $m['size'] ?? '';
    $price = isset($m['price']) ? $m['price'] : 'Free';
    $platforms = ($gplayUrl?'Android':'') . (($gplayUrl && $appstoreUrl)?'/':'') . ($appstoreUrl?'iOS':'');
    $gplayBtn = $gplayUrl ? $this->build_go($gplayUrl, $name) : '';
    $iosBtn   = $appstoreUrl ? $this->build_go($appstoreUrl, $name) : '';

    ob_start(); ?>
    <section class="rid-card">
      <div class="rid-head">
        <?php if($icon): ?><img class="rid-icon" src="<?php echo esc_url($icon); ?>" alt="<?php echo esc_attr($name); ?>"><?php endif; ?>
        <h2 class="rid-title"><?php echo esc_html($name); ?></h2>
        <?php if($rating): ?><span class="rid-rate">★ <?php echo esc_html($rating); ?></span><?php endif; ?>
      </div>
      <div class="rid-grid">
        <?php if($platforms): ?><div><small>Plataforma</small><strong><?php echo esc_html($platforms); ?></strong></div><?php endif; ?>
        <?php if($size): ?><div><small>Tamanho</small><strong><?php echo esc_html($size); ?></strong></div><?php endif; ?>
        <div><small>Preço</small><strong><?php echo esc_html($price); ?></strong></div>
      </div>
      <div class="rid-btns">
        <?php if($gplayBtn): ?><a class="rid-btn rid-btn--primary" rel="nofollow" href="<?php echo esc_url($gplayBtn); ?>">Baixar no Google Play</a><?php endif; ?>
        <?php if($iosBtn):   ?><a class="rid-btn rid-btn--primary" rel="nofollow" href="<?php echo esc_url($iosBtn); ?>">Baixar na App Store</a><?php endif; ?>
      </div>
      <p class="rid-note">As informações sobre tamanho, instalações e avaliação podem variar conforme atualizações nas lojas oficiais.</p>
    </section>
    <?php
    return ob_get_clean();
  }

  // Insere vários cards nos parágrafos escolhidos
  public function inject_cards($content){
    if (!is_singular('post') || !in_the_loop() || !is_main_query()) return $content;
    $apps = get_post_meta(get_the_ID(), self::PMK, true);
    if (!is_array($apps) || empty($apps)) return $content;

    // transforma conteúdo em blocos de parágrafo
    $closing = '</p>';
    $parts = explode($closing, $content);
    // ordenar por posição
    usort($apps, function($a,$b){ return intval($a['pos'] ?? 0) <=> intval($b['pos'] ?? 0); });

    // inserção progressiva (0 = antes de tudo)
    $bufferBefore = '';
    foreach ($apps as $app){
      $g = $app['gplay'] ?? '';
      $a = $app['appstore'] ?? '';
      $p = max(0, intval($app['pos'] ?? 0));
      $card = $this->render_card($g, $a);
      if ($p === 0){ $bufferBefore .= $card; continue; }
      // se o texto tiver menos <p> que p, empurra pro final
      $idx = min($p, count($parts));
      // reconstruir adicionando card logo após o p escolhido
      $parts[$idx-1] = $parts[$idx-1] . $closing . $card;
    }

    $html = implode($closing, $parts);
    return $bufferBefore.$html;
  }

  // Shortcode manual (debug)
  public function shortcode_single($atts){
    $a = shortcode_atts(['gplay'=>'','appstore'=>''], $atts);
    return $this->render_card($a['gplay'], $a['appstore']);
  }

  # ===== /go/ =====
  public function register_go_endpoint(){
    add_rewrite_rule('^go/([A-Za-z0-9\-_]+)/?$', 'index.php?'.self::QV.'=$matches[1]', 'top');
    add_rewrite_tag('%'.self::QV.'%','([A-Za-z0-9\-_]+)');
  }
  public function on_activate(){ $this->register_go_endpoint(); flush_rewrite_rules(); }

  public function handle_go_request(){
    $token = get_query_var(self::QV); if (!$token) return;
    $opts = get_option(self::OPT, []); $hold_ms = intval($opts['hold_ms'] ?? 150);
    $data = get_transient('rid_'.$token);
    if (!$data || empty($data['url'])) { wp_safe_redirect(home_url('/')); exit; }
    $final = esc_url_raw($data['url']);
    status_header(200); nocache_headers(); ?>
<!doctype html><html lang="pt-br"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Redirecionando…</title><meta name="robots" content="noindex,nofollow">
<script>(function(){var u=<?php echo json_encode($final); ?>,d=<?php echo json_encode($hold_ms); ?>;function go(){location.href=u;} if(d>0) setTimeout(go,d); else go();})();</script>
<noscript><meta http-equiv="refresh" content="0;url=<?php echo esc_attr($final); ?>"></noscript>
</head><body></body></html>
<?php exit; }
}

new RedirectID_AD();
