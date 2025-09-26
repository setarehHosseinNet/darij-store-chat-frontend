<?php
/**
 * Plugin Name: Darij – Store Chat Popup (FA-RTL)
 * Description: پاپ‌آپ چت برای مشتری (بدون ایمیل) + صندوق ورودی مدیر + صفحه پاسخ اختصاصی هر کاربر + نوتیفیکیشن داخلی برای نقش‌های مدیر و مدیر فروشگاه.
 * Version: 1.1.0
 * Author: hossein Setareh
 * Author URI: https://github.com/setarehHosseinNet/darij-store-chat-frontend
 * License: GPLv2 or later
 * Text Domain: darij-chat
 */

if (!defined('ABSPATH')) exit;

class Darij_Store_Chat_Popup {
  const COOKIE = 'drj_chat_thread';
  const CPT    = 'drj_chat_thread';
  const NS     = 'drj-chat/v1';

  public function __construct(){
    add_action('init',               [$this, 'register_cpt']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
    add_action('wp_footer',          [$this, 'render_popup']);
    add_action('admin_enqueue_scripts',[$this,'admin_assets']);
    add_action('admin_bar_menu',     [$this, 'admin_bar'], 90);

    add_action('admin_menu',         [$this, 'admin_menu']);
    add_action('add_meta_boxes',     [$this, 'metabox_messages']);

    add_shortcode('store_chat_popup',[$this, 'shortcode']);

    // REST
    add_action('rest_api_init', function(){
      register_rest_route(self::NS, '/send', [
        'methods'  => 'POST',
        'callback' => [$this, 'api_send'],
        'permission_callback' => function(){
          $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
          return is_user_logged_in() || wp_verify_nonce($nonce, 'wp_rest');
        }
      ]);
      register_rest_route(self::NS, '/fetch', [
        'methods'  => 'GET',
        'callback' => [$this, 'api_fetch'],
        'permission_callback' => '__return_true',
        'args' => ['since'=>['type'=>'integer','default'=>0]]
      ]);
      register_rest_route(self::NS, '/admin_list', [
        'methods'  => 'GET',
        'callback' => [$this, 'api_admin_list'],
        'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_woocommerce'); }
      ]);
      register_rest_route(self::NS, '/admin_reply', [
        'methods'  => 'POST',
        'callback' => [$this, 'api_admin_reply'],
        'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_woocommerce'); }
      ]);
      register_rest_route(self::NS, '/thread_mark_read', [
        'methods'  => 'POST',
        'callback' => [$this, 'api_thread_mark_read'],
        'permission_callback' => function(){ return current_user_can('edit_posts') || current_user_can('manage_woocommerce'); }
      ]);
    });

    // هر دیدگاهی که مدیر/شاپ‌منیجر بگذارد به عنوان «اپراتور» تگ شود
    add_action('comment_post', function($id){
      $uid = get_current_user_id();
      if ($uid && ( user_can($uid,'edit_posts') || user_can($uid,'manage_woocommerce') || in_array('shop_manager',(array)get_userdata($uid)->roles,true) )){
        add_comment_meta($id, 'who', 'bot', true);
        // پس از پاسخ، نخ خوانده شده تلقی شود
        $c = get_comment($id);
        if ($c && $c->comment_post_ID) {
          delete_post_meta($c->comment_post_ID, '_drj_admin_unread');
          $this->recalc_unread_total();
        }
      }
    });

    // AJAX برای نوتیفیکیشن تولبار
    add_action('wp_ajax_drj_chat_unread_count', [$this,'ajax_unread_count']);
  }

  /* ====== Data model ====== */
  public function register_cpt(){
    register_post_type(self::CPT, [
      'label' => __('Chat Threads', 'darij-chat'),
      'labels'=> [
        'singular_name' => __('Chat Thread','darij-chat'),
        'menu_name'     => __('Store Chat','darij-chat'),
      ],
      'public'       => false,
      'show_ui'      => true,
      'show_in_menu' => true,
      'supports'     => ['title','author','custom-fields','comments'],
      'menu_icon'    => 'dashicons-format-chat',
    ]);
  }

  private function ensure_thread_id(){
    if (!empty($_COOKIE[self::COOKIE])) {
      $pid = absint($_COOKIE[self::COOKIE]);
      if ($pid && get_post($pid)) return $pid;
    }
    $login = is_user_logged_in() ? wp_get_current_user()->user_login : '';
    $title = 'Chat – '.($login ?: ('Guest '.wp_generate_password(6, false)));
    $pid = wp_insert_post([
      'post_type'      => self::CPT,
      'post_status'    => 'publish',
      'post_title'     => $title,
      'post_author'    => get_current_user_id(),
      'comment_status' => 'open',
    ]);
    if ($pid && !is_wp_error($pid)) {
      setcookie(self::COOKIE, (string)$pid, time()+60*60*24*30, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);
      $_COOKIE[self::COOKIE] = (string)$pid;
      return (int)$pid;
    }
    return 0;
  }

  /* ====== Frontend ====== */
  public function enqueue_assets(){
    if (is_admin()) return;
    $ver = '1.1.0';

    // CSS
    wp_register_style('darij-chat', false, [], $ver);
    wp_enqueue_style('darij-chat');
    wp_add_inline_style('darij-chat', $this->inline_css());

    // JS
    wp_register_script('darij-chat', false, [], $ver, true);
    wp_enqueue_script('darij-chat');
    $data = [ 'rest'  => esc_url_raw(rest_url(self::NS)), 'nonce' => wp_create_nonce('wp_rest') ];
    wp_add_inline_script('darij-chat', 'window.DarijChatData='.wp_json_encode($data).';', 'before');
    wp_add_inline_script('darij-chat', $this->inline_js_front());
  }

  public function render_popup(){ echo $this->html_popup(); }
  public function shortcode(){ return $this->html_popup(); }

  private function html_popup(){
    ob_start(); ?>
    <div id="drj-chat-root" dir="rtl" aria-live="polite">
      <button id="drj-chat-fab" aria-haspopup="dialog" aria-controls="drj-chat-dialog" title="گفت‌وگو">💬</button>
      <div id="drj-chat-dialog" role="dialog" aria-modal="true" aria-labelledby="drj-chat-title" hidden>
        <div class="drj-chat-card">
          <div class="drj-chat-head">
            <strong id="drj-chat-title">گفت‌وگو با فروشگاه</strong>
            <button id="drj-chat-close" aria-label="بستن">×</button>
          </div>
          <div class="drj-chat-body" id="drj-chat-body">
            <div class="drj-msg drj-bot">🤖 <?php echo esc_html__('سلام! هر سوالی دارید بپرسید.', 'darij-chat'); ?></div>
          </div>
          <form class="drj-chat-form" id="drj-chat-form">
            <input type="text" id="drj-chat-name" name="guest_name" placeholder="<?php echo esc_attr__('نام (اختیاری)','darij-chat'); ?>">
            <input type="text" id="drj-chat-input" name="message" autocomplete="off" placeholder="<?php echo esc_attr__('پیامتان را بنویسید...','darij-chat'); ?>" required>
            <button type="submit" id="drj-chat-send"><?php echo esc_html__('ارسال','darij-chat'); ?></button>
          </form>
        </div>
      </div>
    </div>
    <?php return ob_get_clean(); }

  private function inline_css(){
    return <<<CSS
#drj-chat-root{position:fixed;bottom:20px;inset-inline-end:20px;z-index:999999;font-family:inherit}
#drj-chat-fab{width:56px;height:56px;border-radius:999px;border:0;background:linear-gradient(135deg,#3b82f6,#1d4ed8);color:#fff;box-shadow:0 10px 24px rgba(29,78,216,.35);cursor:pointer;font-size:22px;transition:transform .12s ease, box-shadow .12s ease}
#drj-chat-fab:hover,#drj-chat-fab:focus-visible{transform:translateY(-1px);box-shadow:0 14px 30px rgba(29,78,216,.45);outline:none}
#drj-chat-dialog[hidden]{display:none}
#drj-chat-dialog{position:fixed;bottom:90px;inset-inline-end:20px}
.drj-chat-card{width:min(360px,90vw);max-height:min(70vh,600px);display:flex;flex-direction:column;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,.25)}
.drj-chat-head{background:#111;color:#fff;padding:10px 12px;display:flex;align-items:center;justify-content:space-between}
#drj-chat-close{background:transparent;border:0;color:#fff;font-size:20px;cursor:pointer}
.drj-chat-body{background:#f8f8f8;padding:10px;overflow:auto;display:flex;flex-direction:column;gap:8px}
.drj-msg{padding:8px 12px;border-radius:12px;max-width:90%}
.drj-user{background:#DCF8C6;align-self:flex-end;border-start-end-radius:4px}
.drj-bot{background:#eee;align-self:flex-start;border-start-start-radius:4px}
.drj-chat-form{display:flex;gap:6px;padding:10px;background:#fff;border-top:1px solid #eee}
#drj-chat-input{flex:1;padding:10px;border:1px solid #ddd;border-radius:10px}
#drj-chat-name{width:35%;padding:10px;border:1px solid #ddd;border-radius:10px}
#drj-chat-send{padding:10px 14px;border:0;border-radius:10px;background:#0d6efd;color:#fff;cursor:pointer}
@media (max-width:480px){#drj-chat-name{display:none}}
CSS;
  }

  private function inline_js_front(){
    return <<<JS
(function(){
  const conf = window.DarijChatData || {}; 
  const btn  = document.getElementById('drj-chat-fab');
  const dlg  = document.getElementById('drj-chat-dialog');
  const body = document.getElementById('drj-chat-body');
  const form = document.getElementById('drj-chat-form');
  const input= document.getElementById('drj-chat-input');
  const name = document.getElementById('drj-chat-name');
  let lastId = 0, timer = null, sending=false;

  function open(){ dlg.hidden=false; poll(); setTimeout(()=>input&&input.focus(), 0); }
  function close(){ dlg.hidden=true; if(timer){ clearInterval(timer); timer=null; } }
  function scrollBottom(){ body.scrollTop = body.scrollHeight; }

  btn && btn.addEventListener('click', open);
  document.getElementById('drj-chat-close')?.addEventListener('click', close);

  form && form.addEventListener('submit', async function(e){
    e.preventDefault(); const text=(input?.value||'').trim(); if(!text||sending) return; sending=true; append('you', text); input.value='';
    try{ await fetch(conf.rest+'/send',{ method:'POST', headers:{'Content-Type':'application/json','X-WP-Nonce':conf.nonce}, body:JSON.stringify({message:text, guest_name:name?.value||''}) }); }catch(err){ console.error(err); }
    sending=false; });

  function append(who,text){ const div=document.createElement('div'); div.className='drj-msg '+(who==='you'?'drj-user':'drj-bot'); div.textContent=text; body.appendChild(div); scrollBottom(); }
  async function fetchNew(){ try{ const res=await fetch(conf.rest+'/fetch?since='+lastId,{credentials:'same-origin'}); if(!res.ok) return; const data=await res.json(); (data.messages||[]).forEach(m=>{ lastId=Math.max(lastId,m.id); append(m.who==='user'?'you':'bot', m.text); }); }catch(e){}
  }
  function poll(){ if(timer) return; fetchNew(); timer=setInterval(fetchNew, 5000); }
  if(location.hash==='#chat') open();
})();
JS;
  }

  /* ====== REST: Front ====== */
  public function api_send($req){
    $pid = $this->ensure_thread_id(); if (!$pid) return ['ok'=>false,'error'=>'thread'];
    $msg = sanitize_text_field($req->get_param('message')); $n = sanitize_text_field($req->get_param('guest_name'));
    if (!$msg) return ['ok'=>false,'error'=>'empty']; if ($n) setcookie('drj_guest_name', $n, time()+60*60*24*30, COOKIEPATH ?: '/', COOKIE_DOMAIN ?: '', is_ssl(), true);

    $uid = get_current_user_id() ?: 0;
    $cmt_id = wp_insert_comment([
      'comment_post_ID'=>$pid,'comment_author'=>$uid?(wp_get_current_user()->display_name?:'کاربر'):($n?:'میهمان'), 'user_id'=>$uid,'comment_content'=>$msg,'comment_approved'=>1,
    ]);
    if ($cmt_id && !is_wp_error($cmt_id)){
      add_comment_meta($cmt_id,'who','user',true);
      // نوتیفیکیشن داخلی: نخ به عنوان «خوانده نشده برای ادمین» علامت بخورد
      update_post_meta($pid, '_drj_admin_unread', 1);
      $this->recalc_unread_total();
      return ['ok'=>true,'id'=>(int)$cmt_id];
    }
    return ['ok'=>false];
  }

  public function api_fetch($req){
    $pid = $this->ensure_thread_id(); if (!$pid) return ['ok'=>false,'error'=>'thread'];
    $since = absint($req->get_param('since'));
    $comments = get_comments(['post_id'=>$pid,'status'=>'approve','orderby'=>'comment_ID','order'=>'ASC','number'=>200,'offset'=>0]);
    $messages=[]; foreach($comments as $c){ if($c->comment_ID <= $since) continue; $who=get_comment_meta($c->comment_ID,'who',true)?:'user'; $messages[]=['id'=>(int)$c->comment_ID,'text'=>wp_strip_all_tags($c->comment_content),'who'=>$who,'t'=>mysql2date('U',$c->comment_date_gmt)]; }
    return ['ok'=>true,'messages'=>$messages];
  }

  /* ====== Admin UI & APIs ====== */
  public function admin_menu(){
    add_menu_page(__('Store Chat','darij-chat'), __('گفتگوها','darij-chat'), 'edit_posts', 'drj-chat-inbox', [$this,'screen_inbox'], 'dashicons-format-chat', 56);
    add_submenu_page('drj-chat-inbox', __('Inbox','darij-chat'), __('صندوق ورودی','darij-chat'), 'edit_posts', 'drj-chat-inbox', [$this,'screen_inbox']);
    add_submenu_page('drj-chat-inbox', __('Reply','darij-chat'), __('پاسخ','darij-chat'), 'edit_posts', 'drj-chat-reply', [$this,'screen_reply']);
  }

  public function screen_inbox(){
    if (!current_user_can('edit_posts') && !current_user_can('manage_woocommerce')) return;
    echo '<div class="wrap" dir="rtl"><h1>صندوق ورودی گفتگوها</h1><p>پیام‌های جدید با برچسب <span style="background:#ffe8a1;padding:2px 6px;border-radius:6px">خوانده نشده</span> مشخص شده‌اند.</p>';
    echo '<div id="drj-chat-admin-list">در حال بارگذاری...</div></div>';
    $this->print_admin_list_js();
  }

  public function screen_reply(){
    if (!current_user_can('edit_posts') && !current_user_can('manage_woocommerce')) return;
    $thread_id = absint($_GET['thread'] ?? 0);
    if (!$thread_id || get_post_type($thread_id)!==self::CPT){ echo '<div class="wrap"><h2>نخ نامعتبر</h2></div>'; return; }
    // وقتی صفحه پاسخ باز شد، نخ را خوانده شده علامت بزن
    delete_post_meta($thread_id, '_drj_admin_unread');
    $this->recalc_unread_total();

    $post = get_post($thread_id);
    echo '<div class="wrap" dir="rtl">';
    echo '<h1>پاسخ به «'.esc_html($post->post_title).'»</h1>';
    echo '<div id="drj-chat-thread" style="max-width:900px">';

    $comments = get_comments(['post_id'=>$thread_id,'status'=>'approve','orderby'=>'comment_ID','order'=>'ASC','number'=>500]);
    echo '<div class="drj-admin-thread" style="background:#fff;border:1px solid #eee;border-radius:12px;padding:12px;max-height:60vh;overflow:auto">';
    foreach($comments as $c){
      $who = get_comment_meta($c->comment_ID,'who',true)?:'user';
      $bg  = $who==='user'? '#f6ffed' : '#eef6ff';
      printf('<div style="background:%s;margin:8px 0;padding:8px 12px;border-radius:10px"><strong>%s:</strong> %s <span style="opacity:.6;font-size:11px">(%s)</span></div>',
        esc_attr($bg), esc_html($who==='user'?'کاربر':'اپراتور'), esc_html($c->comment_content), esc_html($c->comment_date));
    }
    echo '</div>';

    echo '<form id="drj-admin-reply-form" style="margin-top:12px;display:flex;gap:8px">';
    echo '<input type="hidden" id="drj-thread-id" value="'.esc_attr($thread_id).'">';
    echo '<input type="text" id="drj-reply-text" class="regular-text" style="flex:1" placeholder="پاسخ خود را بنویسید..." required>'; 
    echo '<button type="submit" class="button button-primary">ارسال پاسخ</button>';
    echo '</form>';

    echo '</div></div>';
    $this->print_admin_reply_js();
  }

  public function api_admin_list(){
    $q = new WP_Query(['post_type'=>self::CPT,'post_status'=>'publish','posts_per_page'=>50,'orderby'=>'date','order'=>'DESC']);
    $items = [];
    foreach($q->posts as $p){
      $last = get_comments(['post_id'=>$p->ID,'number'=>1,'orderby'=>'comment_ID','order'=>'DESC']);
      $last_txt = $last? wp_trim_words(wp_strip_all_tags($last[0]->comment_content), 16) : '';
      $unread = (bool) get_post_meta($p->ID,'_drj_admin_unread', true);
      $items[] = [
        'id'=>$p->ID,
        'title'=>$p->post_title,
        'date'=>mysql2date('Y-m-d H:i', $p->post_date),
        'last'=>$last_txt,
        'unread'=>$unread,
      ];
    }
    return ['ok'=>true,'items'=>$items];
  }

  public function api_admin_reply($req){
    $pid = absint($req->get_param('thread_id'));
    $msg = sanitize_text_field($req->get_param('message'));
    if (!$pid || get_post_type($pid)!==self::CPT) return ['ok'=>false,'error'=>'thread'];
    if (!$msg) return ['ok'=>false,'error'=>'empty'];

    $uid = get_current_user_id() ?: 0;
    $cmt_id = wp_insert_comment([
      'comment_post_ID'=>$pid,
      'comment_author'=>$uid? (wp_get_current_user()->display_name ?: 'مدیر'): 'مدیر',
      'user_id'=>$uid,
      'comment_content'=>$msg,
      'comment_approved'=>1,
    ]);
    if ($cmt_id && !is_wp_error($cmt_id)){
      add_comment_meta($cmt_id,'who','bot',true);
      // برای کاربر، این پیام در فرانت نمایش داده خواهد شد (polling)
      delete_post_meta($pid, '_drj_admin_unread');
      $this->recalc_unread_total();
      return ['ok'=>true,'id'=>(int)$cmt_id];
    }
    return ['ok'=>false];
  }

  public function api_thread_mark_read($req){
    $pid = absint($req->get_param('thread_id'));
    if ($pid && get_post_type($pid)===self::CPT){ delete_post_meta($pid,'_drj_admin_unread'); $this->recalc_unread_total(); return ['ok'=>true]; }
    return ['ok'=>false];
  }

  /* ====== Admin helpers ====== */
  private function print_admin_list_js(){
    $rest = esc_url_raw(rest_url(self::NS));
    echo '<script>!function(){const box=document.getElementById("drj-chat-admin-list");if(!box)return;async function load(){try{const r=await fetch("'.$rest.'/admin_list");const d=await r.json();if(!d.ok){box.innerHTML="خطا در بارگذاری";return}const rows=d.items.map(it=>`<tr><td>${it.id}</td><td>${it.title}${it.unread?" <span style=\'background:#ffe8a1;padding:2px 6px;border-radius:6px\'>خوانده نشده</span>":""}</td><td>${it.last||""}</td><td style=\'white-space:nowrap\'>${it.date}</td><td><a class=\'button button-primary\' href=\'admin.php?page=drj-chat-reply&thread=${it.id}\'>باز کردن</a></td></tr>`).join("");box.innerHTML=`<table class=\'widefat striped\'><thead><tr><th>ID</th><th>عنوان</th><th>آخرین پیام</th><th>تاریخ</th><th></th></tr></thead><tbody>${rows}</tbody></table>`}catch(e){box.innerHTML="خطا"}}load()}();</script>';
  }

  private function print_admin_reply_js(){
    $rest = esc_url_raw(rest_url(self::NS));
    echo '<script>!function(){const f=document.getElementById("drj-admin-reply-form"),t=document.getElementById("drj-thread-id"),i=document.getElementById("drj-reply-text");if(!f)return;f.addEventListener("submit",async e=>{e.preventDefault();const msg=(i.value||"").trim();if(!msg)return;const r=await fetch("'.$rest.'/admin_reply",{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({thread_id:t.value,message:msg})});const d=await r.json();if(d&&d.ok)location.reload();});}();</script>';
  }

  public function admin_assets($hook){
    if (!current_user_can('edit_posts') && !current_user_can('manage_woocommerce')) return;
    // اسکریپت تولبار برای نوتیفیکیشن شمارنده
    wp_add_inline_script('jquery-core', 'jQuery(function($){function tick(){ $.post(ajaxurl,{action:"drj_chat_unread_count"},function(res){try{var d=JSON.parse(res||"{}"),c=parseInt(d.count||0,10);var b=$("#wp-admin-bar-drj-chat > a .ab-label"); if(b.length){ b.text(c>0?"گفتگوها ("+c+")":"گفتگوها"); } }catch(e){} }); } tick(); setInterval(tick, 10000); });');
  }

  public function admin_bar($bar){
    if (!is_admin() || (!current_user_can('edit_posts') && !current_user_can('manage_woocommerce'))) return;
    $count = (int) get_option('drj_chat_unread_total', 0);
    $bar->add_menu([
      'id'    => 'drj-chat',
      'title' => '💬 <span class="ab-label">'.($count>0? 'گفتگوها ('.$count.')':'گفتگوها').'</span>',
      'href'  => admin_url('admin.php?page=drj-chat-inbox'),
      'meta'  => ['title'=>'گفتگوهای فروشگاه']
    ]);
  }

  public function ajax_unread_count(){
    if (!current_user_can('edit_posts') && !current_user_can('manage_woocommerce')) wp_die();
    wp_die(wp_json_encode(['count'=>(int)get_option('drj_chat_unread_total',0)]));
  }

  private function recalc_unread_total(){
    $q = new WP_Query(['post_type'=>self::CPT,'post_status'=>'publish','posts_per_page'=>-1,'meta_key'=>'_drj_admin_unread','meta_value'=>1]);
    update_option('drj_chat_unread_total', (int)$q->found_posts, false);
  }

  /* ====== Metabox (history) ====== */
  public function metabox_messages(){
    add_meta_box('drj_chat_msgs', __('Messages','darij-chat'), function($post){
      $comments = get_comments(['post_id'=>$post->ID, 'status'=>'approve', 'orderby'=>'comment_ID','order'=>'ASC', 'number'=>500]);
      echo '<div style="max-height:400px;overflow:auto;background:#f9f9f9;padding:10px">';
      foreach($comments as $c){ $who=get_comment_meta($c->comment_ID,'who',true)?:'user'; $badge=$who==='user'?'کاربر':'اپراتور'; printf('<p><strong>%s:</strong> %s <span style="opacity:.6;font-size:11px">(%s)</span></p>', esc_html($badge), esc_html($c->comment_content), esc_html($c->comment_date)); }
      echo '</div>';
      echo '<p style="margin-top:10px;">مدیر می‌تواند از صفحه «پاسخ» نیز جواب بدهد.</p>';
    }, self::CPT, 'normal', 'default');
  }
}

new Darij_Store_Chat_Popup();
