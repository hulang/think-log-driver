<?php

declare(strict_types=1);

namespace think\log\driver;

use think\App;
use think\facade\Config;
use think\facade\Db;
use think\contract\LogHandlerInterface;

class Database implements LogHandlerInterface
{
    /**
     * 配置参数
     * @var mixed|array
     */
    protected $config = [
        'time_format' => 'c',
        'single' => false,
        'file_size' => 2097152,
        'path' => '',
        'apart_level' => [],
        'max_files' => 0,
        'json' => false,
        'json_options' => JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        'format' => '[%s][%s] %s',
    ];

    /**
     * App
     * @var mixed
     */
    protected $app;

    /**
     * 日志处理器构造函数
     * 
     * @param App $app 依赖注入的应用程序对象
     * @param array $config 日志处理器的配置数组,默认为空数组
     * 
     * 构造函数用于初始化日志处理器,配置包括日志格式和存储路径等
     * 如果未提供配置,则使用默认配置
     * 路径配置确保以目录分隔符结尾
     */
    public function __construct(App $app, $config = [])
    {
        $this->app = $app;
        // 合并传入的配置数组与默认配置数组
        if (is_array($config)) {
            $this->config = array_merge($this->config, $config);
        }
        // 设置默认日志格式,如果未在配置中指定
        if (empty($this->config['format'])) {
            $this->config['format'] = '[%s][%s] %s';
        }
        // 设置默认日志存储路径,如果未在配置中指定
        if (empty($this->config['path'])) {
            $this->config['path'] = $app->getRuntimePath() . 'log';
        }
        // 确保日志路径以目录分隔符结尾
        if (substr($this->config['path'], -1) != DIRECTORY_SEPARATOR) {
            $this->config['path'] .= DIRECTORY_SEPARATOR;
        }
    }

    /**
     * 保存日志到数据库和文件
     * 
     * @param array $log 日志数组,包含不同类型的日志信息
     * @param bool $append 是否将日志追加到现有文件,默认为false（覆盖现有文件）
     * @return mixed|bool 返回保存操作的结果,通常是true,除非写入操作失败
     */
    public function save(array $log, bool $append = false): bool
    {
        // 将日志写入数据库
        $this->writeDb($log);
        // 获取主日志文件路径
        $destination = $this->getMasterLogFile();
        // 确保日志文件的目录存在,如果不存在则创建
        $path = dirname($destination);
        if (!is_dir($path)) {
            mkdir($path, 0755, true);
        }
        $info = [];
        // 格式化当前时间,用于日志记录
        // 日志信息封装
        $time = \DateTime::createFromFormat('0.u00 U', microtime())->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format($this->config['time_format']);
        // 遍历日志数组中的每种类型日志,准备写入文件
        foreach ($log as $type => $val) {
            $message = [];
            foreach ($val as $msg) {
                // 确保日志消息是字符串,如果不是,则使用var_export转为字符串表示
                if (!is_string($msg)) {
                    $msg = var_export($msg, true);
                }
                // 根据配置,选择使用JSON格式还是自定义格式记录日志
                $message[] = $this->config['json'] ?
                    json_encode(['time' => $time, 'type' => $type, 'msg' => $msg], $this->config['json_options']) :
                    sprintf($this->config['format'], $time, $type, $msg);
            }
            // 如果配置了独立级别日志,那么将该类型日志写入到对应的文件中
            if (true === $this->config['apart_level'] || in_array($type, $this->config['apart_level'])) {
                // 独立记录的日志级别
                $filename = $this->getApartLevelFile($path, $type);
                $this->write($message, $filename, true, $append);
                continue;
            }
            // 将普通日志类型及其消息存储,准备写入主日志文件
            $info[$type] = $message;
        }
        // 写入汇总的日志信息到主日志文件
        if ($info) {
            return $this->write($info, $destination, false, $append);
        }
        // 如果没有需要写入主日志文件的信息,则直接返回true表示操作成功
        return true;
    }

