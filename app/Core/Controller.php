<?php
/**
 * Online-Mirror v4.0 - 基础控制器
 */
namespace App\Core;

class Controller
{
    /**
     * 渲染视图
     */
    protected function view($view, $data = [])
    {
        extract($data);
        $viewPath = __DIR__ . '/../Views/' . str_replace('.', '/', $view) . '.php';
        if (file_exists($viewPath)) {
            require $viewPath;
        }
    }

    /**
     * 返回 JSON
     */
    protected function json($data, $code = 200)
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * 重定向
     */
    protected function redirect($url)
    {
        header('Location: ' . $url);
        exit;
    }
}
