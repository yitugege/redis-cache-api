<?php
namespace Redis_Cache_API;

class Cache_Manager {
    private $version_manager;
    private $default_expiration = 3600; // 默认缓存时间1小时

    public function __construct() {
        $this->version_manager = new Cache_Version();
    }

    public function init() {
        // 注册缓存相关的钩子
        add_action('rest_api_init', array($this, 'register_cache_hooks'));
        // 清除产品缓存
        add_action('woocommerce_rest_insert_product_object', array($this, 'clear_product_cache'), 10, 3);
        // 清除产品缓存
        add_action('woocommerce_update_product', array($this, 'clear_product_cache_on_update'), 10, 1);
    }

    public function register_cache_hooks() {
        // 在API请求前检查缓存
        add_filter('rest_pre_dispatch', array($this, 'check_cache'), 10, 3);
        // 在API响应后保存缓存
        add_filter('rest_post_dispatch', array($this, 'save_cache'), 10, 3);
    }

    public function check_cache($result, $server, $request) {
        // 只缓存GET请求
        if ($request->get_method() !== 'GET') {
            return $result;
        }

        $cache_key = $this->generate_cache_key($request);
        $cache_group = $this->get_cache_group($request);
        
        // 获取带版本的缓存键
        $versioned_key = $this->version_manager->get_versioned_key($cache_key, $cache_group);
        
        // 获取缓存
        $cached_data = wp_cache_get($versioned_key, $cache_group);
        
        if ($cached_data !== false) {
            // 记录缓存命中
            $this->log_cache_action($cache_key, $cache_group, 'hit');
            return $cached_data;
        }

        // 记录缓存未命中
        $this->log_cache_action($cache_key, $cache_group, 'miss');
        return $result;
    }

    public function save_cache($response, $server, $request) {
        // 只缓存GET请求
        if ($request->get_method() !== 'GET') {
            return $response;
        }

        $cache_key = $this->generate_cache_key($request);
        $cache_group = $this->get_cache_group($request);
        
        // 获取带版本的缓存键
        $versioned_key = $this->version_manager->get_versioned_key($cache_key, $cache_group);
        
        // 设置缓存
        wp_cache_set($versioned_key, $response, $cache_group, $this->default_expiration);
        
        // 记录缓存设置
        $this->log_cache_action($cache_key, $cache_group, 'set');
        
        return $response;
    }

    public function clear_product_cache($product, $request, $creating) {
        // 获取请求的路由
        $route = $request->get_route();
        
        // 如果是创建或更新产品，清理相关缓存
        if ($creating || $request->get_method() === 'PUT') {
            $this->clear_group_cache('products');
            $this->log_cache_action('*', 'products', 'clear');
        }
    }

    public function clear_product_cache_on_update($product_id) {
        $this->clear_group_cache('products');
        $this->log_cache_action('*', 'products', 'clear');
    }

    private function generate_cache_key($request) {
        $route = $request->get_route();
        $query_params = $request->get_query_params();
        
        // 生成唯一的缓存键
        $key_parts = array(
            $route,
            http_build_query($query_params)
        );
        
        return md5(implode('|', $key_parts));
    }

    private function get_cache_group($request) {
        $route = $request->get_route();
        
        // 根据路由确定缓存组
        if (strpos($route, '/wc/v3/products') !== false) {
            return 'products';
        }
        
        return 'default';
    }

    private function clear_group_cache($group) {
        $this->version_manager->clear_group($group);
    }

    private function log_cache_action($cache_key, $cache_group, $action) {
        global $wpdb;
        
        $wpdb->insert(
            $wpdb->prefix . 'redis_cache_logs',
            array(
                'cache_key' => $cache_key,
                'cache_group' => $cache_group,
                'action' => $action,
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );
    }
} 