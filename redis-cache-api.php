<?php
/**
 * Plugin Name: Redis Cache API
 * Plugin URI: https://www.elegate.com.mx
 * Description: 使用 Redis 缓存 API 响应，提供更好的缓存管理功能
 * Version: 1.0.0
 * Author: Elegate
 * Author URI: https://www.elegate.com.mx
 * Text Domain: redis-cache-api
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 7.2
 */

if (!defined('ABSPATH')) {
    exit; // 禁止直接访问
}

// 定义插件常量
define('RCA_VERSION', '1.0.0');
define('RCA_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RCA_PLUGIN_URL', plugin_dir_url(__FILE__));

// 自动加载类
spl_autoload_register(function ($class) {
    $prefix = 'Redis_Cache_API\\';
    $base_dir = RCA_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// 初始化插件
function redis_cache_api_init() {
    // 检查 Redis 是否可用
    if (!class_exists('Redis')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 __('Redis Cache API 需要 Redis 扩展。请安装并启用 Redis 扩展。', 'redis-cache-api') . 
                 '</p></div>';
        });
        return;
    }

    // 初始化缓存管理器
    $cache_manager = new Redis_Cache_API\Cache_Manager();
    $cache_manager->init();

    // 初始化管理页面
    if (is_admin()) {
        $admin_page = new Redis_Cache_API\Admin_Page();
        $admin_page->init();
    }
}
add_action('plugins_loaded', 'redis_cache_api_init');

// 激活插件时的操作
register_activation_hook(__FILE__, function() {
    // 创建必要的数据库表
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    
    $table_name = $wpdb->prefix . 'redis_cache_logs';
    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        cache_key varchar(255) NOT NULL,
        cache_group varchar(50) NOT NULL,
        action varchar(20) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (id),
        KEY cache_key (cache_key),
        KEY cache_group (cache_group)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});
