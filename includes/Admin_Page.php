<?php
namespace Redis_Cache_API;

class Admin_Page {
    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Redis Cache API', 'redis-cache-api'),
            __('Redis Cache', 'redis-cache-api'),
            'manage_options',
            'redis-cache-api',
            array($this, 'render_admin_page'),
            'dashicons-database'
        );
    }

    public function render_admin_page() {
        global $wpdb;
        
        // 获取缓存日志
        $logs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}redis_cache_logs 
             ORDER BY created_at DESC 
             LIMIT 100"
        );
        
        ?>
        <div class="wrap">
            <h1><?php _e('Redis Cache API', 'redis-cache-api'); ?></h1>
            
            <div class="card">
                <h2><?php _e('Cache Statistics', 'redis-cache-api'); ?></h2>
                <?php
                $stats = $wpdb->get_results(
                    "SELECT action, COUNT(*) as count 
                     FROM {$wpdb->prefix}redis_cache_logs 
                     GROUP BY action"
                );
                
                foreach ($stats as $stat) {
                    echo '<p>' . sprintf(
                        __('%s: %d', 'redis-cache-api'),
                        ucfirst($stat->action),
                        $stat->count
                    ) . '</p>';
                }
                ?>
            </div>
            
            <div class="card">
                <h2><?php _e('Recent Cache Logs', 'redis-cache-api'); ?></h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php _e('Cache Key', 'redis-cache-api'); ?></th>
                            <th><?php _e('Group', 'redis-cache-api'); ?></th>
                            <th><?php _e('Action', 'redis-cache-api'); ?></th>
                            <th><?php _e('Time', 'redis-cache-api'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html($log->cache_key); ?></td>
                            <td><?php echo esc_html($log->cache_group); ?></td>
                            <td><?php echo esc_html($log->action); ?></td>
                            <td><?php echo esc_html($log->created_at); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
} 