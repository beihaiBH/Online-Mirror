<?php
/**
 * Online-Mirror v4.0 - MVC 路由核心
 * 支持路由注册、参数匹配、命名路由生成
 */
namespace App\Core;

class Router
{
    protected static $routes = [];
    protected static $currentRoute = null;

    /**
     * 注册 GET 路由
     */
    public static function get($uri, $handler)
    {
        self::$routes['GET'][self::normalize($uri)] = $handler;
    }

    /**
     * 注册 POST 路由
     */
    public static function post($uri, $handler)
    {
        self::$routes['POST'][self::normalize($uri)] = $handler;
    }

    /**
     * 注册 GET|POST 路由
     */
    public static function any($uri, $handler)
    {
        self::get($uri, $handler);
        self::post($uri, $handler);
    }

    /**
     * 标准化 URI
     */
    protected static function normalize($uri)
    {
        return '/' . trim($uri, '/');
    }

    /**
     * 将路由模式编译为正则（支持 {param} 占位符）
     */
    protected static function compile($pattern)
    {
        $regex = preg_replace('/\{(\w+)\}/', '(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }

    /**
     * 分发请求到对应路由
     */
    public static function dispatch($method, $uri)
    {
        $method = strtoupper($method);
        $uri = self::normalize(parse_url($uri, PHP_URL_PATH));
        
        // 精确匹配
        if (isset(self::$routes[$method][$uri])) {
            self::$currentRoute = $uri;
            return self::call(self::$routes[$method][$uri]);
        }

        // 参数匹配
        foreach (self::$routes[$method] ?? [] as $pattern => $handler) {
            if (preg_match(self::compile($pattern), $uri, $matches)) {
                self::$currentRoute = $pattern;
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                return self::call($handler, $params);
            }
        }

        // 404
        header('HTTP/1.1 404 Not Found');
        die('<h2 style="text-align:center;margin-top:80px;color:#8080a0;">404 - 页面不存在</h2>');
    }

    /**
     * 调用路由处理函数
     */
    protected static function call($handler, $params = [])
    {
        if (is_string($handler)) {
            // "Controller@method" 格式
            if (strpos($handler, '@') !== false) {
                list($class, $method) = explode('@', $handler);
                $class = '\\App\\Controllers\\' . $class;
                $controller = new $class();
                return call_user_func_array([$controller, $method], $params);
            }
            // 直接函数名
            return call_user_func($handler, $params);
        }
        if (is_callable($handler)) {
            return call_user_func($handler, $params);
        }
    }

    /**
     * 获取当前路由名
     */
    public static function current()
    {
        return self::$currentRoute;
    }
}
