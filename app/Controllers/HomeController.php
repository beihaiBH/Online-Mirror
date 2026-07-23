<?php
/**
 * Online-Mirror v4.0 - 首页控制器
 */
namespace App\Controllers;

use App\Core\Controller;

class HomeController extends Controller
{
    public function index()
    {
        // 直接加载原有 index.php
        require __DIR__ . '/../../index.php';
    }
}
