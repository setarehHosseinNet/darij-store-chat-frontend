<?php
/**
 * Plugin Name: Darij – Store Chat Frontend Console
 * Description: نمایش «صندوق گفتگوها» و «صفحه پاسخ» در فرانت‌اند با شورتکد. مناسب المنتور/گوتنبرگ.
 * Version: 1.2.0
 * Author: hossein Setareh
 * Author URI: https://github.com/setarehHosseinNet/darij-store-chat-frontend
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

class Darij_Store_Chat_Frontend {
  const NS  = 'drj-chat/v1';      // REST namespace از افزونهٔ اصلی
  const CPT = 'drj_chat_thread';  // نوع پست نخ گفتگو

  public function __construct() {
    add_shortcode('drj_chat_inbox',  [$this,'sc_inbox']);
    add_shortcode('drj_chat_reply',  [$this,'sc_reply']);
    add_action('wp_enqueue_scripts', [$this,'assets']);
  }

  private function can_manage() {
    return is_user_logged_in() && ( current_user_can('edit_posts') || current_user_can('manage_woocommerce') );
  }

  public function assets() {
    if (!$this->can_manage()) return;
    $ver = '1.2.0';
    wp_register_style('drj-chat-frontend', false, [], $ver);
    wp_enqueue_style('drj-chat-frontend');
    wp_add_inline_style(
      'drj-chat-frontend',
      '#drj-fe{direction:rtl;font-family:inherit}'.
      '.drj-box{background:#fff;border:1px solid #eee;border-radius:12px;padding:12px}'.
      '.drj-table{width:100%;border-collapse:collapse}'.
      '.drj-table th,.drj-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;text-align:right}'.
      '.drj-badge{background:#ffe8a1;padding:2px 8px;border-radius:8px;font-size:12px}'.
      '.drj-msg-user{background:#f6ffed;padding:8px 12px;border-radius:10px;margin:8px 0}'.
      '.drj-msg-op{background:#eef6ff;padding:8px 12px;border-radius:10px;margin:8px 0}'.
      '.drj-row-actions a{display:inline-block;text-decoration:none;padding:.35rem .6rem;border-radius:8px;background:#0d6efd;color:#fff}'.
      '.drj-form{display:flex;gap:8px;margin-top:12px}'.
      '.drj-form input[type=text]{flex:1;padding:10px;border:1px solid #ddd;border-radius:10px}'.
      '.drj-form button{padding:10px 14px;border:0;border-radius:10px;background:#0d6efd;color:#fff;cursor:pointer}'
    );
  }

  /* ============ Shortcode: Inbox (با ستون «کاربر») ============ */
  public function sc_inbox($atts) {
    if (!$this->can_manage()) return '<div id="drj-fe">403 – دسترسی ندارید.</div>';

    $a = shortcode_atts([
      'reply_page' => '', // مثال: /drj_chat_reply/ یا URL کامل
    ], $atts, 'drj_chat_inbox');

    $reply_url = '';
    if (!empty($a['reply_page'])) {
      $reply_url = (strpos($a['reply_page'], 'http') === 0) ? $a['reply_page'] : home_url($a['reply_page']);
    } else {
      // تلاش برای یافتن برگه‌ای با اسلاگ drj_chat_reply
      $reply_page = get_page_by_path('drj_chat_reply');
      $reply_url  = $reply_page ? get_permalink($reply_page) : get_permalink();
    }

    $q = new WP_Query([
      'post_type'      => self::CPT,
      'post_status'    => 'publish',
      'posts_per_page' => 50,
      'orderby'        => 'date',
      'order'          => 'DESC',
    ]);

    ob_start();
    echo '<div id="drj-fe"><h2>صندوق ورودی گفتگوها</h2><div class="drj-box">';
    echo '<table class="drj-table"><thead><tr>
            <th>ID</th><th>عنوان</th><th>کاربر</th><th>آخرین پیام</th><th>تاریخ</th><th></th>
          </tr></thead><tbody>';

    foreach ($q->posts as $p) {
      $last = get_comments(['post_id'=>$p->ID,'number'=>1,'orderby'=>'comment_ID','order'=>'DESC']);
      $last_txt = $last ? wp_trim_words(wp_strip_all_tags($last[0]->comment_content), 16) : '';
      $unread = (bool) get_post_meta($p->ID,'_drj_admin_unread', true);

      $user_login = get_post_meta($p->ID, '_drj_user_login', true); // متای ست‌شده در افزونهٔ اصلی
      $username   = $user_login ?: 'میهمان';
      $title_show = $user_login ? $user_login : $p->post_title;

      $open_link = add_query_arg('thread', $p->ID, $reply_url);

      echo '<tr>'.
           '<td>'.(int)$p->ID.'</td>'.
           '<td>'.esc_html($title_show).($unread?' <span class="drj-badge">خوانده نشده</span>':'').'</td>'.
           '<td>'.esc_html($username).'</td>'.
           '<td>'.esc_html($last_txt).'</td>'.
           '<td>'.esc_html(mysql2date('Y-m-d H:i', $p->post_date)).'</td>'.
           '<td class="drj-row-actions"><a href="'.esc_url($open_link).'">باز کردن</a></td>'.
           '</tr>';
    }

    echo '</tbody></table></div></div>';
    return ob_get_clean();
  }

  /* ============ Shortcode: Reply Thread ============ */
  public function sc_reply($atts) {
    if (!$this->can_manage()) return '<div id="drj-fe">403 – دسترسی ندارید.</div>';

    $a = shortcode_atts([
      'thread' => '', // مثال: [drj_chat_reply thread="3073"]
    ], $atts, 'drj_chat_reply');

    $thread_id = 0;
    if (!empty($a['thread'])) $thread_id = absint($a['thread']);
    if (!$thread_id && isset($_GET['thread'])) $thread_id = absint($_GET['thread']);

    // اگر ID نداریم (پریویو المنتور)، انتخاب‌گر نخ نشان بده
    if (!$thread_id) {
      $q = new WP_Query([
        'post_type'=>self::CPT,'post_status'=>'publish','posts_per_page'=>30,'orderby'=>'date','order'=>'DESC'
      ]);
      $self = get_permalink();
      ob_start();
      echo '<div id="drj-fe"><h2>انتخاب گفتگو</h2><div class="drj-box"><form method="get" action="'.esc_url($self).'">';
      echo '<select name="thread" style="min-width:240px;padding:8px;border:1px solid #ddd;border-radius:8px">';
      foreach ($q->posts as $p) {
        $user_login = get_post_meta($p->ID, '_drj_user_login', true);
        $title_show = $user_login ? $user_login : $p->post_title;
        echo '<option value="'.(int)$p->ID.'">'.esc_html($title_show).' (#'.(int)$p->ID.')</option>';
      }
      echo '</select> <button type="submit" class="button">باز کردن</button></form></div>'.
           '<p style="opacity:.8;margin-top:8px">نکته: می‌توانید در خود شورتکد هم `thread="ID"` بدهید تا مستقیم باز شود.</p></div>';
      return ob_get_clean();
    }

    if (get_post_type($thread_id)!==self::CPT) {
      return '<div id="drj-fe">نخ نامعتبر است.</div>';
    }

    // نخ را خوانده‌شده کن (از شمارنده نوتیفیکیشن کم شود)
    delete_post_meta($thread_id, '_drj_admin_unread');

    $post = get_post($thread_id);
    $comments = get_comments(['post_id'=>$thread_id,'status'=>'approve','orderby'=>'comment_ID','order'=>'ASC','number'=>500]);

    $rest  = esc_url_raw( rest_url(self::NS.'/admin_reply') );
    $nonce = wp_create_nonce('wp_rest');

    ob_start();
    echo '<div id="drj-fe"><h2>پاسخ به «'.esc_html($post->post_title).'»</h2><div class="drj-box">';

    foreach ($comments as $c){
      $who = get_comment_meta($c->comment_ID,'who',true) ?: 'user';
      $cls = ($who==='user') ? 'drj-msg-user' : 'drj-msg-op';
      echo '<div class="'.$cls.'"><strong>'.esc_html($who==='user'?'کاربر':'اپراتور').':</strong> '.
           esc_html($c->comment_content).' <span style="opacity:.6;font-size:11px">('.
           esc_html($c->comment_date).')</span></div>';
    }

    echo '</div>'.
         '<form class="drj-form" id="drj-fe-reply">'.
         '<input type="text" id="drj-fe-reply-text" placeholder="پاسخ خود را بنویسید..." required>'.
         '<button type="submit">ارسال پاسخ</button>'.
         '</form>'.
         '</div>';

    // ارسال پاسخ از فرانت‌اند (REST + nonce)
    echo '<script>(function(){const f=document.getElementById("drj-fe-reply"),i=document.getElementById("drj-fe-reply-text");if(!f)return;f.addEventListener("submit",async function(e){e.preventDefault();const t=(i.value||"").trim();if(!t)return;try{const r=await fetch("'.$rest.'",{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":"'.esc_js($nonce).'"},body:JSON.stringify({thread_id:'.(int)$thread_id.',message:t})});const d=await r.json();if(d&&d.ok){location.reload()}else{alert("ارسال نشد")}}catch(err){console.error(err);alert("خطا در ارتباط")}})})();</script>';

    return ob_get_clean();
  }
}

new Darij_Store_Chat_Frontend();
