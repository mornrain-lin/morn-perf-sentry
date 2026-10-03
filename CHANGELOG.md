# Changelog

本项目遵循 [语义化版本 2.0.0](https://semver.org/lang/zh-CN/)。

## [1.0.1] - 2026-10-03

### Fixed

- **相对路径资源被误判为第三方**：`Resource::isThirdParty()` 在 `parse_url()`
  拿不到 host 时返回 `true`，导致 `/wp-content/a.css` 这类站内相对路径
  被算作站外资源，虚增第三方占比并给出错误的「自托管」建议。
  现改为：无 host 的相对路径与 `data:` 一律视为站内，
  同时补上协议相对地址（`//cdn.example.com/a.js`）的 host 解析。
- **iframe 永远拿不到尺寸建议**：`hasDimensions()` 原本只对图片生效，
  对 iframe 直接返回 `true`，使 `forIframe()` 中的 `width` / `height` 建议永不触发。
  现把 iframe 纳入判定范围。
- **`cache_policy` 维度取错字段**：扣分明细里错用了 `uncompressed_urls`，
  导致「缺版本标识」这一维度附上了「未压缩」的资源列表。现改用 `no_cache_urls`，
  并在摘要中新增该字段。
- **HTML 建议输出了假的 ETag**：`forHtml()` 返回 `md5('html-placeholder')`
  作为 ETag 占位值。该值若被直接复制进配置，会让所有页面共用同一个 ETag、
  缓存彻底失效。现移除该字段，改为在建议中说明 ETag 的正确计算方式。
- `weakEtag()` 改用 `sha256` 替代 `md5`（ETag 属于缓存正确性相关标识）。
- `LazyLoadHint::renderTag()` 中 `$tag === 'img' ? 'src' : ($tag === 'script' ? 'src' : 'src')`
  三个分支结果相同，已简化。

### Added

- `tests/`：57 个用例 / 146 条断言，覆盖资源类型识别、体积估算与注入、
  阻塞判定、评分边界、缓存策略建议与懒加载建议（含片段转义）。
- `tests/run-tests.php`：零依赖测试运行器。
- `phpunit.xml.dist`、`phpcs.xml.dist`（PSR-12）、`CONTRIBUTING.md`、`SECURITY.md`。

## [1.0.0] - 2026-10-02

### 新增

- `Resource`：资源数据模型
  - 7 种资源类型常量（stylesheet / script / image / font / iframe / media / other）
  - 派生判断：`isRenderBlocking()` `isDeferred()` `isLazyLoaded()`
    `hasFetchPriority()` `hasDimensions()` `isInline()` `isThirdParty()`
  - 问题标记累积 `addIssue()` / `issues()` / `hasIssue()`
  - `formatBytes()` 体积格式化
- `ResourceAuditor`：HTML 静态资源审计
  - 提取 link / script / img / source / iframe / video / audio 资源
  - link 按 `rel` + `as` 分派类型（stylesheet / font / script / other），不重复统计
  - 定位 head 边界，判断资源是否位于首屏关键区域
  - 单独统计内联 `<style>` 与内联 `<script>` 体积
  - 体积估算三级查找：完整 URL → 去 query 路径 → 文件名 → 同类型默认值
  - 6 类问题标注：uncompressed / no-cache / not-lazy / no-dimensions /
    font-display（含 font-preload-cors）/ no-fetchpriority
  - `audit()` 返回资源对象，`summarize()` / `summarizeResources()` 返回统计摘要
- `ScoreCalculator`：8 维度性能评分
  - 维度与权重：render_blocking(22) render_blocking_css(12)
    image_dimensions(15) image_lazyload(12) font_loading(10)
    cache_policy(9) payload_size(12) third_party(8)
  - 每个维度独立封顶扣分，返回 0~100 整数分与 A~E 评级
  - 扣分明细含维度、中文标签、扣分值、权重上限、原因与示例 URL
  - 权重与阈值均可通过构造函数覆盖
  - `scoreHtml()` 审计 + 评分一步到位
- `LazyLoadHint`：加载策略建议
  - 判定首屏主图（LCP 候选）→ 建议 fetchpriority="high" 且不加 lazy
  - 首屏之外 → 建议 loading="lazy" + decoding="async"
  - iframe → 建议 loading="lazy"，缺尺寸时补默认宽高
  - head 内同步脚本 → 建议 defer
  - 输出可直接比对的 HTML 标签片段
- `CachePolicySuggestor`：缓存策略建议
  - 区分指纹资源（1 年 immutable）与非指纹资源（1 周 ~ 1 月 must-revalidate）
  - HTML 文档单独给出 no-cache + ETag 规则
  - 第三方资源降级为 1 天并提示自托管
  - 输出 HTTP 响应头、Nginx location 片段与 6 条通用注意事项
- `fixtures/sample.html`：含 13 处典型性能问题的测试样例
- `examples/audit.php`：9 个场景的可运行示例，含优化前后评分对比

[1.0.0]: https://github.com/MornRain/morn-perf-sentry/releases/tag/v1.0.0
