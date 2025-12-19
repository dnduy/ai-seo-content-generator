<?php
// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Create database table for content history
function aiseo_create_history_table() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'aiseo_history';
    
    $charset_collate = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $table_name (
        id int(11) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        prompt text NOT NULL,
        keywords varchar(255) NOT NULL,
        api_used varchar(50) NOT NULL,
        content longtext NOT NULL,
        meta_title varchar(255) DEFAULT '',
        meta_description varchar(300) DEFAULT '',
        synonym_keyword varchar(255) DEFAULT '',
        secondary_keyword varchar(255) DEFAULT '',
        word_count int(11) DEFAULT 0,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY user_id (user_id),
        KEY created_at (created_at)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Save content to history
function aiseo_save_to_history($user_id, $prompt, $keywords, $api_used, $content, $meta_data = array()) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'aiseo_history';
    
    // Calculate word count
    $word_count = str_word_count(strip_tags($content));
    
    $result = $wpdb->insert(
        $table_name,
        array(
            'user_id' => $user_id,
            'prompt' => sanitize_textarea_field($prompt),
            'keywords' => sanitize_text_field($keywords),
            'api_used' => sanitize_text_field($api_used),
            'content' => wp_kses_post($content),
            'meta_title' => sanitize_text_field($meta_data['meta_title'] ?? ''),
            'meta_description' => sanitize_text_field($meta_data['meta_description'] ?? ''),
            'synonym_keyword' => sanitize_text_field($meta_data['synonym_keyword'] ?? ''),
            'secondary_keyword' => sanitize_text_field($meta_data['secondary_keyword'] ?? ''),
            'word_count' => $word_count,
            'created_at' => current_time('mysql')
        ),
        array('%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s')
    );
    
    if ($result === false) {
        error_log('AISEO: Failed to save content to history: ' . $wpdb->last_error);
    } else {
        error_log('AISEO: Content saved to history with ID: ' . $wpdb->insert_id);
    }
    
    return $result;
}

// Get user's content history
function aiseo_get_user_history($user_id, $limit = 10, $offset = 0) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'aiseo_history';
    
    $sql = $wpdb->prepare(
        "SELECT * FROM $table_name WHERE user_id = %d ORDER BY created_at DESC LIMIT %d OFFSET %d",
        $user_id, $limit, $offset
    );
    
    return $wpdb->get_results($sql);
}

// Get total count for user
function aiseo_get_user_history_count($user_id) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'aiseo_history';
    
    return $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_name WHERE user_id = %d",
        $user_id
    ));
}

// Delete old history (cleanup)
function aiseo_cleanup_old_history($days = 30) {
    global $wpdb;
    
    // Validate days parameter
    $days = absint($days);
    if ($days < 1) {
        $days = 30;
    }
    
    $table_name = $wpdb->prefix . 'aiseo_history';
    
    $result = $wpdb->query($wpdb->prepare(
        "DELETE FROM $table_name WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
        $days
    ));
    
    if ($result !== false) {
        error_log('AISEO: Cleaned up ' . $result . ' old history records');
    }
    
    return $result;
}

// Plugin activation hook
function aiseo_activate_plugin() {
    aiseo_create_history_table();
    
    // Schedule cleanup task
    if (!wp_next_scheduled('aiseo_cleanup_history')) {
        wp_schedule_event(time(), 'weekly', 'aiseo_cleanup_history');
    }
}

// Plugin deactivation hook
function aiseo_deactivate_plugin() {
    wp_clear_scheduled_hook('aiseo_cleanup_history');
}

// Cleanup hook
add_action('aiseo_cleanup_history', 'aiseo_cleanup_old_history');
?>