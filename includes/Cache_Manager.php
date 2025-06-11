<?php
namespace Redis_Cache_API;

/**
 * 缓存管理类
 * 用于管理缓存相关的操作
 * 缓存组:products
 * 缓存键：/wc/v3/products
 * 缓存时间：1小时
 */
class Cache_Manager {
  
    private $default_expiration = WP_REDIS_MAXTTL; // 默认缓存时间1小时
    private $cache_group_products = 'cache_redis_api_products';
    private $cache_group_orders = 'cache_redis_api_orders';
    private $user_id_array = ['35485','35486'];  // 用户id数组用于限制api访问权限
  

    /**
     * 获取用户的缓存组名称
     * @param string $type 缓存类型 (products|orders)
     * @return string 缓存组名称
     */
    private function get_user_cache_group($type) {
        $current_user = wp_get_current_user();
        $this->cache_group_products = 'cache_redis_api_products_'.$current_user->ID;
        $this->cache_group_orders = 'cache_redis_api_orders_'.$current_user->ID;
        return $type === 'products' ? $this->cache_group_products : $this->cache_group_orders;
    }

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
        add_action('woocommerce_rest_insert_product_object', array($this, 'clear_products_api_list_cache'), 20, 3);
        //在订单更新后清除订单缓存
        add_action('woocommerce_order_status_changed', array($this, 'clear_order_cache_list_cache'), 20, 1);
        
