<?php
namespace Redis_Cache_API;

/**
 * 缓存管理类
 * 用于管理缓存相关的操作
 * 缓存组:products
 * 缓存版本：1->2->3->4->5->6->7->8->9->10
 * 缓存键：/wc/v3/products
 * 缓存时间：1小时
 */
class Cache_Manager {
  
    private $default_expiration = 3600; // 默认缓存时间1小时
    private $cache_group_products = 'cache_redis_api_products';
    private $cache_group_orders = 'cache_redis_api_orders';

    /**
     * 初始化缓存管理器
     * 注册缓存相关的钩子
     */
    public function init() {
        // 注册缓存相关的钩子
        add_action('rest_api_init', array($this, 'register_cache_hooks'));
        // 清除产品缓存
        add_action('woocommerce_rest_insert_product_object', array($this, 'clear_product_cache'), 10, 2);
        // 清除产品缓存
        add_action('woocommerce_update_product', array($this, 'clear_product_cache'), 10, 1);
        // 清除产品缓存
        add_action('woocommerce_product_quick_edit_save', array($this, 'clear_product_cache'), 10, 1);
        // 清除变体产品缓存
        add_action('woocommerce_update_product_variation', array($this, 'clear_product_cache'), 10, 1);
    }

    /**
     * 注册缓存相关的钩子
     */
    public function register_cache_hooks() {
        // 在API请求前检查缓存
        add_filter('rest_pre_dispatch', array($this, 'check_cache'), 10, 3);
        // 在API响应后保存缓存
        add_filter('rest_post_dispatch', array($this, 'save_cache'), 10, 3);
    }

    /**
     * 检查缓存
     * @param mixed $result 请求结果
     * @param object $server 服务器对象
     * @param object $request 请求对象
     * @return mixed 缓存结果或原始结果
     */
    public function check_cache($result, $server, $request) {
        // 只缓存GET请求
        if ($request->get_method() !== 'GET') {
            return $result;
        }
         
        $cache_key = $this->generate_cache_key($request);
        $cache_group = $this->get_cache_group($request);
        
        // 获取缓存
        $cached_data = wp_cache_get($cache_key, $cache_group);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        return $result;
    }

    /**
     * 保存缓存
     * @param mixed $response 响应结果
     * @param object $server 服务器对象
     * @param object $request 请求对象
     * @return mixed 响应结果
     */
    public function save_cache($response, $server, $request) {
        // 只缓存GET请求
        if ($request->get_method() !== 'GET') {
            return $response;
        }
        
        $cache_key = $this->generate_cache_key($request);
        $cache_group = $this->get_cache_group($request);
        
        // 如果是WP_REST_Response对象，只缓存数据部分
        if ($response instanceof \WP_REST_Response) {
            $cache_data = $response->get_data();
        } else {
            $cache_data = $response;
        }
        
        // 设置缓存
        wp_cache_set($cache_key, $cache_data, $cache_group, $this->default_expiration);
        
        return $response;
    }

    /**
     * 清除产品缓存
     * @param object $product 产品对象
     * @param object $request 请求对象
     * @param bool $creating 是否为创建
     */
    public function clear_product_cache($product_id) {
        // 判断产品类型
        $product = wc_get_product($product_id);
        if($product->is_type('variable')) {
            // 变体产品 - 清除父产品和所有变体的缓存
            $variations = $product->get_children();
            foreach($variations as $variation_id) {
                $variation_key = '/wc/v3/products/'.$variation_id;
                $this->clear_key_cache($variation_key, $this->cache_group_products);
            }
            //父产品
            $parent_key = '/wc/v3/products/'.$product_id;
            $this->clear_key_cache($parent_key, $this->cache_group_products);
        }
        elseif($product->is_type('variation')){
            //子产品
            $product_id = $product->get_id();
            $key = '/wc/v3/products/'.$product_id;
            $this->clear_key_cache($key, $this->cache_group_products);
        }
        elseif($product->is_type('simple')){
            //简单产品
            $key = '/wc/v3/products/'.$product_id;
            $this->clear_key_cache($key, $this->cache_group_products);
        }
    }

    /**
     * 生成缓存键
     * @param object $request 请求对象
     * @return string 缓存键
     */
    private function generate_cache_key($request) {
        $route = $request->get_route();
        $query_params = $request->get_query_params();
        $query = http_build_query($query_params);
        // 生成唯一的缓存键
        if ( $query ) {
            $query = '?' . $query;
        }
        $full_uri = $route  . $query;
        return $full_uri;
    }

    /**
     * 获取缓存组
     * @param object $request 请求对象
     * @return string 缓存组
     */
    private function get_cache_group($request) {
        $route = $request->get_route();
        
        // 根据路由确定缓存组
        if (strpos($route, '/wc/v3/products') !== false) {
            return $this->cache_group_products;
        }
        else if (strpos($route, '/wc/v3/orders') !== false) {
            return $this->cache_group_orders;
        }
        
        return 'default';
    }

    /**
     * 清除缓存键
     * @param string $key 缓存键
     */
    private function clear_key_cache($key, $group) {
        wp_cache_delete($key, $group);
    }
    
    /**
     * 清除缓存组
     * @param string $group 缓存组名称
     */
    private function clear_group_cache($group) {
        // 直接清除整个缓存组
        wp_cache_flush_group($group);
    }
} 