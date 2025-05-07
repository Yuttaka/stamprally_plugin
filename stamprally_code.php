<?php
/*
Plugin Name: Stamp Rally
Description: 位置情報を使ってスタンプを獲得＆表示できるシステム。
Version: 1.0
Author: あなたの名前
*/

// === カスタム投稿タイプ「スタンプ地点」登録 ===
add_action('init', function() {
    register_post_type('stamp_location', [
        'labels' => ['name' => 'スタンプ地点', 'singular_name' => 'スタンプ地点'],
        'public' => true,
        'has_archive' => false,
        'menu_position' => 5,
        'menu_icon' => 'dashicons-location-alt',
        'supports' => ['title', 'thumbnail', 'editor'],
        'show_in_rest' => false,
    ]);
});

// === カスタムフィールド（緯度・経度） ===
add_action('add_meta_boxes', function() {
    add_meta_box('stamp_location_coords', '位置情報（緯度・経度）', function($post) {
        $lat = get_post_meta($post->ID, '_stamp_lat', true);
        $lng = get_post_meta($post->ID, '_stamp_lng', true);
        echo '<label>緯度: <input type="text" name="stamp_lat" value="' . esc_attr($lat) . '" size="10" /></label><br>';
        echo '<label>経度: <input type="text" name="stamp_lng" value="' . esc_attr($lng) . '" size="10" /></label>';
    }, 'stamp_location', 'normal', 'high');
});

add_action('save_post', function($post_id) {
    if (isset($_POST['stamp_lat'])) update_post_meta($post_id, '_stamp_lat', sanitize_text_field($_POST['stamp_lat']));
    if (isset($_POST['stamp_lng'])) update_post_meta($post_id, '_stamp_lng', sanitize_text_field($_POST['stamp_lng']));
});

// === スタンプ獲得処理（AJAX） ===
add_action('wp_ajax_award_stamp', function() {
    check_ajax_referer('stamp_nonce', 'nonce');
    $user_id = get_current_user_id();
    $stamp_id = sanitize_text_field($_POST['stamp_id']);
    if ($user_id && $stamp_id) {
        update_user_meta($user_id, 'stamp_' . $stamp_id, 1);
        wp_send_json_success('スタンプ獲得完了！');
    }
    wp_send_json_error('エラーが発生しました');
});

