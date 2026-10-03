<?php
/**
 * 懒加载与加载优先级建议测试。
 *
 * @package MornRain\PerfSentry\Tests
 */

declare(strict_types=1);

namespace MornRain\PerfSentry\Tests;

use MornRain\PerfSentry\LazyLoadHint;
use MornRain\PerfSentry\Resource;

/**
 * LazyLoadHint 测试。
 */
class LazyLoadHintTest extends TestCase
{
    /**
     * 构造一份含首图、缩略图、iframe 与阻塞脚本的页面。
     */
    private function html(): string
    {
        return <<<'HTML'
<!DOCTYPE html><html><body>
<img src="https://example.com/hero-banner.jpg" width="1" height="1">
<img src="https://example.com/thumb.jpg">
<img src="https://example.com/thumb2.jpg" loading="lazy" width="1" height="1">
<iframe src="https://example.com/e"></iframe>
<script src="https://example.com/a.js"></script>
</body></html>
HTML;
    }

    public function testFirstImageTreatedAsLcpCandidate(): void
    {
        $items  = (new LazyLoadHint('example.com'))->suggest($this->html())['suggestions'];

        $images = array_values(array_filter($items, static function (array $i): bool {
            return $i['kind'] === 'image';
        }));

        self::assertTrue(count($images) >= 3);
        self::assertTrue($images[0]['lcp_candidate'], '首图应标记为 LCP 候选');
        self::assertArrayNotHasKey('loading', $images[0]['attributes'], 'LCP 图不应加 lazy');
    }

    public function testBelowFoldImagesGetLazy(): void
    {
        $hint = new LazyLoadHint('example.com');
        $res  = $hint->forImage(new Resource('https://example.com/thumb.jpg', Resource::TYPE_IMAGE, 'img'), 3);

        self::assertSame('lazy', $res['attributes']['loading'] ?? null);
        self::assertSame('async', $res['attributes']['decoding'] ?? null);
    }

    public function testAlreadyLazyImageIsNotFlagged(): void
    {
        $hint = new LazyLoadHint();
        // index 较大确保落在首屏之外；属性齐备时不应再产生任何建议
        $res = $hint->forImage(
            new Resource(
                'https://example.com/a.jpg',
                Resource::TYPE_IMAGE,
                'img',
                0,
                ['loading' => 'lazy', 'decoding' => 'async', 'width' => '1', 'height' => '1']
            ),
            9
        );

        self::assertFalse($res['applicable'], '已具备懒加载、解码与尺寸时无需建议');
    }

    public function testAboveFoldImageWithEverythingSetStillGetsFetchPriority(): void
    {
        $hint = new LazyLoadHint();
        $res  = $hint->forImage(
            new Resource('https://example.com/hero.jpg', Resource::TYPE_IMAGE, 'img', 0, ['loading' => 'lazy', 'width' => '1', 'height' => '1']),
            0
        );

        // 首屏图即使已声明尺寸，仍应建议 fetchpriority=high
        self::assertSame('high', $res['attributes']['fetchpriority'] ?? null);
    }

    public function testMissingDimensionsProduceHint(): void
    {
        $hint = new LazyLoadHint();
        $res  = $hint->forImage(new Resource('https://example.com/a.jpg', Resource::TYPE_IMAGE, 'img'), 5);

        self::assertStringContains('width', $res['dimension_hint']);
    }

    public function testLazyAboveFoldOption(): void
    {
        $hint = (new LazyLoadHint())->lazyAboveFold(true);
        $res  = $hint->forImage(new Resource('https://example.com/hero.jpg', Resource::TYPE_IMAGE, 'img'), 0);

        self::assertSame('lazy', $res['attributes']['loading'] ?? null);
    }

    public function testIframeAlwaysGetsLazy(): void
    {
        $hint = new LazyLoadHint();
        $res  = $hint->forIframe(new Resource('https://example.com/e', Resource::TYPE_IFRAME, 'iframe'));

        self::assertSame('lazy', $res['attributes']['loading'] ?? null);
        self::assertArrayHasKey('width', $res['attributes']);
        self::assertArrayHasKey('height', $res['attributes']);
    }

    public function testBlockingScriptGetsDefer(): void
    {
        $hint = new LazyLoadHint();
        $res  = $hint->forScript(new Resource('https://example.com/a.js', Resource::TYPE_SCRIPT, 'script', 0, []));

        self::assertTrue($res['applicable']);
        self::assertArrayHasKey('defer', $res['attributes']);
    }

    public function testDeferredScriptNeedsNoChange(): void
    {
        $hint = new LazyLoadHint();
        $res  = $hint->forScript(new Resource('https://example.com/a.js', Resource::TYPE_SCRIPT, 'script', 0, ['defer' => 'defer']));

        self::assertFalse($res['applicable']);
    }

    public function testSnippetIsEscaped(): void
    {
        $hint = new LazyLoadHint();
        $res  = $hint->forImage(new Resource('https://example.com/a.jpg?x="><script>', Resource::TYPE_IMAGE, 'img'), 5);

        // 生成的片段必须转义，防止把建议本身变成注入点
        self::assertStringNotContains('<script>', $res['snippet']);
        self::assertStringContains('&quot;', $res['snippet']);
    }

    public function testEmptyHtmlYieldsNoSuggestions(): void
    {
        $result = (new LazyLoadHint())->suggest('');

        self::assertSame(0, $result['count']);
    }

    public function testSuggestReturnsApplicableSubset(): void
    {
        $result = (new LazyLoadHint())->suggest($this->html());

        self::assertArrayHasKey('suggestions', $result);
        self::assertArrayHasKey('applicable', $result);
        self::assertTrue(count($result['applicable']) <= count($result['suggestions']));
    }
}