    /**
     * 将日志信息写入数据库
     * 仅在非CLI环境下运行,并且只有在存在SQL语句或错误信息,或者请求参数不为空时才执行
     * 忽略配置中指定的行动
     * 记录包括SQL运行时间最长的SQL语句、请求方法、请求URL、应用名称、控制器、操作、请求参数等信息
     * 根据配置中指定的数据库连接方式,选择不同的插入数据方法
     * 
     * @param array $message 包含日志信息的数组,可能包含SQL和错误信息
     * @return mixed|\Exception|string 返回操作结果,可能是成功提示、异常对象或空字符串
     */
    protected function writeDb(array $message)
    {
        // 在CLI环境下不执行日志写入
        if (PHP_SAPI == 'cli') {
            return '';
        }
        // 如果没有SQL语句、错误信息且请求参数为空,则不执行写入
        if (!isset($message['sql']) && !isset($message['error']) && empty($this->app->request->get()) && empty($this->app->request->post())) {
            return '';
        }
        // 获取日志数据库连接配置和应用名称
        $log_db_connect = Config::get('log.db_connect', 'default');
        $app_name = app('http')->getName();
        // 获取当前控制器和操作名称
        $controller = $this->app->request->controller();
        $action = $this->app->request->action();
        // 检查是否在忽略行动的列表中,如果是则不执行写入
        if (in_array($app_name . '/' . $controller . '/' . $action, $this->config['action_filters'])) {
            return '';
        }
        // 初始化SQL信息数组和最长运行时间
        $sql = [];
        $runtime_max = 0;
        // 处理SQL信息,忽略某些特定的SQL语句,记录运行时间最长的SQL
        if (isset($message['sql'])) {
            foreach ($message['sql'] as $v) {
                $db_k = 0;
                if (strstr($v, 'SHOW FULL COLUMNS') || strstr($v, 'CONNECT:')) {
                    continue;
                }
                $runtime = (float)substr($v, strrpos($v, 'RunTime:') + 8, -3);
                if ($runtime >= $this->config['slow_sql_time']) {
                    $sql[] = [
                        'db' => substr($message['sql'][$db_k], 30),
                        'sql' => $v,
                        'runtime' => $runtime,
                    ];
                    if ($runtime_max < $runtime) {
                        $runtime_max = $runtime;
                    }
                }
            }
        }
        // 如果最长运行时间小于等于0,则不执行写入
        if ($runtime_max <= 0) {
            return '';
        }
        // 准备日志信息,包括时间、请求参数、SQL列表、错误信息等
        $time = time();
        $param = [
            'get' => $this->app->request->get(),
            'post' => $this->app->request->post(),
            'sql' => isset($message['sql']) ?? [],
            'error' => isset($message['error']) ?? [],
        ];
        $info = [
            'year' => date('Y', $time),
            'month' => date('m', $time),
            'day' => date('d', $time),
            'ip' => $this->app->request->ip(),
            'method' => $this->app->request->method(),
            'host' => $this->app->request->host(),
            'url' => $this->app->request->url(),
            'app' => $app_name,
            'controller' => $controller,
            'action' => $action,
            'create_time' => $time,
            'create_date' => date('Y-m-d H:i:s', $time),
            'runtime' => $runtime_max,
        ];
        // 根据数据库连接类型,对SQL和参数信息进行序列化处理
        if ($log_db_connect === 'mongodb') {
            $info['sql_list'] = $sql;
            $info['param'] = $param;
        } else {
            $info['sql_list'] = json_encode($sql);
            $info['param'] = json_encode($param);
        }
        // 决定使用的日志表名
        $log_table = $this->config['db_table'];
        // 默认写入成功的消息
        $msg = 'success';
        // 根据日志数据库连接配置,执行相应的数据插入操作
        if ($log_db_connect === 'default') {
            try {
                Db::name($log_table)->insert($info);
            } catch (\Exception $e) {
                $msg = $e;
            }
        } else {
            try {
                Db::connect($log_db_connect)->name($log_table)->insert($info);
            } catch (\Exception $e) {
                $msg = $e;
            }
        }
        return $msg;
    }

    /**
     * 写入日志
     * 将给定的消息数组写入指定的日志文件
     * 支持将消息写入单独的文件,以及追加请求信息
     * 
     * @param array $message 日志消息数组,其中键为日志类型,值为日志消息
     * @param string $destination 日志文件的路径
     * @param bool $apart 是否将消息写入单独的日志文件,默认为false,表示写入同一文件
     * @param bool $append 是否追加请求信息到日志,默认为false,表示不追加
     * @return mixed|bool 写入日志的成功与否
     */
    protected function write(array $message, string $destination, bool $apart = false, bool $append = false): bool
    {
        // 检查日志文件大小,如果超过预定大小,则备份当前日志文件并创建新的日志文件
        // 检测日志文件大小,超过配置大小则备份日志文件重新生成
        $this->checkLogSize($destination);
        $info = [];
        // 遍历消息数组,将消息字符串化,并按类型组织
        foreach ($message as $type => $msg) {
            $info[$type] = is_array($msg) ? implode(PHP_EOL, $msg) : $msg;
        }
        // 根据配置,获取调试信息,并可能地追加到日志中
        $this->getDebugLog($info, $append, $apart);
        // 将所有消息合并为一个字符串,并在每条消息后添加换行符
        $message = implode(PHP_EOL, $info) . PHP_EOL;
        // 使用PHP的error_log函数将消息写入日志文件,返回写入的成功与否
        return error_log($message, 3, $destination);
    }

