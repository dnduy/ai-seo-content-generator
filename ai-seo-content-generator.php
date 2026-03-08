<?php
/*
Plugin Name: AI SEO Content Generator
Description: A WordPress plugin to generate SEO-optimized content using Google Gemini or DeepSeek API in WordPress 6.8 Gutenberg editor, with SEO guidance, caching, and content history.
Version: 3.2
Author: Your Name
License: GPL2
Text Domain: ai-seo-content-generator
Domain Path: /languages
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('AISEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('AISEO_PLUGIN_URL', plugin_dir_url(__FILE__));

// Get encryption key safely — always returns a proper 32-byte (256-bit) key
function aiseo_get_encryption_key() {
    $raw = function_exists('wp_salt') ? wp_salt('aiseo_encryption') : ('aiseo_fallback_key_' . ABSPATH);
    return hash('sha256', $raw, true); // Binary 32-byte key for AES-256
}

// Encryption/Decryption functions for API keys
function aiseo_encrypt_api_key($key) {
    if (empty($key)) {
        return '';
    }
    
    if (!function_exists('openssl_encrypt')) {
        // Fallback: just base64 encode if OpenSSL not available
        return base64_encode($key);
    }
    
    $iv = openssl_random_pseudo_bytes(16);
    $encryption_key = aiseo_get_encryption_key();
    $encrypted = openssl_encrypt($key, 'AES-256-CBC', $encryption_key, 0, $iv);
    return base64_encode($iv . $encrypted);
}

function aiseo_decrypt_api_key($encrypted_key) {
    if (empty($encrypted_key)) {
        return '';
    }
    
    if (!function_exists('openssl_decrypt')) {
        // Fallback: just base64 decode if OpenSSL not available
        return base64_decode($encrypted_key);
    }
    
    $data = base64_decode($encrypted_key);
    if (strlen($data) < 16) {
        // Invalid data, might be old unencrypted key
        return $encrypted_key;
    }
    
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    $encryption_key = aiseo_get_encryption_key();
    return openssl_decrypt($encrypted, 'AES-256-CBC', $encryption_key, 0, $iv);
}

function aiseo_get_api_key($option_name) {
    $encrypted_key = get_option($option_name);
    return aiseo_decrypt_api_key($encrypted_key);
}

function aiseo_save_api_key($option_name, $key) {
    $encrypted_key = aiseo_encrypt_api_key($key);
    update_option($option_name, $encrypted_key);
}

// Caching functions
function aiseo_get_cache_key($prompt, $keywords, $length, $tone, $language, $api) {
    $cache_data = $prompt . $keywords . $length . $tone . $language . $api;
    return 'aiseo_content_' . md5($cache_data);
}

function aiseo_get_cached_content($cache_key) {
    $cached = get_transient($cache_key);
    if ($cached !== false) {
        error_log('AISEO: Using cached content for key: ' . $cache_key);
        return $cached;
    }
    return false;
}

function aiseo_cache_content($cache_key, $content) {
    // Cache for 1 hour
    set_transient($cache_key, $content, HOUR_IN_SECONDS);
    error_log('AISEO: Cached content for key: ' . $cache_key);
}

function aiseo_clear_cache() {
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_aiseo_content_%'");
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_aiseo_content_%'");
}

// Include API handler
if (file_exists(AISEO_PLUGIN_DIR . 'includes/api-handler.php')) {
    require_once AISEO_PLUGIN_DIR . 'includes/api-handler.php';
} else {
    error_log('AISEO: api-handler.php not found');
}

// Include database functions
if (file_exists(AISEO_PLUGIN_DIR . 'includes/database.php')) {
    require_once AISEO_PLUGIN_DIR . 'includes/database.php';
} else {
    error_log('AISEO: database.php not found');
}

// Load text domain for translations
function aiseo_load_textdomain() {
    load_plugin_textdomain('ai-seo-content-generator', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
add_action('plugins_loaded', 'aiseo_load_textdomain');

// Initialize plugin after WordPress is loaded
function aiseo_init() {
    // Ensure database table exists
    if (function_exists('aiseo_create_history_table')) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'aiseo_history';
        $sql = $wpdb->prepare("SHOW TABLES LIKE %s", $table_name);
        if ($wpdb->get_var($sql) != $table_name) {
            aiseo_create_history_table();
        }
    }
}
add_action('init', 'aiseo_init');

// Enqueue scripts and styles for Gutenberg
function aiseo_enqueue_block_editor_assets() {
    if (!function_exists('get_current_screen') || !get_current_screen()->is_block_editor()) {
        return;
    }

    $block_editor_file = AISEO_PLUGIN_DIR . 'assets/js/block-editor.js';
    $style_file = AISEO_PLUGIN_DIR . 'assets/css/style.css';
    
    wp_enqueue_script(
        'aiseo-block-editor',
        AISEO_PLUGIN_URL . 'assets/js/block-editor.js',
        array('wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-api-fetch', 'wp-data'),
        file_exists($block_editor_file) ? filemtime($block_editor_file) : '1.0',
        true
    );

    wp_enqueue_style(
        'aiseo-style',
        AISEO_PLUGIN_URL . 'assets/css/style.css',
        array(),
        file_exists($style_file) ? filemtime($style_file) : '1.0'
    );

    wp_localize_script('aiseo-block-editor', 'aiseoSettings', array(
        'rest_url' => rest_url('aiseo/v1/generate-content'),
        'nonce' => wp_create_nonce('wp_rest')
    ));

    error_log('AISEO: Enqueuing block-editor.js at ' . AISEO_PLUGIN_URL . 'assets/js/block-editor.js');
}
add_action('enqueue_block_editor_assets', 'aiseo_enqueue_block_editor_assets');

// Register REST API endpoint
function aiseo_register_rest_route() {
    // Test endpoint to verify authentication setup
    register_rest_route('aiseo/v1', '/test-auth', array(
        'methods' => 'GET',
        'callback' => 'aiseo_test_auth_endpoint',
        'permission_callback' => '__return_true'
    ));

    // Main content generation endpoint
    register_rest_route('aiseo/v1', '/generate-content', array(
        'methods' => 'POST',
        'callback' => 'aiseo_handle_ai_request',
        'permission_callback' => '__return_true'  // Allow request to proceed; validation done in callback
    ));
}

// Test authentication endpoint for debugging
function aiseo_test_auth_endpoint(WP_REST_Request $request) {
    $response = array(
        'user_logged_in' => is_user_logged_in(),
        'current_user' => get_current_user_id(),
        'nonce_header' => $request->get_header('X-WP-Nonce'),
        'user_capabilities' => array(
            'edit_posts' => current_user_can('edit_posts'),
            'manage_options' => current_user_can('manage_options')
        )
    );
    
    return new WP_REST_Response($response, 200);
}
add_action('rest_api_init', 'aiseo_register_rest_route');

// Handle AI request
function aiseo_handle_ai_request(WP_REST_Request $request) {
    error_log('AISEO: Handling REST request for /aiseo/v1/generate-content');

    // First, verify user is logged in
    if (!is_user_logged_in()) {
        error_log('AISEO: User not logged in');
        return new WP_REST_Response(
            array(
                'success' => false,
                'code' => 'not_authenticated',
                'message' => __('You must be logged in to use this feature', 'ai-seo-content-generator')
            ),
            401
        );
    }

    // Check user capability
    if (!current_user_can('edit_posts')) {
        error_log('AISEO: User does not have edit_posts capability');
        return new WP_REST_Response(
            array(
                'success' => false,
                'code' => 'forbidden',
                'message' => __('You do not have permission to generate content', 'ai-seo-content-generator')
            ),
            403
        );
    }

    // Verify nonce from X-WP-Nonce header
    $nonce = $request->get_header('X-WP-Nonce');
    
    if (empty($nonce)) {
        error_log('AISEO: No nonce provided in request headers');
        return new WP_REST_Response(
            array(
                'success' => false,
                'code' => 'invalid_nonce',
                'message' => __('Missing nonce for security verification', 'ai-seo-content-generator')
            ),
            403
        );
    }

    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        error_log('AISEO: Nonce verification failed. Nonce: ' . substr($nonce, 0, 10) . '...');
        return new WP_REST_Response(
            array(
                'success' => false,
                'code' => 'invalid_nonce',
                'message' => __('Security verification failed. Please refresh the page and try again.', 'ai-seo-content-generator')
            ),
            403
        );
    }

    $params = $request->get_params();

    $prompt = isset($params['prompt']) ? sanitize_textarea_field($params['prompt']) : '';
    $keywords = isset($params['keywords']) ? sanitize_text_field($params['keywords']) : '';

    // Cap length: 100–5000 words to prevent excessive API costs
    $length = isset($params['length']) ? absint($params['length']) : 500;
    $length = max(100, min(5000, $length));

    // Whitelist tone to prevent prompt injection via this field
    $allowed_tones = array('neutral', 'informative', 'storytelling', 'professional', 'friendly', 'humorous');
    $tone = isset($params['tone']) && in_array($params['tone'], $allowed_tones, true)
        ? $params['tone'] : 'neutral';

    // Whitelist language
    $allowed_languages = array('vi', 'en', 'ko');
    $language = isset($params['language']) && in_array($params['language'], $allowed_languages, true)
        ? $params['language'] : 'vi';

    // Whitelist API model to prevent unexpected fallback paths
    $allowed_apis = array(
        'claude-opus', 'claude-sonnet', 'claude-haiku',
        'gemini-3.1-pro', 'gemini-3-flash', 'gemini-3.1-flash-lite', 'gemini-2.0', 'gemini-studio',
        'deepseek'
    );
    $api = isset($params['api']) && in_array($params['api'], $allowed_apis, true)
        ? $params['api'] : 'claude-opus';

    if (empty($prompt) || empty($keywords)) {
        error_log('AISEO: Missing prompt or keywords');
        return new WP_REST_Response(
            array('code' => 'missing_params', 'message' => __('Please provide a prompt and keywords.', 'ai-seo-content-generator')),
            400
        );
    }

    // Server-side rate limiting: 1 request per 15 seconds per user
    $rate_limit_key = 'aiseo_rate_limit_' . get_current_user_id();
    if (get_transient($rate_limit_key)) {
        return new WP_REST_Response(
            array(
                'success' => false,
                'code' => 'rate_limited',
                'message' => __('Please wait before sending another request.', 'ai-seo-content-generator')
            ),
            429
        );
    }
    set_transient($rate_limit_key, 1, 15); // 15-second cooldown

    // Check cache first
    $cache_key = aiseo_get_cache_key($prompt, $keywords, $length, $tone, $language, $api);
    $cached_content = aiseo_get_cached_content($cache_key);
    
    if ($cached_content !== false) {
        return new WP_REST_Response($cached_content, 200);
    }

    // Map language codes to full names
    $language_map = [
        'vi' => 'Vietnamese',
        'en' => 'English',
        'ko' => 'Korean'
    ];
    $language_full = isset($language_map[$language]) ? $language_map[$language] : 'Vietnamese';

    // Construct advanced SEO-optimized prompt following Google E-E-A-T guidelines
    $full_prompt  = "You are an expert SEO content writer. Generate a high-quality, SEO-optimized article in {$language_full} with the following requirements:\n\n";

    $full_prompt .= "=== CONTENT REQUIREMENTS ===\n";
    $full_prompt .= "- Main keyword: \"{$keywords}\"\n";
    $full_prompt .= "- Target word count: approximately {$length} words\n";
    $full_prompt .= "- Writing tone: {$tone}\n";
    $full_prompt .= "- User brief: {$prompt}\n\n";

    $full_prompt .= "=== SEO WRITING RULES ===\n";
    $full_prompt .= "- Follow Google E-E-A-T (Experience, Expertise, Authoritativeness, Trustworthiness).\n";
    $full_prompt .= "- Place the main keyword naturally in the first 100 words.\n";
    $full_prompt .= "- Maintain keyword density of 1–2% (do NOT over-stuff).\n";
    $full_prompt .= "- Use Latent Semantic Indexing (LSI) keywords and synonyms throughout.\n";
    $full_prompt .= "- Write the opening paragraph as a concise, direct answer (featured snippet optimisation).\n";
    $full_prompt .= "- Use transition words to improve readability and Flesch reading ease.\n";
    $full_prompt .= "- Include at least one clear call-to-action (CTA) near the end.\n\n";

    $full_prompt .= "=== HTML FORMAT ===\n";
    $full_prompt .= "- Output the article body strictly in HTML: <h2>–<h6>, <p>, <ul>, <ol>, <li>, <strong>, <em>.\n";
    $full_prompt .= "- Do NOT use <h1>. Do NOT use Markdown. Do NOT wrap in code blocks like ```html```.\n";
    $full_prompt .= "- Use <strong> for the first occurrence of the main keyword in each section.\n";
    $full_prompt .= "- Structure: intro paragraph → multiple H2 sections with H3 sub-sections → conclusion with CTA.\n\n";

    $full_prompt .= "=== METADATA (output BEFORE the HTML content, one field per line) ===\n";
    $full_prompt .= "Output exactly these lines first, then the HTML article body:\n";
    $full_prompt .= "SEO Title: [60–70 characters, include main keyword near the start]\n";
    $full_prompt .= "Meta Description: [150–160 characters, include main keyword and a compelling CTA]\n";
    $full_prompt .= "OG Title: [50–60 characters, engaging social sharing title]\n";
    $full_prompt .= "Synonym Keyword: [one LSI/semantic variant of the main keyword]\n";
    $full_prompt .= "Secondary Keyword: [one related long-tail keyword]\n";
    $full_prompt .= "Schema Type: [one of: Article, BlogPosting, HowTo, FAQPage, Product, NewsArticle]\n";
    $full_prompt .= "Reading Time: [estimated reading time, e.g. 5 minutes]\n";
    $full_prompt .= "FAQ 1 Q: [frequently asked question 1 about the topic]\n";
    $full_prompt .= "FAQ 1 A: [concise answer, 1–2 sentences]\n";
    $full_prompt .= "FAQ 2 Q: [frequently asked question 2]\n";
    $full_prompt .= "FAQ 2 A: [concise answer]\n";
    $full_prompt .= "FAQ 3 Q: [frequently asked question 3]\n";
    $full_prompt .= "FAQ 3 A: [concise answer]\n";
    $full_prompt .= "Internal Link 1: [suggested anchor text for an internal link]\n";
    $full_prompt .= "Internal Link 2: [suggested anchor text for an internal link]\n";
    $full_prompt .= "Internal Link 3: [suggested anchor text for an internal link]\n";

    // Call selected API with fallback
    $response = aiseo_generate_content_with_fallback($full_prompt, $api);

    if (is_wp_error($response)) {
        $error_code = $response->get_error_code();
        $error_message = $response->get_error_message();
        error_log('AISEO: API error: ' . $error_message);

        return new WP_REST_Response(
            array(
                'success' => false,
                'code' => $error_code,
                'message' => $error_message
            ),
            in_array($error_code, array('quota_exceeded', 'all_quota_exceeded'), true) ? 429 : 500
        );
    }

    // ── Parse all metadata fields from the AI response ──────────────────────
    $meta_title       = '';
    $meta_description = '';
    $og_title         = '';
    $synonym_keyword  = '';
    $secondary_keyword = '';
    $schema_type      = '';
    $reading_time     = '';
    $faq_1_q = ''; $faq_1_a = '';
    $faq_2_q = ''; $faq_2_a = '';
    $faq_3_q = ''; $faq_3_a = '';
    $internal_link_1  = '';
    $internal_link_2  = '';
    $internal_link_3  = '';

    $meta_prefixes = array(
        'SEO Title:'       => &$meta_title,
        'Meta Description:'=> &$meta_description,
        'OG Title:'        => &$og_title,
        'Synonym Keyword:' => &$synonym_keyword,
        'Secondary Keyword:'=> &$secondary_keyword,
        'Schema Type:'     => &$schema_type,
        'Reading Time:'    => &$reading_time,
        'FAQ 1 Q:'         => &$faq_1_q,
        'FAQ 1 A:'         => &$faq_1_a,
        'FAQ 2 Q:'         => &$faq_2_q,
        'FAQ 2 A:'         => &$faq_2_a,
        'FAQ 3 Q:'         => &$faq_3_q,
        'FAQ 3 A:'         => &$faq_3_a,
        'Internal Link 1:' => &$internal_link_1,
        'Internal Link 2:' => &$internal_link_2,
        'Internal Link 3:' => &$internal_link_3,
    );

    $content_lines = array();
    foreach (explode("\n", $response) as $line) {
        $matched = false;
        foreach ($meta_prefixes as $prefix => &$target) {
            if (strncmp(trim($line), $prefix, strlen($prefix)) === 0) {
                $target   = trim(substr(trim($line), strlen($prefix)));
                $matched  = true;
                break;
            }
        }
        unset($target);
        if (!$matched) {
            $content_lines[] = $line;
        }
    }
    $content = implode("\n", $content_lines);

    // ── Keyword density (server-side) ─────────────────────────────────────
    $plain_content    = strtolower(strip_tags($content));
    $kw_lower         = strtolower(trim($keywords));
    $word_count_body  = str_word_count($plain_content);
    $kw_occurrences   = $kw_lower !== '' ? substr_count($plain_content, $kw_lower) : 0;
    $keyword_density  = ($word_count_body > 0 && $kw_occurrences > 0)
        ? round(($kw_occurrences / $word_count_body) * 100, 2) : 0;

    // Density status label
    if ($keyword_density === 0.0) {
        $density_status = 'Chưa có từ khóa';
    } elseif ($keyword_density < 0.5) {
        $density_status = 'Quá thấp (&lt; 0.5%)';
    } elseif ($keyword_density <= 2.0) {
        $density_status = 'Tốt (0.5 – 2%)';
    } else {
        $density_status = 'Quá cao (&gt; 2%) – có thể bị phạt';
    }

    // ── Build comprehensive SEO guidance block ────────────────────────────
    $kw_esc   = esc_html($keywords);
    $syn_esc  = esc_html($synonym_keyword);
    $sec_esc  = esc_html($secondary_keyword);
    $desc_esc = esc_html($meta_description);
    $title_esc = esc_html($meta_title);
    $og_esc   = esc_html($og_title);
    $schema_esc = esc_html($schema_type ?: 'Article');
    $rt_esc   = esc_html($reading_time ?: 'N/A');

    $seo_guidance  = '<h2>📊 Hướng dẫn SEO đầy đủ</h2>';

    // Section 1: Basic SEO fields
    $seo_guidance .= '<h3>1. Thông tin SEO cơ bản (nhập vào Yoast SEO / Rank Math)</h3>';
    $seo_guidance .= '<ul>';
    $seo_guidance .= "<li><strong>SEO Title:</strong> {$title_esc} <em>(" . strlen($meta_title) . " ký tự — tối ưu 60-70)</em></li>";
    $seo_guidance .= "<li><strong>Meta Description:</strong> {$desc_esc} <em>(" . strlen($meta_description) . " ký tự — tối ưu 150-160)</em></li>";
    $seo_guidance .= "<li><strong>Open Graph Title (MXH):</strong> {$og_esc}</li>";
    $seo_guidance .= "<li><strong>Từ khóa chính (Focus Keyword):</strong> {$kw_esc}</li>";
    $seo_guidance .= "<li><strong>Từ khóa đồng nghĩa (Synonym):</strong> {$syn_esc}</li>";
    $seo_guidance .= "<li><strong>Từ khóa phụ (Secondary):</strong> {$sec_esc}</li>";
    $seo_guidance .= "<li><strong>Mật độ từ khóa:</strong> {$keyword_density}% ({$kw_occurrences} lần / {$word_count_body} từ) — {$density_status}</li>";
    $seo_guidance .= "<li><strong>Thời gian đọc ước tính:</strong> {$rt_esc}</li>";
    $seo_guidance .= '</ul>';

    // Section 2: Schema.org
    $seo_guidance .= '<h3>2. Schema.org Structured Data</h3>';
    $seo_guidance .= '<ul>';
    $seo_guidance .= "<li><strong>Loại Schema gợi ý:</strong> {$schema_esc}</li>";
    $seo_guidance .= '<li>Triển khai JSON-LD trong <code>&lt;head&gt;</code> của trang để tăng khả năng hiển thị Rich Result trên Google.</li>';

    // FAQ schema block
    if ($faq_1_q) {
        $seo_guidance .= '<li><strong>FAQ Schema (3 câu hỏi):</strong><ul>';
        if ($faq_1_q) $seo_guidance .= '<li><strong>Q:</strong> ' . esc_html($faq_1_q) . ' <strong>A:</strong> ' . esc_html($faq_1_a) . '</li>';
        if ($faq_2_q) $seo_guidance .= '<li><strong>Q:</strong> ' . esc_html($faq_2_q) . ' <strong>A:</strong> ' . esc_html($faq_2_a) . '</li>';
        if ($faq_3_q) $seo_guidance .= '<li><strong>Q:</strong> ' . esc_html($faq_3_q) . ' <strong>A:</strong> ' . esc_html($faq_3_a) . '</li>';
        $seo_guidance .= '</ul></li>';
    }
    $seo_guidance .= '</ul>';

    // Section 3: Internal Links
    if ($internal_link_1 || $internal_link_2 || $internal_link_3) {
        $seo_guidance .= '<h3>3. Gợi ý Internal Links (anchor text)</h3>';
        $seo_guidance .= '<ul>';
        if ($internal_link_1) $seo_guidance .= '<li>' . esc_html($internal_link_1) . '</li>';
        if ($internal_link_2) $seo_guidance .= '<li>' . esc_html($internal_link_2) . '</li>';
        if ($internal_link_3) $seo_guidance .= '<li>' . esc_html($internal_link_3) . '</li>';
        $seo_guidance .= '</ul>';
    }

    // Section 4: On-page SEO checklist
    $seo_guidance .= '<h3>4. On-page SEO Checklist</h3>';
    $seo_guidance .= '<ul>';
    $seo_guidance .= '<li>☐ Từ khóa chính xuất hiện trong 100 từ đầu tiên</li>';
    $seo_guidance .= '<li>☐ Từ khóa trong ít nhất một thẻ H2</li>';
    $seo_guidance .= '<li>☐ URL slug ngắn, chứa từ khóa chính (không dấu)</li>';
    $seo_guidance .= '<li>☐ Hình ảnh có alt text chứa từ khóa</li>';
    $seo_guidance .= '<li>☐ Thêm 3–5 internal links tới bài liên quan</li>';
    $seo_guidance .= '<li>☐ Thêm 1–2 external links tới nguồn uy tín (Wikipedia, Gov, Edu)</li>';
    $seo_guidance .= '<li>☐ Nhập SEO Title và Meta Description vào Yoast/Rank Math</li>';
    $seo_guidance .= '<li>☐ Nhập Open Graph Title cho mạng xã hội</li>';
    $seo_guidance .= '<li>☐ Cài đặt Schema JSON-LD theo loại: ' . $schema_esc . '</li>';
    $seo_guidance .= '<li>☐ Kiểm tra mật độ từ khóa (mục tiêu 1–2%)</li>';
    $seo_guidance .= '<li>☐ Đảm bảo bài có tốc độ tải tốt (Core Web Vitals)</li>';
    $seo_guidance .= '</ul>';

    $content .= $seo_guidance;

    // Collect FAQ data for response
    $faq_items = array();
    if ($faq_1_q) $faq_items[] = array('q' => $faq_1_q, 'a' => $faq_1_a);
    if ($faq_2_q) $faq_items[] = array('q' => $faq_2_q, 'a' => $faq_2_a);
    if ($faq_3_q) $faq_items[] = array('q' => $faq_3_q, 'a' => $faq_3_a);

    $response_data = array(
        'success'          => true,
        'data'             => $content,
        'meta_title'       => $meta_title,
        'meta_description' => $meta_description,
        'og_title'         => $og_title,
        'synonym_keyword'  => $synonym_keyword,
        'secondary_keyword'=> $secondary_keyword,
        'schema_type'      => $schema_type,
        'reading_time'     => $reading_time,
        'keyword_density'  => $keyword_density,
        'keyword_count'    => $kw_occurrences,
        'word_count'       => $word_count_body,
        'faq_items'        => $faq_items,
        'internal_links'   => array_filter(array($internal_link_1, $internal_link_2, $internal_link_3)),
    );

    // Cache the response
    aiseo_cache_content($cache_key, $response_data);
    
    // Save to history
    $user_id = get_current_user_id();
    if ($user_id) {
        aiseo_save_to_history($user_id, $prompt, $keywords, $api, $content, array(
            'meta_title'        => $meta_title,
            'meta_description'  => $meta_description,
            'synonym_keyword'   => $synonym_keyword,
            'secondary_keyword' => $secondary_keyword,
            'og_title'          => $og_title,
            'schema_type'       => $schema_type,
            'reading_time'      => $reading_time,
            'keyword_density'   => $keyword_density,
        ));
    }

    return new WP_REST_Response($response_data, 200);
}

// Add settings page for API keys
function aiseo_register_settings() {
    add_options_page(
        'AI SEO Content Generator Settings',
        'AI SEO Content',
        'manage_options',
        'aiseo-settings',
        'aiseo_settings_page'
    );
    
    add_submenu_page(
        'edit.php',
        'AI SEO Content History',
        'AI Content History',
        'edit_posts',
        'aiseo-history',
        'aiseo_history_page'
    );
}
add_action('admin_menu', 'aiseo_register_settings');

function aiseo_settings_page() {
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    // Handle form submission
    if (isset($_POST['submit'])) {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aiseo_settings_nonce')) {
            wp_die('Nonce verification failed');
        }
        
        $gemini_key = isset($_POST['aiseo_gemini_api_key']) ? sanitize_text_field($_POST['aiseo_gemini_api_key']) : '';
        $deepseek_key = isset($_POST['aiseo_deepseek_api_key']) ? sanitize_text_field($_POST['aiseo_deepseek_api_key']) : '';
        $claude_key = isset($_POST['aiseo_claude_api_key']) ? sanitize_text_field($_POST['aiseo_claude_api_key']) : '';

        aiseo_save_api_key('aiseo_gemini_api_key', $gemini_key);
        aiseo_save_api_key('aiseo_deepseek_api_key', $deepseek_key);
        aiseo_save_api_key('aiseo_claude_api_key', $claude_key);
        
        echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
    }
    
    // Handle clear cache
    if (isset($_POST['clear_cache'])) {
        // Verify nonce
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'aiseo_settings_nonce')) {
            wp_die('Nonce verification failed');
        }
        
        aiseo_clear_cache();
        echo '<div class="notice notice-success"><p>Cache cleared successfully!</p></div>';
    }
    
    ?>
    <div class="wrap">
        <h1>AI SEO Content Generator Settings</h1>
        <form method="post" action="">
            <?php wp_nonce_field('aiseo_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="aiseo_gemini_api_key">Gemini API Key</label></th>
                    <td>
                        <input type="password" name="aiseo_gemini_api_key" id="aiseo_gemini_api_key" value="<?php echo esc_attr(aiseo_get_api_key('aiseo_gemini_api_key')); ?>" class="regular-text" />
                        <p class="description">Get your API key from <a href="https://ai.google.dev/" target="_blank">Google AI Studio</a>. <em>(Encrypted in database)</em></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="aiseo_deepseek_api_key">DeepSeek API Key</label></th>
                    <td>
                        <input type="password" name="aiseo_deepseek_api_key" id="aiseo_deepseek_api_key" value="<?php echo esc_attr(aiseo_get_api_key('aiseo_deepseek_api_key')); ?>" class="regular-text" />
                        <p class="description">Get your API key from <a href="https://openrouter.ai/" target="_blank">OpenRouter</a>. <em>(Encrypted in database)</em></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="aiseo_claude_api_key">Claude (Anthropic) API Key</label></th>
                    <td>
                        <input type="password" name="aiseo_claude_api_key" id="aiseo_claude_api_key" value="<?php echo esc_attr(aiseo_get_api_key('aiseo_claude_api_key')); ?>" class="regular-text" />
                        <p class="description">Get your API key from <a href="https://console.anthropic.com/" target="_blank">Anthropic Console</a>. Supports Claude Opus 4.6, Sonnet 4.6, and Haiku 4.5. <em>(Encrypted in database)</em></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <hr>
        <h2>Cache Management</h2>
        <p>Cached content is stored for 1 hour to improve performance and reduce API calls.</p>
        <form method="post" action="">
            <?php wp_nonce_field('aiseo_settings_nonce'); ?>
            <input type="submit" name="clear_cache" class="button" value="Clear Cache" onclick="return confirm('Are you sure you want to clear all cached content?');" />
        </form>
    </div>
    <?php
}

function aiseo_history_page() {
    // Check user permissions
    if (!current_user_can('edit_posts')) {
        wp_die('Unauthorized');
    }
    
    $current_user_id = get_current_user_id();
    $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    
    $history = aiseo_get_user_history($current_user_id, $per_page, $offset);
    $total = aiseo_get_user_history_count($current_user_id);
    $total_pages = ceil($total / $per_page);
    
    ?>
    <div class="wrap">
        <h1>AI SEO Content History</h1>
        <p>Your generated content history. Total: <?php echo esc_html( (int) $total ); ?> items</p>
        
        <?php if (empty($history)): ?>
            <p>No content history found. Start generating content to see your history here!</p>
        <?php else: ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Keywords</th>
                        <th>API Used</th>
                        <th>Word Count</th>
                        <th>SEO Title</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($history as $item): ?>
                        <tr>
                            <td><?php echo esc_html( date('M j, Y H:i', strtotime($item->created_at)) ); ?></td>
                            <td><strong><?php echo esc_html($item->keywords); ?></strong></td>
                            <td><span class="badge"><?php echo esc_html($item->api_used); ?></span></td>
                            <td><?php echo esc_html( number_format( (int) $item->word_count ) ); ?> words</td>
                            <td><?php echo esc_html($item->meta_title); ?></td>
                            <td>
                                <button type="button" class="button button-small" onclick="toggleContent(<?php echo intval($item->id); ?>)">View Content</button>
                            </td>
                        </tr>
                        <tr id="content-<?php echo intval($item->id); ?>" style="display: none;">
                            <td colspan="6">
                                <div style="background: #f9f9f9; padding: 15px; margin: 10px 0;">
                                    <h4>Prompt:</h4>
                                    <p><?php echo esc_html($item->prompt); ?></p>
                                    
                                    <h4>SEO Data:</h4>
                                    <ul>
                                        <li><strong>Meta Description:</strong> <?php echo esc_html($item->meta_description); ?></li>
                                        <li><strong>Synonym Keyword:</strong> <?php echo esc_html($item->synonym_keyword); ?></li>
                                        <li><strong>Secondary Keyword:</strong> <?php echo esc_html($item->secondary_keyword); ?></li>
                                    </ul>
                                    
                                    <h4>Generated Content:</h4>
                                    <div style="max-height: 300px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
                                        <?php echo wp_kses_post($item->content); ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if ($total_pages > 1): ?>
                <div class="tablenav">
                    <div class="tablenav-pages">
                        <?php
                        echo paginate_links(array(
                            'base' => add_query_arg('paged', '%#%'),
                            'format' => '',
                            'prev_text' => '&laquo;',
                            'next_text' => '&raquo;',
                            'total' => $total_pages,
                            'current' => $page
                        ));
                        ?>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <script>
    function toggleContent(id) {
        var row = document.getElementById('content-' + id);
        if (row.style.display === 'none') {
            row.style.display = 'table-row';
        } else {
            row.style.display = 'none';
        }
    }
    </script>
    
    <style>
    .badge {
        background: #0073aa;
        color: white;
        padding: 2px 8px;
        border-radius: 3px;
        font-size: 11px;
        text-transform: uppercase;
    }
    </style>
    <?php
}

function aiseo_register_options() {
    register_setting('aiseo_settings_group', 'aiseo_gemini_api_key', 'sanitize_text_field');
    register_setting('aiseo_settings_group', 'aiseo_deepseek_api_key', 'sanitize_text_field');
    register_setting('aiseo_settings_group', 'aiseo_claude_api_key', 'sanitize_text_field');
}
add_action('admin_init', 'aiseo_register_options');

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'aiseo_activate_plugin');
register_deactivation_hook(__FILE__, 'aiseo_deactivate_plugin');
?>