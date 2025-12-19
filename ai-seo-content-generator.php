<?php
/*
Plugin Name: AI SEO Content Generator
Description: A WordPress plugin to generate SEO-optimized content using Google Gemini or DeepSeek API in WordPress 6.8 Gutenberg editor, with SEO guidance, caching, and content history.
Version: 3.0
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

// Get encryption key safely
function aiseo_get_encryption_key() {
    if (function_exists('wp_salt')) {
        return wp_salt('aiseo_encryption');
    }
    // Fallback key if wp_salt is not available yet
    return 'aiseo_fallback_key_' . ABSPATH;
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
    register_rest_route('aiseo/v1', '/generate-content', array(
        'methods' => 'POST',
        'callback' => 'aiseo_handle_ai_request',
        'permission_callback' => function() {
            return current_user_can('edit_posts');
        }
    ));
}
add_action('rest_api_init', 'aiseo_register_rest_route');

// Handle AI request
function aiseo_handle_ai_request(WP_REST_Request $request) {
    error_log('AISEO: Handling REST request for /aiseo/v1/generate-content');

    $params = $request->get_params();
    $nonce = $request->get_header('X-WP-Nonce');

    if (!wp_verify_nonce($nonce, 'wp_rest')) {
        error_log('AISEO: Nonce verification failed');
        return new WP_REST_Response(
            array('code' => 'invalid_nonce', 'message' => __('Nonce verification failed', 'ai-seo-content-generator')),
            403
        );
    }

    $prompt = isset($params['prompt']) ? sanitize_text_field($params['prompt']) : '';
    $keywords = isset($params['keywords']) ? sanitize_text_field($params['keywords']) : '';
    $length = isset($params['length']) ? absint($params['length']) : 500;
    $tone = isset($params['tone']) ? sanitize_text_field($params['tone']) : 'neutral';
    $language = isset($params['language']) ? sanitize_text_field($params['language']) : 'vi';
    $api = isset($params['api']) ? sanitize_text_field($params['api']) : 'gemini-1.5';

    if (empty($prompt) || empty($keywords)) {
        error_log('AISEO: Missing prompt or keywords');
        return new WP_REST_Response(
            array('code' => 'missing_params', 'message' => __('Please provide a prompt and keywords.', 'ai-seo-content-generator')),
            400
        );
    }

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

    // Construct SEO-optimized prompt
    $full_prompt = "Generate an SEO-optimized article in {$language_full} language with the following details:\n";
    $full_prompt .= "- Main keyword: {$keywords}\n";
    $full_prompt .= "- Word count: approximately {$length} words\n";
    $full_prompt .= "- Tone: {$tone}\n";
    $full_prompt .= "- Format the content strictly in HTML using tags <h2> to <h6>, <p>, <ul>, <li>. Do NOT use <h1>, Markdown, or wrap in code blocks like ```html...```.\n";
    $full_prompt .= "- Ensure the content is unique, original, and free from plagiarism.\n";
    $full_prompt .= "- Include proper heading structure, bullet points, and natural keyword integration.\n";
    $full_prompt .= "- User request: {$prompt}\n";
    $full_prompt .= "- Provide an SEO title (60-70 characters), meta description (150-160 characters), synonym keyword, and secondary keyword at the beginning in plain text, formatted as:\n";
    $full_prompt .= "  SEO Title: [Your title here]\n";
    $full_prompt .= "  Meta Description: [Your description here]\n";
    $full_prompt .= "  Synonym Keyword: [Your synonym here]\n";
    $full_prompt .= "  Secondary Keyword: [Your secondary keyword here]\n";
    $full_prompt .= "- Focus on professional content with factual accuracy for Gemini, or conversational and engaging content for DeepSeek.\n";

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
            $error_code === 'quota_exceeded' ? 429 : 500
        );
    }

    // Extract meta title, description, synonym, and secondary keyword
    $meta_title = '';
    $meta_description = '';
    $synonym_keyword = '';
    $secondary_keyword = '';
    $content_lines = explode("\n", $response);
    $content_start_index = 0;

    foreach ($content_lines as $index => $line) {
        $line = trim($line);
        if (strpos($line, 'SEO Title:') === 0) {
            $meta_title = trim(substr($line, strlen('SEO Title:')));
            $content_start_index = $index + 1;
        } elseif (strpos($line, 'Meta Description:') === 0) {
            $meta_description = trim(substr($line, strlen('Meta Description:')));
            $content_start_index = $index + 1;
        } elseif (strpos($line, 'Synonym Keyword:') === 0) {
            $synonym_keyword = trim(substr($line, strlen('Synonym Keyword:')));
            $content_start_index = $index + 1;
        } elseif (strpos($line, 'Secondary Keyword:') === 0) {
            $secondary_keyword = trim(substr($line, strlen('Secondary Keyword:')));
            $content_start_index = $index + 1;
        }
    }

    $content = implode("\n", array_slice($content_lines, $content_start_index));

    // Add SEO guidance at the end of content
    $seo_guidance = "<h3>Hướng dẫn SEO</h3>";
    $seo_guidance .= "<p>Vui lòng nhập các thông tin sau vào plugin SEO (Yoast SEO hoặc Rank Math) để tối ưu bài viết:</p>";
    $seo_guidance .= "<ul>";
    $seo_guidance .= "<li><strong>Từ khóa chính:</strong> {$keywords}</li>";
    $seo_guidance .= "<li><strong>Từ khóa đồng nghĩa:</strong> {$synonym_keyword}</li>";
    $seo_guidance .= "<li><strong>Từ khóa phụ:</strong> {$secondary_keyword}</li>";
    $seo_guidance .= "<li><strong>Mô tả (Meta Description):</strong> {$meta_description}</li>";
    $seo_guidance .= "</ul>";

    $content .= $seo_guidance;

    $response_data = array(
        'success' => true,
        'data' => $content,
        'meta_title' => $meta_title,
        'meta_description' => $meta_description,
        'synonym_keyword' => $synonym_keyword,
        'secondary_keyword' => $secondary_keyword
    );

    // Cache the response
    aiseo_cache_content($cache_key, $response_data);
    
    // Save to history
    $user_id = get_current_user_id();
    if ($user_id) {
        aiseo_save_to_history($user_id, $prompt, $keywords, $api, $content, array(
            'meta_title' => $meta_title,
            'meta_description' => $meta_description,
            'synonym_keyword' => $synonym_keyword,
            'secondary_keyword' => $secondary_keyword
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
        
        aiseo_save_api_key('aiseo_gemini_api_key', $gemini_key);
        aiseo_save_api_key('aiseo_deepseek_api_key', $deepseek_key);
        
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
        <p>Your generated content history. Total: <?php echo $total; ?> items</p>
        
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
                            <td><?php echo date('M j, Y H:i', strtotime($item->created_at)); ?></td>
                            <td><strong><?php echo esc_html($item->keywords); ?></strong></td>
                            <td><span class="badge"><?php echo esc_html($item->api_used); ?></span></td>
                            <td><?php echo number_format($item->word_count); ?> words</td>
                            <td><?php echo esc_html($item->meta_title); ?></td>
                            <td>
                                <button type="button" class="button button-small" onclick="toggleContent(<?php echo $item->id; ?>)">View Content</button>
                            </td>
                        </tr>
                        <tr id="content-<?php echo $item->id; ?>" style="display: none;">
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
}
add_action('admin_init', 'aiseo_register_options');

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'aiseo_activate_plugin');
register_deactivation_hook(__FILE__, 'aiseo_deactivate_plugin');
?>