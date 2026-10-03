<?php
/**
 * 性能评分计算器。
 *
 * 基于 Core Web Vitals 的三项核心指标（LCP / CLS / INP）背后的常见成因，
 * 设计 8 个扣分维度，从 100 分起逐项扣减，返回 0~100 的整数分与完整扣分明细。
 *
 * 维度与权重：
 *   1. render_blocking     首屏阻塞资源数与体积   权重 22
 *   2. render_blocking_css 渲染阻塞样式表数       权重 12
 *   3. image_dimensions    图片缺尺寸（CLS）      权重 15
 *   4. image_lazyload      图片懒加载覆盖率       权重 12
 *   5. font_loading        字体加载方式（FOIT）   权重 10
 *   6. cache_policy        静态资源缓存标识       权重 9
 *   7. payload_size        总传输体积             权重 12
 *   8. third_party         第三方资源占比         权重 8
 *
 * 评分特点：
 * - 每个维度独立扣分并封顶，不会出现负分；
 * - 返回 deduction 明细，便于直接生成优化清单；
 * - 阈值可在构造时覆盖，适配不同站点规模。
 *
 * @package MornRain\PerfSentry
 */

declare(strict_types=1);

namespace MornRain\PerfSentry;

use InvalidArgumentException;

/**
 * 评分计算器。
 */
class ScoreCalculator
{
    /** 默认权重表 */
    public const DEFAULT_WEIGHTS = [
        'render_blocking'     => 22,
        'render_blocking_css' => 12,
        'image_dimensions'    => 15,
        'image_lazyload'      => 12,
        'font_loading'        => 10,
        'cache_policy'        => 9,
        'payload_size'        => 12,
        'third_party'         => 8,
    ];

    /** 评级门槛 */
    public const GRADE_EXCELLENT = 90;
    public const GRADE_GOOD      = 75;
    public const GRADE_FAIR      = 60;
    public const GRADE_POOR      = 0;

    /** @var array<string,int> 权重表 */
    protected $weights;

    /** @var array<string,mixed> 阈值配置 */
    protected $thresholds;

    /**
     * 构造函数。
     *
     * @param array<string,int> $weights    权重覆盖。
     * @param array<string,mixed> $thresholds 阈值覆盖。
     */
    public function __construct(array $weights = [], array $thresholds = [])
    {
        $this->weights = array_merge(self::DEFAULT_WEIGHTS, $weights);
        foreach ($this->weights as $key => $value) {
            if (!isset(self::DEFAULT_WEIGHTS[$key])) {
                throw new InvalidArgumentException('未知的评分维度：' . $key);
            }
            if ((int) $value < 0) {
                throw new InvalidArgumentException('权重不得为负：' . $key);
            }
        }

        $this->thresholds = array_merge(
            [
                // 首屏阻塞脚本数：超出后每多一个扣 6 分
                'blocking_script_free'      => 2,
                'blocking_script_penalty'   => 6,
                // 首屏阻塞样式表数：超出后每多一个扣 5 分
                'blocking_css_free'         => 2,
                'blocking_css_penalty'      => 5,
                // 阻塞资源总体积（字节）
                'blocking_bytes_free'       => 150000,
                'blocking_bytes_penalty'   => 18,
                // 图片缺尺寸比例（0~1）
                'dimension_rate_free'       => 0.0,
                'dimension_penalty'         => 15,
                // 图片懒加载覆盖率（0~1），低于该值开始扣分
                'lazy_rate_target'          => 0.9,
                'lazy_penalty'              => 12,
                // 字体问题
                'font_penalty_each'         => 5,
                // 缓存标识缺失比例
                'cache_rate_free'           => 0.0,
                'cache_penalty'             => 9,
                // 总体积（字节）
                'payload_free'              => 500000,
                'payload_penalty'           => 12,
                // 第三方资源体积占比
                'third_party_rate_target'   => 0.3,
                'third_party_penalty'       => 8,
            ],
            $thresholds
        );
    }

    /**
     * 对审计摘要评分。
     *
     * @param array<string,mixed> $summary ResourceAuditor::summarize() 的返回值。
     * @return array<string,mixed>
     */
    public function score(array $summary): array
    {
        $deductions = [];
        $raw        = 100.0;

        $raw -= $this->scoreBlockingScripts($summary, $deductions);
        $raw -= $this->scoreBlockingCss($summary, $deductions);
        $raw -= $this->scoreBlockingBytes($summary, $deductions);
        $raw -= $this->scoreImageDimensions($summary, $deductions);
        $raw -= $this->scoreImageLazyLoad($summary, $deductions);
        $raw -= $this->scoreFontLoading($summary, $deductions);
        $raw -= $this->scoreCachePolicy($summary, $deductions);
        $raw -= $this->scorePayload($summary, $deductions);
        $raw -= $this->scoreThirdParty($summary, $deductions);

        $score = (int) round(max(0.0, min(100.0, $raw)));

        return [
            'score'       => $score,
            'grade'       => $this->grade($score),
            'raw_score'   => round($raw, 2),
            'deductions'  => $deductions,
            'total_penalty' => (int) round(max(0.0, 100.0 - $raw)),
            'summary'     => $this->verdict($score, $deductions),
        ];
    }