// === スタンプ一覧 + モーダル表示 + CSS/JS（[user_stamps]）===
add_shortcode('user_stamps', function() {
    $user_id = get_current_user_id();
    $args = ['post_type' => 'stamp_location', 'posts_per_page' => -1];
    $stamps = get_posts($args);
    ob_start();
    ?>
    <div class="user-stamps" style="display:flex; flex-wrap:wrap; gap:1em;">
        <?php foreach ($stamps as $stamp):
            $stamp_id = $stamp->post_name;
            $has = get_user_meta($user_id, 'stamp_' . $stamp_id, true);
            $title = esc_html($stamp->post_title);
            $content = apply_filters('the_content', $stamp->post_content);
            $thumbnail = get_the_post_thumbnail_url($stamp->ID, 'medium') ?: 'https://via.placeholder.com/100x100?text=No+Image';
            $img_class = $has ? 'clear' : 'blurred';
        ?>
        <div class="stamp-container">
            <div class="stamp-img-wrapper <?= $img_class ?>" data-title="<?= esc_attr($title) ?>" data-content="<?= esc_attr(wp_strip_all_tags($stamp->post_content)) ?>" data-img="<?= esc_url($thumbnail) ?>">
                <img src="<?= esc_url($thumbnail) ?>" alt="<?= $title ?>" />
                <?php if (!$has): ?><div class="stamp-overlay">？</div><?php endif; ?>
            </div>
            <p class="stamp-title"><?= $title ?></p>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="stamp-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background-color:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center;">
        <div style="background:white; padding:20px; max-width:400px; border-radius:10px; position:relative;">
            <span id="stamp-modal-close" style="position:absolute; top:10px; right:15px; cursor:pointer; font-size:20px;">×</span>
            <img id="stamp-modal-img" src="" alt="" style="width:100%; max-height:200px; object-fit:cover; margin-bottom:1em;" />
            <h3 id="stamp-modal-title"></h3>
            <p id="stamp-modal-content"></p>
        </div>
    </div>

    <style>
    .stamp-img-wrapper { position: relative; width: 100px; height: 100px; overflow: hidden; border-radius: 10px; cursor: pointer; }
    .stamp-img-wrapper img { width: 100%; height: 100%; object-fit: cover; transition: filter 0.3s; }
    .stamp-img-wrapper.blurred img { filter: blur(6px) grayscale(60%) brightness(0.6); }
    .stamp-overlay {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        color: white; font-size: 2em; font-weight: bold;
        background-color: rgba(0, 0, 0, 0.4); pointer-events: none;
    }
    .stamp-title { margin-top: 0.5em; font-size: 0.9em; }
    </style>
    <script>
    document.addEventListener("DOMContentLoaded", function() {
        document.querySelectorAll(".stamp-img-wrapper").forEach(el => {
            el.addEventListener("click", () => {
                document.getElementById("stamp-modal-title").textContent = el.dataset.title;
                document.getElementById("stamp-modal-content").textContent = el.dataset.content;
                document.getElementById("stamp-modal-img").src = el.dataset.img;
                document.getElementById("stamp-modal").style.display = "flex";
            });
        });
        document.getElementById("stamp-modal-close").addEventListener("click", () => {
            document.getElementById("stamp-modal").style.display = "none";
        });
    });
    </script>
    <?php
    return ob_get_clean();
});
// 自動チェックインショートコード（slugから緯度経度を取得）
add_shortcode('stamp_checkin_auto', function($atts) {
    $atts = shortcode_atts(['slug' => ''], $atts);
    $slug = sanitize_title($atts['slug']);
    $post = get_page_by_path($slug, OBJECT, 'stamp_location');
    if (!$post) return 'スタンプ地点が見つかりません';

    $lat = get_post_meta($post->ID, '_stamp_lat', true);
    $lng = get_post_meta($post->ID, '_stamp_lng', true);
    $stamp_id = $post->post_name;
    $nonce = wp_create_nonce('stamp_nonce');

    ob_start();
    ?>
    <div id="checkin-box" data-lat="<?= esc_attr($lat) ?>" data-lng="<?= esc_attr($lng) ?>" data-stamp="<?= esc_attr($stamp_id) ?>" data-nonce="<?= $nonce ?>">
        <p id="checkin-status">現在地を確認中...</p>
        <button id="checkin-button" style="display:none;">スタンプを獲得！</button>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function () {
        const box = document.getElementById("checkin-box");
        const targetLat = parseFloat(box.dataset.lat);
        const targetLng = parseFloat(box.dataset.lng);
        const stampId = box.dataset.stamp;
        const nonce = box.dataset.nonce;
        const status = document.getElementById("checkin-status");
        const button = document.getElementById("checkin-button");

        function distance(lat1, lon1, lat2, lon2) {
            const R = 6371; // km
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) ** 2 + Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) * Math.sin(dLon / 2) ** 2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
        }

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(pos => {
                const dist = distance(pos.coords.latitude, pos.coords.longitude, targetLat, targetLng);
                if (dist <= 1.0) {
                    status.textContent = `スタンプ地点に到達しました！（距離：${dist.toFixed(2)}km）`;
                    button.style.display = 'inline-block';
                } else {
                    status.textContent = `現在地は ${dist.toFixed(2)}km 離れています（1km以内でスタンプを獲得可能）。`;
                }
            }, () => {
                status.textContent = "位置情報の取得に失敗しました。";
            });
        } else {
            status.textContent = "位置情報を取得できる環境ではありません。";
        }

        button.addEventListener("click", () => {
            fetch("<?= admin_url('admin-ajax.php') ?>", {
                method: "POST",
                headers: { "Content-Type": "application/x-www-form-urlencoded" },
                body: `action=award_stamp&stamp_id=${encodeURIComponent(stampId)}&nonce=${encodeURIComponent(nonce)}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert("✅ スタンプを獲得しました！");
                    location.reload(); // 更新して一覧に反映
                } else {
                    alert("❌ スタンプ獲得に失敗しました");
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
});
// === カスタムタクソノミー「コース分類」の追加 ===
add_action('init', function() {
    register_taxonomy('stamp_course', 'stamp_location', [
        'label' => 'コース',
        'labels' => [
            'name'              => 'コース分類',
            'singular_name'     => 'コース',
            'search_items'      => 'コースを検索',
            'all_items'         => 'すべてのコース',
            'edit_item'         => 'コースを編集',
            'update_item'       => 'コースを更新',
            'add_new_item'      => '新しいコースを追加',
            'new_item_name'     => '新しいコース名',
            'menu_name'         => 'コース',
        ],
        'hierarchical' => false, // タグのような形式（複数選択可）
        'show_ui' => true,
        'show_admin_column' => true,
        'rewrite' => ['slug' => 'course'],
        'show_in_rest' => true // ブロックエディタ用
    ]);
});