    /**
     * 获取主日志文件的路径和名称
     * 
     * 此方法根据配置确定日志文件的命名和存储位置
     * 如果配置指定单个日志文件,则返回该单个文件的路径
     * 如果配置允许多个日志文件,并且已达到最大文件数,则会删除最旧的日志文件以保持数量在限制之内
     * 
     * @access protected
     * @return mixed|string 主日志文件的完整路径
     */
    protected function getMasterLogFile(): string
    {
        // 如果配置了最大文件数,则检查并可能删除多余的日志文件
        if ($this->config['max_files']) {
            $files = glob($this->config['path'] . '*.log');
            try {
                if (count($files) > $this->config['max_files']) {
                    unlink($files[0]);
                }
            } catch (\Exception $e) {
                // 忽略删除文件时可能发生的异常
            }
        }
        // 根据配置决定日志文件名的生成逻辑
        if ($this->config['single']) {
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';
            $destination = $this->config['path'] . $name . '.log';
        } else {
            if ($this->config['max_files']) {
                $filename = date('Ymd') . '.log';
            } else {
                $filename = date('Ym') . DIRECTORY_SEPARATOR . date('d') . '.log';
            }
            $destination = $this->config['path'] . $filename;
        }
        return $destination;
    }

    /**
     * 根据配置获取独立日志文件名
     * 此方法用于根据日志记录策略决定日志文件的命名,以实现日志的分类存储
     * @param string $path 日志目录的路径,用于构建日志文件的完整路径
     * @param string $type 日志的类型,用于区分不同类型的日志,例如错误日志、访问日志等
     * @return mixed|string 返回构建好的日志文件名,格式为‘日期_类型.log’
     */
    protected function getApartLevelFile(string $path, string $type): string
    {
        // 根据配置判断是否使用单个日志文件还是按日期分割日志文件
        if ($this->config['single']) {
            // 如果配置为单个日志文件,使用'single'作为文件名,或使用配置中指定的字符串
            $name = is_string($this->config['single']) ? $this->config['single'] : 'single';
        } elseif ($this->config['max_files']) {
            // 如果配置了最大文件数,按年月日分割日志,确保日志文件数量不会过多
            $name = date('Ymd');
        } else {
            // 如果以上配置都未设置,按天分割日志文件
            $name = date('d');
        }
        // 构建并返回日志文件的完整路径和文件名
        return $path . DIRECTORY_SEPARATOR . $name . '_' . $type . '.log';
    }

    /**
     * 检查日志文件大小,如果超过配置的大小则创建一个备份文件
     * 
     * 此方法定期检查指定的日志文件是否超过了预设的最大大小
     * 如果超过,它会尝试创建一个备份文件,备份文件的命名格式为原文件名加上当前时间戳,以避免覆盖之前的备份
     * 
     * @param string $destination 日志文件的路径
     * @return mixed 无返回值
     */
    protected function checkLogSize(string $destination)
    {
        // 检查文件是否存在,且文件大小是否超过配置的大小
        if (is_file($destination) && floor($this->config['file_size']) <= filesize($destination)) {
            try {
                // 重命名文件为当前时间戳加上原文件名的形式,创建一个备份文件
                rename($destination, dirname($destination) . DIRECTORY_SEPARATOR . time() . '-' . basename($destination));
            } catch (\Exception $e) {
                // 如果在重命名过程中发生异常,捕获但不处理,允许方法继续执行
                // 这种处理方式可能需要根据实际应用场景调整
            }
        }
    }

    /**
     * 获取调试日志信息
     * 该方法用于在应用的调试模式下,根据不同的配置方式,添加运行时的调试信息到传入的信息数组中
     * @param array &$info 调试信息数组,方法将运行时信息追加到这个数组中
     * @param bool $append 是否追加调试信息到数组,默认为true表示追加
     * @param bool $apart 是否将调试信息以分开的形式添加,默认为false表示不分开
     * @return mixed 返回调整后的调试信息数组
     */
    protected function getDebugLog(&$info, bool $append, bool $apart)
    {
        // 检查是否处于调试模式且是否需要追加信息
        if ($this->app->isDebug() && $append) {
            // 当配置为以JSON格式输出时
            if ($this->config['json']) {
                // 计算运行时间、请求速率、内存使用和加载文件数
                // 获取基本信息
                $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
                $reqs = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
                $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);
                $file_count = count(get_included_files());
                // 将运行时信息合并到调试信息数组中
                $info = [
                    'runtime' => number_format($runtime, 6) . 's',
                    'reqs' => $reqs . 'req/s',
                    'memory' => $memory_use . 'kb',
                    'file' => $file_count,
                ] + $info;
            } elseif (!$apart) {
                // 计算运行时间、请求速率、内存使用和加载文件数
                // 增加额外的调试信息
                $runtime = round(microtime(true) - $this->app->getBeginTime(), 10);
                $reqs = $runtime > 0 ? number_format(1 / $runtime, 2) : '∞';
                $memory_use = number_format((memory_get_usage() - $this->app->getBeginMem()) / 1024, 2);
                $file_count = count(get_included_files());

                // 构造调试信息字符串,并添加到数组的开头
                $time_str = '[运行时间：' . number_format($runtime, 6) . 's] [吞吐率：' . $reqs . 'req/s]';
                $memory_str = ' [内存消耗：' . $memory_use . 'kb]';
                $file_load = ' [文件加载：' . $file_count . ']';
                array_unshift($info, $time_str . $memory_str . $file_load);
            }
        }
    }
}
