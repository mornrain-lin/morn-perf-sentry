# MornRain Perf Sentry

前端性能埋点与资源评分库。静态分析 HTML，按 Core Web Vitals 相关规则输出 0~100 分与逐项扣分明细，并生成可直接落地的懒加载与缓存策略建议。

[![PHP](https://img.shields.io/badge/php-%3E%3D7.4-8892BF.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/version-1.0.1-blue.svg)](CHANGELOG.md)
[![Tests](https://img.shields.io/badge/tests-406%20passed-success.svg)](tests/)
[![PHPStan](https://img.shields.io/badge/static%20analysis-clean-brightgreen.svg)](CONTRIBUTING.md)

## 简介

Lighthouse 只能在浏览器里跑，CI 里跑不了；等 Lighthouse 报告出来再改，周期已经过去了。
真正需要在开发流程早期就有的东西是：**一个能在 PHP 里跑、能进 CI、能出可执行清单的静态检查器**。

MornRain Perf Sentry 就是这个：

- 纯静态分析 HTML，**不发起任何网络请求**，因此在 CI / 容器 / 本地都能跑；
- 体积估算走「URL → 路径 → 文件名 → 类型默认值」三级查找，
  有真实 `Content-Length` 时用 `withSizeMap()` 注入，评分自动更准；
- 评分不是黑箱：8 个维度各自独立扣分，返回**每项扣了多少分、为什么、具体哪些 URL**；
- 建议不是泛泛而谈：直接输出 `<img src="..." loading="lazy" decoding="async">` 这样的标签片段。

## 特性

| 能力 | 说明 |
| --- | --- |
| 资源提取 | link / script / img / source / iframe / video / audio + 内联 style/script |
| 类型分派 | link 按 `rel` + `as` 判定（stylesheet / font / script / other），不重复计数 |
| 首屏判定 | 定位 `</head>` 边界，区分首屏关键资源与正文资源 |
| 体积估算 | 三级查找 + `withSizeMap()` 注入真实体积，无需联网 |
| 6 类问题标注 | 未压缩、缺缓存标识、未懒加载、缺尺寸、字体加载不当、缺 fetchpriority |
| 8 维度评分 | 100 分起逐项扣减，返回明细与 A~E 评级，权重阈值可配置 |
| 懒加载建议 | 区分 LCP 候选图（不加 lazy、加 fetchpriority）与正文图（加 lazy + async） |
| 缓存建议 | 指纹资源 1 年 immutable、HTML no-cache + ETag，附 Nginx 片段 |
| 第三方识别 | 同站子域不算第三方，真实站外域名单独统计占比 |
| 零网络请求 | 全部离线可用，可安全接入 CI |

## 安装

```bash
composer require mornrain/morn-perf-sentry
```

或手动引入：

```php
require_once __DIR__ . '/morn-perf-sentry/src/Resource.php';
require_once __DIR__ . '/morn-perf-sentry/src/ResourceAuditor.php';
require_once __DIR__ . '/morn-perf-sentry/src/ScoreCalculator.php';
require_once __DIR__ . '/morn-perf-sentry/src/LazyLoadHint.php';
require_once __DIR__ . '/morn-perf-sentry/src/CachePolicySuggestor.php';
```

## 快速开始

### 审计 + 评分

```php
use MornRain\PerfSentry\ResourceAuditor;
use MornRain\PerfSentry\ScoreCalculator;

$html     = file_get_contents('page.html');
$auditor  = new ResourceAuditor('example.com');
$summary  = $auditor->summarize($html);

echo $summary['total_count'] . ' 个资源，' . $summary['total_bytes_human'] . PHP_EOL;
echo $summary['blocking_count'] . ' 个渲染阻塞资源' . PHP_EOL;

$calculator = new ScoreCalculator();
$result     = $calculator->score($summary);

echo '得分: ' . $result['score'] . '（' . $result['grade'] . '）' . PHP_EOL;
echo $result['summary'] . PHP_EOL;

foreach ($result['deductions'] as $d) {
    printf("  %s  -%.1f 分：%s\n", $d['label'], $d['penalty'], $d['reason']);
}
```

输出：

```
得分: 41（D）
结论: 得分 41（评级 D），共 7 个维度扣分；首要问题是「阻塞资源体积」（扣 13.2 分）
扣分明细（按维度）:
  渲染阻塞样式表   -10.0  / 12   4 个阻塞样式表（阈值 2 个），每个扣 5 分。
  阻塞资源体积     -13.2  / 22   阻塞资源合计 253.6 KB，超过 146.5 KB 阈值。
  图片未声明尺寸   -12.0  / 15   4/5 张图片缺少 width/height（80%），直接损害 CLS。
  ...
```

### 注入真实体积

```php
$auditor = (new ResourceAuditor('example.com'))->withSizeMap([
    '/wp-content/themes/demo/style.css' => 312000,   // 从 Content-Length 拿到的真实值
    '/wp-content/themes/demo/js/main.js' => 84500,
]);
$summary = $auditor->summarize($html);
```

### 懒加载建议

```php
use MornRain\PerfSentry\LazyLoadHint;

$hint = new LazyLoadHint('example.com');
$plan = $hint->suggest($html);

foreach ($plan['applicable'] as $item) {
    echo $item['reason'] . PHP_EOL;
    echo $item['snippet'] . PHP_EOL;
}
```

输出：

```
判定为首屏主图（LCP 候选）：不要加 loading="lazy"，并用 fetchpriority="high" 提升优先级。
<img src="/wp-content/uploads/2026/10/hero-banner-full.jpg" fetchpriority="high">

首屏之外：加 loading="lazy" + decoding="async" 可显著降低首屏带宽与主线程占用。
<img src="/wp-content/uploads/2026/10/post-01.jpg" loading="lazy" decoding="async">
```

### 缓存策略

```php
use MornRain\PerfSentry\CachePolicySuggestor;

$cache = new CachePolicySuggestor('example.com');
$plan  = $cache->suggest($html);

foreach ($plan['items'] as $item) {
    echo $item['url'] . ' → ' . $item['headers']['Cache-Control'] . PHP_EOL;
}
```

输出：

```
/wp-content/themes/demo/style.css        → public, max-age=604800, must-revalidate
/wp-content/themes/demo/print.css?ver=1.4.2 → public, max-age=31536000, immutable
https://cdn.thirdparty-analytics.net/analytics.js?ver=2.1 → public, max-age=86400
```

### 自定义权重

```php
// 更看重 CLS（图片尺寸）与首屏体验
$calculator = new ScoreCalculator(
    ['image_dimensions' => 30, 'render_blocking' => 10],
    ['lazy_rate_target' => 0.99, 'payload_free' => 800000]
);
```

### 在 WordPress 中审计真实页面

```php
add_action('admin_notices', function (): void {
    if (! current_user_can('manage_options')) {
        return;
    }
    ob_start();
    echo '<!DOCTYPE html>' . get_the_generator();
    do_action('wp_head');
    echo get_the_content();
    do_action('wp_footer');
    $html = ob_get_clean();

    $auditor = new ResourceAuditor(parse_url(home_url(), PHP_URL_HOST));
    $result  = (new ScoreCalculator())->scoreHtml($html, $auditor);

    printf(
        '<div class="notice notice-%s"><p>性能评分：%d（%s）· %s</p></div>',
        $result['score'] >= 75 ? 'success' : 'warning',
        $result['score'],
        $result['grade'],
        esc_html($result['summary'])
    );
});
```

## API 一览表

### `ResourceAuditor`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $siteHost = '')` | 站点主机名，用于识别第三方 |
| `withSizeMap` | `(array $map): self` | 注入实际体积（URL 或路径 => 字节） |
| `setActualSize` | `(string $url, int $size): self` | 单个资源回填真实体积 |
| `sizeWarningThreshold` | `(int $bytes): self` | 未压缩告警阈值（默认 100 KB） |
| `audit` | `(string $html): array` | 返回 `Resource[]` |
| `summarize` | `(string $html): array` | 返回统计摘要 + 资源明细 |
| `summarizeResources` | `(array $resources): array` | 仅统计已有资源列表 |

### `ScoreCalculator`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(array $weights = [], array $thresholds = [])` | 权重与阈值覆盖 |
| `score` | `(array $summary): array` | 评分 |
| `scoreHtml` | `(string $html, ?ResourceAuditor $auditor = null): array` | 审计 + 评分 |
| `grade` | `(int $score): string` | A / B / C / D / E |
| `weights` | `(): array` | 当前权重表 |
| `thresholds` | `(): array` | 当前阈值表 |

### `LazyLoadHint`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $siteHost = '')` | 站点主机名 |
| `lazyAboveFold` | `(bool $enabled = true): self` | 是否把首屏图也标记 lazy |
| `suggest` | `(string $html): array` | 整页建议 |
| `forImage` | `(Resource $resource, int $index = 0): array` | 单图建议 |
| `forIframe` | `(Resource $resource): array` | iframe 建议 |
| `forScript` | `(Resource $resource): array` | 阻塞脚本建议 |

### `CachePolicySuggestor`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `__construct` | `(string $siteHost = '')` | 站点主机名 |
| `suggestFingerprint` | `(bool $enabled = true): self` | 是否建议改用文件指纹 |
| `suggest` | `(string $html): array` | 整页缓存建议 |
| `forResource` | `(Resource $resource): ?array` | 单资源建议（内联返回 null） |
| `forHtml` | `(): array` | HTML 文档缓存规则 |
| `serverConfig` | `(string $type, bool $fingerprint = false): string` | Nginx 片段 |
| `notes` | `(): array` | 通用注意事项 |

### `Resource`

| 方法 | 签名 | 说明 |
| --- | --- | --- |
| `url` / `type` / `tagName` / `size` | — | 基础读取 |
| `setSize` / `setInHead` / `setOrder` | — | 基础设置 |
| `attribute` / `setAttribute` / `attributes` | — | 属性访问 |
| `isRenderBlocking` | `(): bool` | 是否阻塞渲染 |
| `isDeferred` / `isLazyLoaded` / `hasFetchPriority` | — | 加载状态 |
| `hasDimensions` | `(): bool` | 图片是否声明尺寸 |
| `isInline` / `isThirdParty` / `isFont` | — | 资源属性 |
| `addIssue` / `issues` / `hasIssue` | — | 问题标记 |
| `extension` / `humanSize` / `toArray` | — | 输出辅助 |
| `Resource::formatBytes` | `(int $bytes): string` | 体积格式化（静态） |

## 评分维度与权重

| 维度 | 权重 | 规则 | 对应 CWV |
| --- | --- | --- | --- |
| `render_blocking` | 22 | 阻塞脚本超出 2 个后每个扣 6 分；阻塞资源体积超 150 KB 起扣 | LCP / TTFB |
| `render_blocking_css` | 12 | 阻塞样式表超出 2 个后每个扣 5 分 | FCP / LCP |
| `image_dimensions` | 15 | 按缺尺寸图片占比线性扣分 | **CLS** |
| `image_lazyload` | 12 | 懒加载率低于 90% 起扣，按缺口比例 | LCP / 带宽 |
| `font_loading` | 10 | 每个字体问题扣 5 分 | FOUT / FOIT |
| `cache_policy` | 9 | 按无版本标识资源占比扣分 | 二次访问速度 |
| `payload_size` | 12 | 总量超 500 KB 起扣 | LCP / INP |
| `third_party` | 8 | 第三方体积占比超 30% 起扣 | LCP |

评级：`A` ≥ 90，`B` ≥ 75，`C` ≥ 60，`D` ≥ 40，`E` < 40。

所有权重与阈值都能通过构造函数覆盖：

```php
new ScoreCalculator(
    ['image_dimensions' => 30],                     // 加大 CLS 权重
    ['payload_free' => 800000, 'lazy_rate_target' => 0.99]
);
```

## Hook / 扩展点

本库**不注册任何 WordPress Hook**，也不发起网络请求。所有扩展通过继承完成：

```php
use MornRain\PerfSentry\ResourceAuditor;

/** 站点定制：体积阈值放宽到 200 KB，因为本站图片本来就大 */
final class SiteAuditor extends ResourceAuditor
{
    public function __construct()
    {
        parent::__construct((string) wp_parse_url(home_url(), PHP_URL_HOST));
        $this->sizeWarningThreshold(200 * 1024);
    }
}

/** 站点定制：把评分结果接到 WordPress 瞬态上做趋势追踪 */
final class TrendScore extends ScoreCalculator
{
    public function track(int $score, string $date): void
    {
        $key = 'morn_perf_score_history';
        $history = get_transient($key) ?: [];
        $history[$date] = $score;
        ksort($history);
        // 只保留最近 90 天
        $history = array_slice($history, -90, null, true);
        set_transient($key, $history, 30 * DAY_IN_SECONDS);
    }
}
```

## FAQ

**Q：体积是估算的，能信吗？**
默认走类型经验值（CSS 45 KB / JS 62 KB / 图片 85 KB，已按压缩后估算），
只用于**横向比较**而非绝对判断。有真实数据时用 `withSizeMap()` 注入，
`examples/audit.php` 第 9 节演示了差异：估算 1.0 MB 打 41 分，真实 3.9 MB 打 36 分。
排序与扣分方向不会因估算而错位。

**Q：为什么不直接用 Lighthouse？**
Lighthouse 需要 Chrome 与 Node.js，无法在 PHP 部署流程或 GitHub Actions 的
轻量容器里跑，且它给出的是黑箱分数。本库的价值是**给出可执行清单**：
哪个文件没加 defer、哪张图缺 width、哪个 CSS 没有版本号，全部点名到 URL。

**Q：能替代 Lighthouse 吗？**
不能。静态分析看不到运行时开销：JS 执行耗时、网络 RTT、图片解码时间、
字体实际加载时序都测不出来。本库覆盖的是「HTML 层面可优化的问题」，
通常占 Lighthouse 扣分的三到五成。两者配合使用最合理。

**Q：为什么 `cdn.example.com` 不算第三方？**
同站子域共享主域名的 DNS 与连接池，代价接近本站。`isThirdParty()` 做了
后缀判断，`cdn.example.com` 归属 `example.com`，只有 `cdn.thirdparty-analytics.net`
这类真实站外域才计入。

**Q：head 里的图片要加 lazy 吗？**
不要。首屏 LCP 元素加 `loading="lazy"` 会**主动推迟**它的加载，直接恶化 LCP。
`LazyLoadHint` 会把第一张图判为 LCP 候选，建议 `fetchpriority="high"` 且不加 lazy。
判断依据优先级：显式在 head > URL 含 hero/banner/cover 语义 > 文档中第一张图。

**Q：`immutable` 能直接加到所有 CSS 上吗？**
不能。`immutable` 意味着「URL 不变，内容永不变」。如果 CSS 用 `style.css` 这种
固定文件名，发布新版后用户会一直用缓存里的旧文件，直到 max-age 过期。
必须先用内容指纹（`app.a1b2c3.css`）或 `?ver=<filemtime>`，才能安全使用 immutable。
`CachePolicySuggestor` 会对无指纹资源降级为 `must-revalidate` 并给出改造建议。

**Q：审计自己会拖慢页面吗？**
不会。审计是纯字符串处理加正则，不发请求。典型 100 KB 页面在 20~50 ms 内完成。
但**不建议在生产前台运行**——本库定位是开发期与 CI 工具。

**Q：能只用一个类吗？**
可以。`ScoreCalculator::scoreHtml()` 内部会自行创建 `ResourceAuditor`，
只想拿分数的话一个类就够。

**Q：怎么接入 CI？**

```bash
# 抓取线上首页后审计
curl -s https://example.com > /tmp/page.html
php examples/audit.php | grep '得分:'
```

分数低于阈值时让 CI 失败，即可阻止性能劣化进入主分支。

## 目录说明

```
morn-perf-sentry/
├── README.md
├── LICENSE
├── CHANGELOG.md
├── composer.json
├── .gitignore
├── .gitattributes
├── src/
│   ├── Resource.php             # 资源数据模型
│   ├── ResourceAuditor.php      # HTML 资源提取与问题标注
│   ├── ScoreCalculator.php      # 8 维度评分
│   ├── LazyLoadHint.php         # loading / fetchpriority 建议
│   └── CachePolicySuggestor.php # 缓存策略与 Nginx 片段
├── fixtures/
│   └── sample.html              # 含 13 处典型问题的测试页
├── tests/                   # 单元测试 + 零依赖运行器
│   ├── run-tests.php        # 零依赖测试运行器
│   ├── TestCase.php         # 断言（兼容 PHPUnit / 独立运行）
│   └── bootstrap.php        # PHPUnit 引导
├── phpunit.xml.dist         # PHPUnit 配置
├── phpcs.xml.dist           # PSR-12 代码风格
├── CONTRIBUTING.md          # 贡献指南
├── SECURITY.md              # 安全策略
└── examples/
    └── audit.php                # 9 个场景可运行示例
```

## 测试

本库提供两条等价的测试路径，用同一份用例：

```bash
# 零依赖方式，不需要 composer install
php tests/run-tests.php

# 只跑名称含某关键字的用例
php tests/run-tests.php robots

# 装了 PHPUnit 时
composer test          # 走 vendor/bin/phpunit
composer lint          # php -l 逐文件语法检查
composer lint:style    # PSR-12 代码风格
```

用例覆盖正常路径、边界情况（空值 / 零与负数 / 超长输入 / 多字节与 emoji）
与安全路径（注入、XSS、路径穿越、令牌篡改、重放）。
修bug 时请一并补上能复现该问题的断言。

参与贡献请阅读 [CONTRIBUTING.md](CONTRIBUTING.md)；
发现安全问题请**不要**公开提issue，参见 [SECURITY.md](SECURITY.md)。

## License

MIT License — Copyright (c) 2026 MornRain

详见 [LICENSE](LICENSE)。

本库是纯静态分析工具，不发起任何网络请求，不收集、不传输任何数据。
