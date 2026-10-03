<?php
/**
 * morn-perf-sentry 使用示例。
 *
 * 运行方式（CLI）：
 *   php examples/audit.php
 *
 * 对 fixtures/sample.html 做完整审计 → 评分 → 生成优化建议。
 * 全部为静态分析，不发起任何网络请求，因此可在 CI 中离线运行。
 */

declare(strict_types=1);

require __DIR__ . '/../src/Resource.php';
require __DIR__ . '/../src/ResourceAuditor.php';
require __DIR__ . '/../src/ScoreCalculator.php';
require __DIR__ . '/../src/LazyLoadHint.php';
require __DIR__ . '/../src/CachePolicySuggestor.php';

use MornRain\PerfSentry\CachePolicySuggestor;
use MornRain\PerfSentry\LazyLoadHint;
use MornRain\PerfSentry\Resource;
use MornRain\PerfSentry\ResourceAuditor;
use MornRain\PerfSentry\ScoreCalculator;

function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

$html = file_get_contents(__DIR__ . '/../fixtures/sample.html');
if ($html === false) {
    fwrite(STDERR, "无法读取 fixtures/sample.html" . PHP_EOL);
    exit(1);
}

echo '页面 HTML 体积: ' . Resource::formatBytes(strlen($html)) . PHP_EOL;
echo '站点主机: example.com' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('1. 资源审计');

$auditor = new ResourceAuditor('example.com');
$summary = $auditor->summarize($html);

printf('资源总数: %d 个，合计 %s' . PHP_EOL, $summary['total_count'], $summary['total_bytes_human']);
printf('其中渲染阻塞: %d 个，合计 %s' . PHP_EOL, $summary['blocking_count'], Resource::formatBytes($summary['blocking_bytes']));
printf('内联样式/脚本: %d 个' . PHP_EOL, count(array_filter($summary['resources'], static function (array $r): bool {
    return ($r['url'] === '' || $r['url'] === null) && $r['tag'] !== 'link';
})));

echo PHP_EOL . '按类型统计：' . PHP_EOL;
foreach ($summary['by_type'] as $type => $stat) {
    if ($stat['count'] === 0) {
        continue;
    }
    printf(
        '  %-11s %2d 个  %9s' . PHP_EOL,
        $type,
        $stat['count'],
        Resource::formatBytes($stat['bytes'])
    );
}

echo PHP_EOL . '资源清单：' . PHP_EOL;
printf("  %-3s %-11s %-6s %-9s %-8s %s" . PHP_EOL, '#', '类型', '位置', '体积', '阻塞', 'URL');
foreach ($summary['resources'] as $i => $item) {
    $url = $item['url'] !== '' ? $item['url'] : '(内联 ' . $item['tag'] . ')';
    printf(
        "  %-3d %-11s %-6s %-9s %-8s %s\n",
        $i + 1,
        $item['type'],
        $item['in_head'] ? 'head' : 'body',
        $item['size_human'],
        $item['blocking'] ? '是' : '否',
        $url
    );
}

/* ------------------------------------------------------------------ */
section('2. 渲染阻塞资源');

foreach ($summary['blocking_urls'] as $url) {
    echo '  - ' . $url . PHP_EOL;
}

/* ------------------------------------------------------------------ */
section('3. 逐资源问题明细');

$issueTotal = 0;
foreach ($summary['resources'] as $item) {
    if (empty($item['issues'])) {
        continue;
    }
    $label = $item['url'] !== '' ? $item['url'] : '(内联 ' . $item['tag'] . ')';
    echo '[' . $item['type'] . '] ' . $label . PHP_EOL;
    foreach ($item['issues'] as $issue) {
        echo '    · (' . $issue['code'] . ') ' . $issue['message'] . PHP_EOL;
        $issueTotal++;
    }
}
echo PHP_EOL . '问题总数: ' . $issueTotal . ' 条' . PHP_EOL;

/* ------------------------------------------------------------------ */
section('4. 性能评分');

$calculator = new ScoreCalculator();
$result     = $calculator->score($summary);

echo '得分: ' . $result['score'] . ' / 100' . PHP_EOL;
echo '评级: ' . $result['grade'] . PHP_EOL;
echo '总扣分: ' . $result['total_penalty'] . ' 分' . PHP_EOL;
echo PHP_EOL . '结论: ' . $result['summary'] . PHP_EOL;

echo PHP_EOL . '扣分明细（按维度）:' . PHP_EOL;
foreach ($result['deductions'] as $d) {
    printf(
        '  %-20s -%-5.1f / %-3d  %s' . PHP_EOL,
        $d['label'],
        $d['penalty'],
        $d['max'],
        $d['reason']
    );
    foreach ($d['examples'] as $example) {
        echo '        → ' . $example . PHP_EOL;
    }
}

/* ------------------------------------------------------------------ */
section('5. 自定义权重与阈值');

$custom = new ScoreCalculator(
    ['image_dimensions' => 30, 'render_blocking' => 10],   // 更看重 CLS 与首屏
    ['lazy_rate_target' => 0.99]                            // 严格要求懒加载
);
$customResult = $custom->score($summary);
printf('默认权重得分: %d（%s）' . PHP_EOL, $result['score'], $result['grade']);
printf('自定义权重得分: %d（%s）' . PHP_EOL, $customResult['score'], $customResult['grade']);
echo '权重表: ' . json_encode($custom->weights(), JSON_UNESCAPED_UNICODE) . PHP_EOL;

