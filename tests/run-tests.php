<?php
/**
 * 零依赖测试运行器。
 *
 * 用法：
 *   php tests/run-tests.php              跑全部用例
 *   php tests/run-tests.php robots       只跑方法名含 robots 的用例
 *
 * 装了 PHPUnit 的项目也可以用 `vendor/bin/phpunit` 跑同一份用例，
 * 两种方式的断言语义一致。
 *
 * 退出码：0 = 全部通过，1 = 存在失败。
 *
 * @package MornRain\PerfSentry\Tests
 */

declare(strict_types=1);

require_once __DIR__ . '/TestCase.php';

use MornRain\PerfSentry\Tests\Runner;

$filter = $argv[1] ?? '';
$runner = new Runner(__DIR__, dirname(__DIR__), 'MornRain\PerfSentry\\Tests');

exit($runner->run($filter));
