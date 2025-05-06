 # Redis Cache API Plugin

一个用于优化 WooCommerce REST API 性能的 WordPress 插件，通过 Redis 缓存来加速 API 响应。

## 功能特点

- 自动缓存 WooCommerce REST API 响应
- 支持产品、订单等 WooCommerce 数据的缓存
- 智能缓存清理机制
- 可配置的缓存过期时间
- 详细的缓存日志记录

## 安装要求

- WordPress 5.0 或更高版本
- PHP 7.2 或更高版本
- Redis 扩展
- WooCommerce 插件

## 安装步骤

1. 下载插件并上传到 WordPress 插件目录
2. 在 WordPress 后台激活插件
3. 确保 Redis 服务器已正确配置并运行
4. 插件会自动开始缓存 API 响应

## 缓存机制

### 缓存组
- `cache_redis_api_products`: 产品相关 API 缓存
- `cache_redis_api_orders`: 订单相关 API 缓存

### 缓存时间
- 默认缓存时间：1小时
- 可通过代码修改缓存时间

### 缓存清理
插件会在以下情况下自动清理缓存：
- 产品更新
- 产品创建
- 产品快速编辑
- 产品变体更新

## 开发文档

### 主要类
- `Cache_Manager`: 缓存管理核心类

### 主要方法
- `init()`: 初始化缓存管理器
- `check_cache()`: 检查缓存
- `save_cache()`: 保存缓存
- `clear_product_cache()`: 清理产品缓存

## 性能优化

- 使用 Redis 作为缓存后端
- 智能缓存键生成
- 按需缓存清理
- 缓存日志记录

## 注意事项

1. 确保 Redis 服务器正常运行
2. 定期检查缓存日志
3. 根据实际需求调整缓存时间
4. 在开发环境中注意缓存可能影响测试结果

## 技术支持

如有问题，请联系技术支持团队。

## 更新日志

### v1.0.0
- 初始版本发布
- 支持产品 API 缓存
- 支持订单 API 缓存
- 实现基本的缓存管理功能