        // 设置当前用户的缓存组
        $this->cache_group_products = $this->get_user_cache_group('products');
        $this->cache_group_orders = $this->get_user_cache_group('orders');
    }

    /**
     * 注册缓存相关的钩子
     */
    public function register_cache_hooks() {
        // 限制API访问权限
        add_filter('rest_pre_dispatch', array($this, 'check_permission'), 10, 3);
        // 在API请求前检查缓存
        add_filter('rest_pre_dispatch', array($this, 'check_cache'), 99, 3);
        // 在API响应后保存缓存
        add_filter('rest_post_dispatch', array($this, 'save_cache'), 99, 3);
    }

    /**
     * 检查API访问权限
     * @param mixed $result 请求结果
     * @param object $server 服务器对象
     * @param object $request 请求对象
     * @return mixed 请求结果
     */
    public function check_permission($result, $server, $request) {
        // 如果已经是错误响应，直接返回
        if (is_wp_error($result)) {
            return $result;
        }
        //获取当前用户
        $current_user = wp_get_current_user();
        if(in_array($current_user->ID, $this->user_id_array)){
            // 获取请求路径
            $route = $request->get_route();

            // 获取请求方法
            $method = $request->get_method();
            if($method === 'GET'){
                if(strpos($route, '/wc/v3/products') !== false || strpos($route, '/wc/v3/elegate-products') !== false){
                    return $result;
                }else{
                    status_header(403);
                    header('Content-Type: application/json');
                    die(json_encode([
                        'code' => 'rest_forbidden',
                        'message' => 'you have no permission',
                        'data' => ['status' => 403]
                    ]));
                }
            }else{
                status_header(403);
                header('Content-Type: application/json');
                die(json_encode([
                    'code' => 'rest_forbidden',
                    'message' => 'you have no permission',
                    'data' => ['status' => 403]
                ]));
            }
        }else{
            return $result;
        }
    }

    /**
     * 检查缓存
     * @param mixed $result 请求结果
     * @param object $server 服务器对象
     * @param object $request 请求对象
     * @return mixed 缓存结果或原始结果
     */
    public function check_cache($result, $server, $request) {
        // 只缓存GET请求和路由以/wc/v3/products开头的请求
        if(!$this->is_route_cache_api($request) || $request->get_method() !== 'GET'){
            return $result;
        }
         
        $cache_key = $this->generate_cache_key($request);
        $cache_group = $this->get_cache_group($request);
        
        // 获取缓存
        $cached_data = wp_cache_get($cache_key, $cache_group);
        
        // 如果缓存存在且有效
        if ($cached_data !== false) {
            // 检查缓存数据是否有效
            if (is_array($cached_data) && isset($cached_data['data']['status']) && $cached_data['data']['status'] == 400) {
                error_log('缓存数据无效: '.$cache_key);
                return $result;
            }
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
        if(!$this->is_route_cache_api($request) || $request->get_method() !== 'GET'){
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

        if ($response->get_status() != 200) {
            error_log('缓存设置失败: '.$cache_key);
            return $response;
        }

        // 设置缓存
        $result = wp_cache_set($cache_key, $cache_data, $cache_group, $this->default_expiration);

        if($result){
            // 设置缓存索引
            $cache_index = 'index';

            
        // 解析缓存数据中的ID
        $item_ids = array();
        if (is_array($cache_data)) {
            // 如果是列表数据
            if (isset($cache_data[0]) && is_array($cache_data[0])) {
                foreach ($cache_data as $item) {
                    if (isset($item['id'])) {
                        $item_ids[] = $item['id'];
                    }
                }
            }
            // 如果是单个数据
            else if (isset($cache_data['id'])) {
                    $item_ids[] = $cache_data['id'];
                }
            }
            
            // 读取现有的缓存索引
            $cached_index = wp_cache_get($cache_index, $cache_group);
            if($cached_index === false){
                $cached_index = array();
            }
            
            // 为每个ID创建缓存索引条目
            foreach ($item_ids as $item_id) {
                $new_index_entry = array(
                    'cache_key' => $cache_key,
                    'id' => $item_id,
                    'cache_expiration' => $this->default_expiration,
                    'cache_group' => $cache_group
                );
                
                // 检查是否已存在相同的cache_key和id组合
                $key_exists = false;
                foreach($cached_index as $key => $index){
                    if($index['cache_key'] === $cache_key && $index['id'] === $item_id){
                        // 更新已存在的缓存索引
                        $cached_index[$key] = $new_index_entry;
                        $key_exists = true;
                        break;
                    }
                }
                
                // 如果不存在，添加新的缓存索引
                if(!$key_exists){
                    $cached_index[] = $new_index_entry;
                }
            }
            
            // 保存更新后的缓存索引
            wp_cache_set($cache_index, $cached_index, $cache_group, $this->default_expiration);
            
            // 记录缓存索引
            //error_log('Cache Index: ' . json_encode($cached_index, JSON_PRETTY_PRINT));
        }
        else{
            error_log('缓存设置失败: '.$cache_key);
        }
        
        return $response;
    }

    //定义api路由,用于判断是否需要缓存
    /**
     * 判断API路由是否需要缓存
     * 
     * 检查请求的路由是否需要进行缓存处理
     * 目前支持缓存的路由:
     * - /wc/v3/products/* - 产品相关API
     * - /wc/v3/orders/* - 订单相关API
     * 
     * @param object $request WP_REST_Request对象
     * @return bool 如果路由需要缓存返回true,否则返回false
     */
    private function is_route_cache_api($request){
        $route = $request->get_route();
        if(strpos($route, '/wc/v3/products') !== false){
            return true;
        }
        else if(strpos($route, '/wc/v3/orders') !== false){
            return true;
        }
        else if(strpos($route, '/wc/v3/elegate-products') !== false){
            return true;
        }
        else{
            return false;
        }
    }

    /**
     * 清除产品缓存
     * @param object $produc_id 产品ID
     * 
     * 
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


        
        my_sync_product_info($product_id);
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
     * 清除订单列表缓存
     * @param int $order_id 订单ID
     * 
     * 
     */
    function clear_order_cache_list_cache($order_id){
      
    
        $key = '/wc/v3/orders/'.$order_id;
        $this->clear_key_cache($key, $this->cache_group_orders, $order_id);
      
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
        else if (strpos($route, '/wc/v3/elegate-products') !== false) {
            return $this->cache_group_products;
        }
        
        return 'default';
    }

    /**
     * 清除缓存键
     * @param string $key 缓存键
     * @param string $group 缓存组
     * @param int $id 产品ID或者订单id
     * 1.删除指定的缓存键
     * 2.获取缓存索引,如果缓存索引不存在,则返回,操作索引缓存里面的id和传递的id进行对比,如果匹配,则删除缓存
     */
    private function clear_key_cache($key, $group, $id) {
        //1 删除指定的缓存键
        wp_cache_delete($key, $group);
    
        //2 获取该产品ID对应的所有缓存键
        $cache_keys = $this->get_cache_keys_by_id($id, $group);
        //3 删除所有相关的缓存
        foreach($cache_keys as $cache_key) {
            wp_cache_delete($cache_key, $group);  
        }
        //4 清除缓存索引
        $this->clear_index_cache($id, $group);

       
    }

    /**
     * 清除缓存索引
     * @param int $id 产品ID
     * @param string $group 缓存组
     * 
     */
    private function clear_index_cache($id, $group){
        

        // 获取缓存索引,如果缓存索引不存在,则返回
        $cached_index = wp_cache_get('index', $group);
        if($cached_index === false) {
            return;
        }
        
        // 3. 只过滤出需要保留的索引项（更高效的做法）
        $updated_index = array_filter($cached_index, function($index) use ($id) {
            return $index['id'] !== $id;
        });

        // 4. 只有当索引确实有变化时才更新
        if(count($updated_index) !== count($cached_index)) {
            wp_cache_set('index', array_values($updated_index), $group);
            error_log('已更新缓存索引移除产品ID: ' . $id);
        }
    }
    
    
    /**
     * 通过ID获取缓存键
     * @param int $id 产品ID
     * @param string $group 缓存组
     * @return array 包含所有匹配的cache_key的数组
     */
    private function get_cache_keys_by_id($id, $group) {
        $cache_keys = array();
        
        // 获取缓存索引
        $cached_index = wp_cache_get('index', $group);
        if($cached_index === false) {
            return $cache_keys;
        }      
        // 遍历缓存索引查找匹配的ID
        foreach($cached_index as $index) {
            if($index['id'] === $id) {
                $cache_keys[] = $index['cache_key'];
            }
        }
        
        return $cache_keys;
    }

    /**
     * 清除产品列表缓存
     * @param object $product 产品对象
     * @param object $request 请求对象
     * @param bool $creating 是否正在创建
     */
    public function clear_products_api_list_cache($product, $request, $creating) {
        if($creating) {
            error_log('正在创建产品');
            return;
        }
    }
} 