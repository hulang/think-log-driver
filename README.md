# think-log-driver
think-log-driver

## 依赖
适用于`thinkphp6.*`
php: >=7.2

## 安装 
1. 安装`think-log-driver`
```sh
composer require hulang/think-log-driver
```

## 使用
1. 更改配置  
在`config/log.php` 中的配置修改
```php
// 日志记录方式
// 日志通道列表

    'channels'     => [
        'file'=>[],
        'database' => [
            // 日志记录方式
            'type'           => 'Database',
            // 大于0.05秒的sql将被记录
            'slow_sql_time'  => 0.5,
            // 记录日志的数据库配置，即在database.php中的driver
            // 如果设置该值为'default'，则使用系统数据库的实例
            'db_connect'     => 'default', //mongodb 
            // 记录慢日志查询的数据表名
            'db_table'       => 'log_sql',
            // 忽略的操作，在以下数据中的操作不会被记录
            'action_filters' => [
                // 'index/Index/lst'
            ],
            // 日志保存目录
            'path'           => '',
            // 单文件日志写入
            'single'         => false,
            // 独立日志级别
            'apart_level'    => [],
            // 最大日志文件数量
            'max_files'      => 0,
            // 使用JSON格式记录
            'json'           => false,
            // 日志处理
            'processor'      => null,
            // 关闭通道日志写入
            'close'          => false,
            // 日志输出格式化
            'format'         => '[%s][%s] %s',
            // 是否实时写入
            'realtime_write' => false,
        ],
],
```

2. 创建数据库  
用于记录日志的mysql数据表,如果使用mongodb则无需创建
```sql
DROP TABLE IF EXISTS `th_log_sql`;
CREATE TABLE `th_log_sql`  (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `year` int(4) NULL DEFAULT 0 COMMENT '年',
  `month` int(2) NULL DEFAULT 0 COMMENT '月',
  `day` int(2) NULL DEFAULT 0 COMMENT '日',
  `host` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '请求的Host',
  `url` varchar(200) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '请求的URL',
  `ip` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT 'IP',
  `method` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '提交方式',
  `app` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '应用',
  `controller` varchar(30) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '控制器',
  `action` varchar(50) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '' COMMENT '方法',
  `runtime` decimal(10, 3) UNSIGNED NOT NULL DEFAULT 0.000 COMMENT '运行时长',
  `sql_list` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT 'SQL语句',
  `param` text CHARACTER SET utf8 COLLATE utf8_general_ci NULL COMMENT '参数',
  `create_date` datetime NULL DEFAULT NULL COMMENT '添加时间',
  `create_time` int(11) NULL DEFAULT 0 COMMENT '添加时间戳',
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `runtime`(`runtime`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 1 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;
```