    /**
     * 便捷方法：直接审计 HTML 并评分。
     *
     * @param string $html 页面 HTML。
     * @return array<string,mixed>
     */
    public function scoreHtml(string $html, ?ResourceAuditor $auditor = null): array
    {
        $auditor = $auditor ?? new ResourceAuditor();
        $summary = $auditor->summarize($html);
        $result  = $this->score($summary);
        $result['audit'] = $summary;

        return $result;
    }

    /**
     * 评级。
     */
    public function grade(int $score): string
    {
        if ($score >= self::GRADE_EXCELLENT) {
            return 'A';
        }
        if ($score >= self::GRADE_GOOD) {
            return 'B';
        }
        if ($score >= self::GRADE_FAIR) {
            return 'C';
        }
        if ($score >= 40) {
            return 'D';
        }

        return 'E';
    }

    /**
     * 取得权重表。
     *
     * @return array<string,int>
     */
    public function weights(): array
    {
        return $this->weights;
    }

    /**
     * 取得阈值配置。
     *
     * @return array<string,mixed>
     */
    public function thresholds(): array
    {
        return $this->thresholds;
    }

    /* ================================================================
     *  各维度评分
     * ================================================================ */

    /**
     * 首屏阻塞脚本。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细（引用传递）。
     */
    protected function scoreBlockingScripts(array $summary, array &$deductions): float
    {
        $blocking = $this->blockingScripts($summary);
        $free     = (int) $this->thresholds['blocking_script_free'];
        $excess   = max(0, count($blocking) - $free);
        if ($excess === 0) {
            return 0.0;
        }

        $penalty = min(
            $this->weights['render_blocking'],
            $excess * (int) $this->thresholds['blocking_script_penalty']
        );

        return $this->record(
            $deductions,
            'render_blocking',
            '首屏阻塞脚本',
            $penalty,
            sprintf('%d 个阻塞脚本（阈值 %d 个），每个扣 %d 分。', count($blocking), $free, (int) $this->thresholds['blocking_script_penalty']),
            array_map(static function (Resource $r): string {
                return $r->url() !== '' ? $r->url() : '(内联脚本)';
            }, $blocking)
        );
    }