/* ------------------------------------------------------------------ */
section('6. 对比：优化后的理想页面');

$optimized = <<<'HTML'
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>优化后页面</title>
    <link rel="preload" href="/fonts/inter-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/app.a1b2c3d4.css?ver=a1b2c3d4">
    <link rel="stylesheet" href="/print.9f8e7d6c.css?ver=9f8e7d6c">
    <script src="/app.7c6b5a49.js?ver=7c6b5a49" defer></script>
</head>
<body>
    <img src="/hero.webp" alt="主图" width="1200" height="675" fetchpriority="high">
    <img src="/p1.webp" alt="图一" width="640" height="360" loading="lazy" decoding="async">
</body>
</html>
HTML;

$optimizedAuditor = new ResourceAuditor('example.com');
$optimizedResult  = $calculator->scoreHtml($optimized, $optimizedAuditor);

printf('优化前: %d 分（%s）' . PHP_EOL, $result['score'], $result['grade']);
printf('优化后: %d 分（%s）' . PHP_EOL, $optimizedResult['score'], $optimizedResult['grade']);
printf('提升: +%d 分' . PHP_EOL, $optimizedResult['score'] - $result['score']);
echo '优化后结论: ' . $optimizedResult['summary'] . PHP_EOL;

/* ------------------------------------------------------------------ */
section('7. 懒加载与优先级建议');

$hint    = new LazyLoadHint('example.com');
$suggest = $hint->suggest($html);

printf('共 %d 条建议，其中 %d 条可直接应用' . PHP_EOL, count($suggest['suggestions']), $suggest['count']);

foreach ($suggest['applicable'] as $item) {
    echo PHP_EOL . '[' . $item['kind'] . '] '
        . ($item['url'] !== '' ? $item['url'] : '(内联)') . PHP_EOL;
    echo '    理由: ' . $item['reason'] . PHP_EOL;
    if (!empty($item['dimension_hint'])) {
        echo '    尺寸: ' . $item['dimension_hint'] . PHP_EOL;
    }
    if (!empty($item['snippet'])) {
        echo '    建议: ' . $item['snippet'] . PHP_EOL;
    }
}

/* ------------------------------------------------------------------ */
section('8. 缓存策略建议');

$cache     = new CachePolicySuggestor('example.com');
$cachePlan = $cache->suggest($html);

printf('为 %d 个外链资源生成了缓存建议' . PHP_EOL, $cachePlan['count']);

echo PHP_EOL . 'HTML 文档：' . PHP_EOL;
foreach ($cachePlan['html_rule']['headers'] as $name => $value) {
    echo '    ' . $name . ': ' . $value . PHP_EOL;
}
echo '    原因: ' . $cachePlan['html_rule']['reason'] . PHP_EOL;

echo PHP_EOL . '抽样 5 个资源：' . PHP_EOL;
foreach (array_slice($cachePlan['items'], 0, 5) as $item) {
    echo '  · ' . $item['url'] . PHP_EOL;
    echo '      类型: ' . $item['type']
        . '，指纹: ' . ($item['fingerprint'] ? '有' : '无')
        . '，三方: ' . ($item['third_party'] ? '是' : '否')
        . '，max-age: ' . $item['max_age_human'] . PHP_EOL;
    echo '      Cache-Control: ' . $item['headers']['Cache-Control'] . PHP_EOL;
    foreach ($item['actions'] as $action) {
        echo '      → ' . $action . PHP_EOL;
    }
}

echo PHP_EOL . 'Nginx 配置片段示例（指纹 CSS）：' . PHP_EOL;
foreach ($cachePlan['items'] as $item) {
    if ($item['type'] === Resource::TYPE_STYLESHEET && $item['fingerprint']) {
        echo $item['server_config'] . PHP_EOL;
        break;
    }
}

echo PHP_EOL . '通用注意事项：' . PHP_EOL;
foreach ($cachePlan['notes'] as $note) {
    echo '  - ' . $note . PHP_EOL;
}

/* ------------------------------------------------------------------ */
section('9. 体积估算：注入真实 Content-Length');

$withRealSizes = (new ResourceAuditor('example.com'))->withSizeMap([
    '/wp-content/themes/demo/style.css'     => 312000,   // 未压缩的 3 个大样式表
    '/wp-content/plugins/banner/style.css' => 268000,
    '/wp-content/themes/demo/js/legacy-widget.js'    => 540000,
    '/wp-content/themes/demo/js/analytics-sync.js'   => 410000,
    '/wp-content/uploads/2026/10/hero-banner-full.jpg' => 1850000,
]);
$realSummary = $withRealSizes->summarize($html);
$realResult  = $calculator->score($realSummary);

printf('估算模式: %d 分，总体 %s' . PHP_EOL, $result['score'], $summary['total_bytes_human']);
printf('真实体积: %d 分，总体 %s' . PHP_EOL, $realResult['score'], $realSummary['total_bytes_human']);
echo '（体积越大，未压缩类问题扣分越高）' . PHP_EOL;
echo PHP_EOL;
foreach ($realResult['deductions'] as $d) {
    if ($d['dimension'] === 'payload_size' || $d['dimension'] === 'render_blocking') {
        echo '  ' . $d['label'] . ': -' . $d['penalty'] . ' → ' . $d['reason'] . PHP_EOL;
    }
}

echo PHP_EOL . 'PerfSentry 示例运行结束。' . PHP_EOL;
