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
        add_action('woocommerce_update_product', array($this, 'clear_product_cache'), 10, 1);
        // 清除产品缓存
        add_action('woocommerce_product_quick_edit_save', array($this, 'clear_product_cache'), 10, 1);
        // 清除变体产品缓存
        add_action('woocommerce_update_product_variation', array($this, 'clear_product_cache'), 10, 1);
        // 在POST API成功后清除产品列表缓存
        add_action('woocommerce_rest_insert_product_object', array($this, 'clear_products_list_cache'), 20, 1);
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
        $result = wp_cache_set($cache_key, $cache_data, $cache_group, $this->default_expiration);
        if($result){
            // 设置缓存索引
            $cache_index = 'index';
            
            // 读取现有的缓存索引
            $cached_index = wp_cache_get($cache_index, $cache_group);
            if($cached_index === false){
                $cached_index = array();
            }
            
            // 检查是否已存在相同的cache_key
            $key_exists = false;
            foreach($cached_index as &$index){
                if($index['cache_key'] === $cache_key){
                    // 更新已存在的缓存索引
                    $index['cache_data'] = $cache_data;
                    $index['cache_expiration'] = $this->default_expiration;
                    $index['cache_group'] = $cache_group;
                    $key_exists = true;
                    break;
                }
            }
            
            // 如果不存在，添加新的缓存索引
            if(!$key_exists){
                $cached_index[] = array(
                    'cache_key' => $cache_key,
                    'cache_data' => $cache_data,
                    'cache_expiration' => $this->default_expiration,
                    'cache_group' => $cache_group
                );
            }
            
            // 保存更新后的缓存索引
            wp_cache_set($cache_index, $cached_index, $cache_group, $this->default_expiration);
        }
        else{
            error_log('缓存设置失败: '.$cache_key);
        }
        
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
                $this->clear_key_cache($variation_key, $this->cache_group_products,$variation_id);
            }
            //父产品
            $parent_key = '/wc/v3/products/'.$product_id;
            $this->clear_key_cache($parent_key, $this->cache_group_products,$product_id);
        }
        elseif($product->is_type('variation')){
            //子产品
            $product_id = $product->get_id();
            $key = '/wc/v3/products/'.$product_id;
            $this->clear_key_cache($key, $this->cache_group_products,$product_id);
        }
        elseif($product->is_type('simple')){
            //简单产品
            $key = '/wc/v3/products/'.$product_id;
            $this->clear_key_cache($key, $this->cache_group_products,$product_id);
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
     * @param string $group 缓存组
     * @param int $product_id 产品ID
     */
    private function clear_key_cache($key, $group, $product_id) {
        // 删除指定的缓存键
        wp_cache_delete($key, $group);
        
        // 获取缓存索引
        $cached_index = wp_cache_get('index', $group);
        if($cached_index === false) {
            return;
        }
        
        // 用于存储需要删除的缓存键
        $keys_to_delete = array();
        
        // 遍历缓存索引
        foreach($cached_index as $index) {
            // 检查缓存键是否匹配
            if($index['cache_key'] === $key) {
                $keys_to_delete[] = $index['cache_key'];
                continue;
            }
            
            // 检查缓存数据中的产品ID是否匹配
            if(isset($index['cache_data'])) {
                // 递归检查缓存数据中的所有产品ID
                $check_product_id = function($data) use ($product_id, &$check_product_id) {
                    if(is_array($data)) {
                        // 如果是数组，检查每个元素
                        foreach($data as $item) {
                            // 如果元素是数组且包含id字段
                            if(is_array($item) && isset($item['id']) && $item['id'] === $product_id) {
                                return true;
                            }
                            // 递归检查嵌套数组
                            if(is_array($item) && $check_product_id($item)) {
                                return true;
                            }
                        }
                    }
                    return false;
                };
                
                if($check_product_id($index['cache_data'])) {
                    $keys_to_delete[] = $index['cache_key'];
                }
            }
            
            // 检查缓存键中是否包含产品ID
            if(strpos($index['cache_key'], (string)$product_id) !== false) {
                $keys_to_delete[] = $index['cache_key'];
            }
        }
        
        // 删除所有匹配的缓存
        foreach($keys_to_delete as $key_to_delete) {
            wp_cache_delete($key_to_delete, $group);
        }
        
        // 更新缓存索引
        $updated_index = array_filter($cached_index, function($index) use ($keys_to_delete) {
            return !in_array($index['cache_key'], $keys_to_delete);
        });
        
        // 保存更新后的缓存索引
        wp_cache_set('index', array_values($updated_index), $group, $this->default_expiration);
        
        // 记录删除的缓存
        if(!empty($keys_to_delete)) {
            error_log('已删除的缓存键: ' . implode(', ', $keys_to_delete));
        }
    }
    
    /**
     * 清除缓存组
     * @param string $group 缓存组名称
     */
    private function clear_group_cache($group) {
        // 直接清除整个缓存组
        wp_cache_flush_group($group);
    }

    /**
     * 清除产品列表缓存
     * @param object $product 产品对象
     */
    public function clear_products_list_cache($product) {
        // 清除产品列表缓存
        $list_key = '/wc/v3/products';
        $this->clear_key_cache($list_key, $this->cache_group_products, 0);
        
        // 获取缓存索引
        $cached_index = wp_cache_get('index', $this->cache_group_products);
        if($cached_index !== false) {
            // 用于存储需要删除的缓存键
            $keys_to_delete = array();
            
            // 遍历缓存索引
            foreach($cached_index as $index) {
                // 检查缓存键是否以 /wc/v3/products 开头
                if(strpos($index['cache_key'], '/wc/v3/products') === 0) {
                    $keys_to_delete[] = $index['cache_key'];
                }
            }
            
            // 删除所有匹配的缓存
            foreach($keys_to_delete as $key_to_delete) {
                wp_cache_delete($key_to_delete, $this->cache_group_products);
            }
            
            // 更新缓存索引
            $updated_index = array_filter($cached_index, function($index) use ($keys_to_delete) {
                return !in_array($index['cache_key'], $keys_to_delete);
            });
            
            // 保存更新后的缓存索引
            wp_cache_set('index', array_values($updated_index), $this->cache_group_products, $this->default_expiration);
            
            // 记录删除的缓存
            if(!empty($keys_to_delete)) {
                error_log('已删除的产品列表缓存键: ' . implode(', ', $keys_to_delete));
            }
        }
    }
} 