    /**
     * 渲染阻塞样式表。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreBlockingCss(array $summary, array &$deductions): float
    {
        $blocking = $this->blockingStylesheets($summary);
        $free     = (int) $this->thresholds['blocking_css_free'];
        $excess   = max(0, count($blocking) - $free);
        if ($excess === 0) {
            return 0.0;
        }

        $penalty = min(
            $this->weights['render_blocking_css'],
            $excess * (int) $this->thresholds['blocking_css_penalty']
        );

        return $this->record(
            $deductions,
            'render_blocking_css',
            '渲染阻塞样式表',
            $penalty,
            sprintf('%d 个阻塞样式表（阈值 %d 个），每个扣 %d 分。', count($blocking), $free, (int) $this->thresholds['blocking_css_penalty']),
            array_map(static function (Resource $r): string {
                return $r->url() !== '' ? $r->url() : '(内联样式)';
            }, $blocking)
        );
    }

    /**
     * 阻塞资源总体积。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreBlockingBytes(array $summary, array &$deductions): float
    {
        $bytes = (int) ($summary['blocking_bytes'] ?? 0);
        $free  = (int) $this->thresholds['blocking_bytes_free'];
        if ($bytes <= $free) {
            return 0.0;
        }

        $ratio  = ($bytes - $free) / max(1, $free);
        $penalty = min(
            $this->weights['render_blocking'],
            (int) $this->thresholds['blocking_bytes_penalty'] * min(1.0, $ratio)
        );

        return $this->record(
            $deductions,
            'render_blocking',
            '阻塞资源体积',
            (float) $penalty,
            sprintf('阻塞资源合计 %s，超过 %s 阈值。', Resource::formatBytes($bytes), Resource::formatBytes($free)),
            []
        );
    }

    /**
     * 图片缺尺寸（CLS 主因）。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreImageDimensions(array $summary, array &$deductions): float
    {
        $images = $this->resourcesOfType($summary, Resource::TYPE_IMAGE);
        if ($images === []) {
            return 0.0;
        }

        $missing = array_values(array_filter($images, static function (Resource $r): bool {
            return !$r->isInline() && !$r->hasDimensions();
        }));

        $rate = count($missing) / count($images);
        if ($rate <= (float) $this->thresholds['dimension_rate_free']) {
            return 0.0;
        }

        $penalty = (int) $this->weights['image_dimensions'] * $rate;

        return $this->record(
            $deductions,
            'image_dimensions',
            '图片未声明尺寸',
            (float) $penalty,
            sprintf('%d/%d 张图片缺少 width/height（%.0f%%），直接损害 CLS。', count($missing), count($images), $rate * 100),
            array_map(static function (Resource $r): string {
                return $r->url();
            }, $missing)
        );
    }

    /**
     * 图片懒加载覆盖率。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreImageLazyLoad(array $summary, array &$deductions): float
    {
        $images = array_values(array_filter($this->resourcesOfType($summary, Resource::TYPE_IMAGE), static function (Resource $r): bool {
            return !$r->isInline();
        }));
        if ($images === []) {
            return 0.0;
        }

        $lazy = count(array_filter($images, static function (Resource $r): bool {
            return $r->isLazyLoaded();
        }));

        $rate   = $lazy / count($images);
        $target = (float) $this->thresholds['lazy_rate_target'];
        if ($rate >= $target) {
            return 0.0;
        }

        $penalty = (int) $this->weights['image_lazyload'] * ($target - $rate) / $target;

        return $this->record(
            $deductions,
            'image_lazyload',
            '图片懒加载覆盖不足',
            (float) $penalty,
            sprintf('懒加载率 %.0f%%，目标 %.0f%%。首屏主图之外的图片都应启用 loading="lazy"。', $rate * 100, $target * 100),
            array_map(static function (Resource $r): string {
                return $r->url();
            }, array_filter($images, static function (Resource $r): bool {
                return !$r->isLazyLoaded();
            }))
        );
    }

    /**
     * 字体加载方式。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreFontLoading(array $summary, array &$deductions): float
    {
        $problemFonts = array_values(array_filter($this->resourcesOfType($summary, Resource::TYPE_FONT), static function (Resource $r): bool {
            return $r->hasIssue('font-display') || $r->hasIssue('font-preload-cors');
        }));
        if ($problemFonts === []) {
            return 0.0;
        }

        $each    = (int) $this->thresholds['font_penalty_each'];
        $penalty = min($this->weights['font_loading'], count($problemFonts) * $each);

        return $this->record(
            $deductions,
            'font_loading',
            '字体加载方式不当',
            (float) $penalty,
            sprintf('%d 个字体资源未 preload 或 preload 缺少 crossorigin，易触发 FOIT。', count($problemFonts)),
            array_map(static function (Resource $r): string {
                return $r->url();
            }, $problemFonts)
        );
    }

    /**
     * 缓存标识。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreCachePolicy(array $summary, array &$deductions): float
    {
        $total     = (int) ($summary['total_count'] ?? 0);
        $noCache   = (int) ($summary['no_cache_count'] ?? 0);
        if ($total === 0 || $noCache === 0) {
            return 0.0;
        }

        $rate    = $noCache / $total;
        $penalty = (int) $this->weights['cache_policy'] * min(1.0, $rate);

        return $this->record(
            $deductions,
            'cache_policy',
            '静态资源缺少版本标识',
            (float) $penalty,
            sprintf('%d/%d 个静态资源（%.0f%%）无 ver/version 或内容哈希，CDN 缓存难以命中。', $noCache, $total, $rate * 100),
            array_slice((array) ($summary['no_cache_urls'] ?? []), 0, 10)
        );
    }

    /**
     * 总传输体积。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scorePayload(array $summary, array &$deductions): float
    {
        $bytes = (int) ($summary['total_bytes'] ?? 0);
        $free  = (int) $this->thresholds['payload_free'];
        if ($bytes <= $free) {
            return 0.0;
        }

        $ratio   = ($bytes - $free) / max(1, $free);
        $penalty = (int) $this->weights['payload_size'] * min(1.0, $ratio);

        return $this->record(
            $deductions,
            'payload_size',
            '页面总体积偏大',
            (float) $penalty,
            sprintf('资源合计 %s（%d 个请求），超过 %s 基准。', Resource::formatBytes($bytes), (int) ($summary['total_count'] ?? 0), Resource::formatBytes($free)),
            []
        );
    }

    /**
     * 第三方资源占比。
     *
     * @param array<string,mixed> $summary   审计摘要。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function scoreThirdParty(array $summary, array &$deductions): float
    {
        $thirdBytes = (int) ($summary['third_party_bytes'] ?? 0);
        $totalBytes = (int) ($summary['total_bytes'] ?? 0);
        if ($thirdBytes === 0 || $totalBytes === 0) {
            return 0.0;
        }

        $rate    = $thirdBytes / $totalBytes;
        $target  = (float) $this->thresholds['third_party_rate_target'];
        if ($rate <= $target) {
            return 0.0;
        }

        $penalty = (int) $this->weights['third_party'] * min(1.0, ($rate - $target) / (1 - $target));

        return $this->record(
            $deductions,
            'third_party',
            '第三方资源占比过高',
            (float) $penalty,
            sprintf('第三方资源 %s，占总体积 %.0f%%（阈值 %.0f%%），主域名 DNS 与 TLS 握手成本不可控。', Resource::formatBytes($thirdBytes), $rate * 100, $target * 100),
            []
        );
    }

    /* ================================================================
     *  工具方法
     * ================================================================ */

    /**
     * 记录一条扣分。
     *
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     * @param string                         $dimension  维度键。
     * @param string                         $label      中文标签。
     * @param float                          $penalty    扣分值。
     * @param string                         $reason     原因。
     * @param array<int,string>              $examples   示例 URL。
     */
    protected function record(array &$deductions, string $dimension, string $label, float $penalty, string $reason, array $examples): float
    {
        if ($penalty <= 0.0) {
            return 0.0;
        }

        $deductions[] = [
            'dimension' => $dimension,
            'label'     => $label,
            'penalty'   => round($penalty, 1),
            'max'       => $this->weights[$dimension] ?? 0,
            'reason'    => $reason,
            'examples'  => array_slice($examples, 0, 10),
        ];

        return $penalty;
    }

    /**
     * 从审计结果中取出指定类型的资源对象。
     *
     * @param array<string,mixed> $summary 审计摘要。
     * @param string              $type    资源类型。
     * @return array<int,Resource>
     */
    protected function resourcesOfType(array $summary, string $type): array
    {
        $out = [];
        foreach ((array) ($summary['resources'] ?? []) as $item) {
            if (!is_array($item) || ($item['type'] ?? '') !== $type) {
                continue;
            }
            $out[] = $this->rehydrate($item);
        }

        return $out;
    }

    /**
     * 从审计摘要中取出阻塞的脚本。
     *
     * @param array<string,mixed> $summary 审计摘要。
     * @return array<int,Resource>
     */
    protected function blockingScripts(array $summary): array
    {
        $out = [];
        foreach ((array) ($summary['resources'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === Resource::TYPE_SCRIPT && !empty($item['blocking'])) {
                $out[] = $this->rehydrate($item);
            }
        }

        return $out;
    }

    /**
     * 从审计摘要中取出阻塞的样式表。
     *
     * @param array<string,mixed> $summary 审计摘要。
     * @return array<int,Resource>
     */
    protected function blockingStylesheets(array $summary): array
    {
        $out = [];
        foreach ((array) ($summary['resources'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            if (($item['type'] ?? '') === Resource::TYPE_STYLESHEET && !empty($item['blocking'])) {
                $out[] = $this->rehydrate($item);
            }
        }

        return $out;
    }

    /**
     * 把数组形态的资源描述还原为 Resource 对象。
     *
     * 评分阶段只读属性，因此只需填充参与判断的字段。
     *
     * @param array<string,mixed> $item 资源描述。
     */
    protected function rehydrate(array $item): Resource
    {
        $resource = new Resource(
            (string) ($item['url'] ?? ''),
            (string) ($item['type'] ?? Resource::TYPE_OTHER),
            (string) ($item['tag'] ?? ''),
            (int) ($item['size'] ?? 0),
            (array) ($item['attributes'] ?? [])
        );
        $resource->setInHead(!empty($item['in_head']));

        // 从 issues 数组重建标记。
        foreach ((array) ($item['issues'] ?? []) as $issue) {
            if (is_array($issue) && isset($issue['code'])) {
                $resource->addIssue((string) $issue['code'], (string) ($issue['message'] ?? ''));
            }
        }

        return $resource;
    }

    /**
     * 生成一句话结论与首要优化项。
     *
     * @param int                            $score      得分。
     * @param array<int,array<string,mixed>> $deductions 扣分明细。
     */
    protected function verdict(int $score, array $deductions): string
    {
        $grade = $this->grade($score);

        if ($deductions === []) {
            return sprintf('得分 %d（评级 %s），未发现明显性能隐患。', $score, $grade);
        }

        usort($deductions, static function (array $a, array $b): int {
            return $b['penalty'] <=> $a['penalty'];
        });

        $top = $deductions[0];

        return sprintf(
            '得分 %d（评级 %s），共 %d 个维度扣分；首要问题是「%s」（扣 %.1f 分）：%s',
            $score,
            $grade,
            count($deductions),
            $top['label'],
            $top['penalty'],
            $top['reason']
        );
    }
}
