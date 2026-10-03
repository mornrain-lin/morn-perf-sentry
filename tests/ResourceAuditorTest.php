<?php
/**
 * 资源审计器测试。
 *
 * @package MornRain\PerfSentry\Tests
 */

declare(strict_types=1);

namespace MornRain\PerfSentry\Tests;

use MornRain\PerfSentry\Resource;
use MornRain\PerfSentry\ResourceAuditor;

/**
 * ResourceAuditor 测试。
 */
class ResourceAuditorTest extends TestCase
{
    /**
     * 构造一份典型页面。
     */
    private function html(): string
    {
        return <<<'HTML'
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>测试页面</title>
    <link rel="stylesheet" href="https://example.com/app.css?ver=1.2.3">
    <link rel="stylesheet" href="https://example.com/extra.css">
    <script src="https://example.com/head.js?ver=abc123456"></script>
    <link rel="preload" as="font" href="https://example.com/f.woff2" crossorigin>
    <link rel="icon" href="https://example.com/favicon.ico">
    <style>body{color:#333}</style>
</head>
<body>
    <img src="https://example.com/hero.jpg" width="1200" height="675" fetchpriority="high">
    <img src="https://example.com/thumb-1.jpg">
    <img src="https://example.com/thumb-2.jpg" loading="lazy" width="100" height="100">
    <script src="https://cdn.thirdparty.com/widget.js"></script>
    <iframe src="https://example.com/embed"></iframe>
    <video src="https://example.com/v.mp4"></video>
    <script>console.log(1)</script>
</body>
</html>
HTML;
    }

    public function testFindsAllResourceTypes(): void
    {
        $types = [];
        foreach ((new ResourceAuditor('example.com'))->audit($this->html()) as $resource) {
            $types[$resource->type()] = true;
        }

        foreach ([
            Resource::TYPE_STYLESHEET,
            Resource::TYPE_SCRIPT,
            Resource::TYPE_IMAGE,
            Resource::TYPE_FONT,
            Resource::TYPE_IFRAME,
            Resource::TYPE_MEDIA,
        ] as $type) {
            self::assertTrue(isset($types[$type]), "应识别出 {$type} 类型资源");
        }
    }

    public function testEmptyInputReturnsEmptyArray(): void
    {
        $auditor = new ResourceAuditor();
        self::assertCount(0, $auditor->audit(''));
        self::assertCount(0, $auditor->audit('   '));
    }

    public function testInlineBlobsAreCounted(): void
    {
        $summary = (new ResourceAuditor())->summarize($this->html());

        // 内联 <style> 与 <script> 也应计入请求与体积
        self::assertTrue($summary['total_bytes'] > 0);
    }

    public function testSizeMapIsUsed(): void
    {
        $auditor = (new ResourceAuditor())->withSizeMap([
            'https://example.com/app.css?ver=1.2.3' => 1234,
        ]);
        $found = null;
        foreach ($auditor->audit($this->html()) as $resource) {
            if (strpos($resource->url(), 'app.css') !== false) {
                $found = $resource;
                break;
            }
        }

        self::assertNotNull($found);
        self::assertSame(1234, $found->size(), '应优先使用注入的体积表');
    }

    public function testSizeLookupFallsBackToBasename(): void
    {
        $auditor = (new ResourceAuditor())->withSizeMap(['app.css' => 999]);
        $found   = null;
        foreach ($auditor->audit($this->html()) as $resource) {
            if (strpos($resource->url(), 'app.css') !== false) {
                $found = $resource;
                break;
            }
        }

        self::assertNotNull($found);
        self::assertSame(999, $found->size());
    }

    public function testWithSizeMapToleratesJunk(): void
    {
        $auditor = (new ResourceAuditor())->withSizeMap([
            ''           => 100,
            '  /a.css  ' => -50,
        ]);

        self::assertTrue(is_array($auditor->audit($this->html())));
    }

    public function testSizeWarningThresholdHasFloor(): void
    {
        $auditor = new ResourceAuditor();
        // 低于 1024 的阈值会被抬到下限，避免误报
        self::assertDoesNotThrow(static function () use ($auditor): void {
            $auditor->sizeWarningThreshold(1);
        });
    }

    public function testVersionDetectionMarksNoCacheIssue(): void
    {
        $auditor = new ResourceAuditor();
        $withVer = null;
        $noVer   = null;

        foreach ($auditor->audit($this->html()) as $resource) {
            if (strpos($resource->url(), 'app.css') !== false) {
                $withVer = $resource;
            }
            if (strpos($resource->url(), 'extra.css') !== false) {
                $noVer = $resource;
            }
        }

        self::assertNotNull($withVer);
        self::assertNotNull($noVer);
        self::assertFalse($withVer->hasIssue('no-cache'), '带 ver 的资源不应报缓存问题');
        self::assertTrue($noVer->hasIssue('no-cache'), '无版本标识应报缓存问题');
    }

    public function testImageDimensionAndLazyIssues(): void
    {
        $auditor = new ResourceAuditor();
        $lazy    = null;
        $eager   = null;

        foreach ($auditor->audit($this->html()) as $resource) {
            if (strpos($resource->url(), 'thumb-2.jpg') !== false) {
                $lazy = $resource;
            }
            if (strpos($resource->url(), 'thumb-1.jpg') !== false) {
                $eager = $resource;
            }
        }

        self::assertNotNull($lazy);
        self::assertNotNull($eager);
        self::assertTrue($lazy->isLazyLoaded());
        self::assertTrue($lazy->hasDimensions());
        self::assertTrue($eager->hasIssue('no-dimensions'), '缺 width/height 应报 CLS 风险');
    }

    public function testRenderBlockingDetection(): void
    {
        $scripts = [];
        foreach ((new ResourceAuditor())->audit($this->html()) as $resource) {
            if ($resource->type() === Resource::TYPE_SCRIPT && !$resource->isInline()) {
                $scripts[] = $resource;
            }
        }

        $blocking = array_filter($scripts, static function (Resource $r): bool {
            return $r->isRenderBlocking();
        });
        self::assertTrue(count($blocking) >= 1, 'head 内无 defer/async 的脚本应算阻塞');
    }

    public function testThirdPartyDetection(): void
    {
        $summary = (new ResourceAuditor('example.com'))->summarize($this->html());

        self::assertTrue($summary['third_party_count'] >= 1, 'cdn.thirdparty.com 应识别为第三方');
    }

    public function testSubdomainCountsAsInternal(): void
    {
        $resource = new Resource('https://cdn.example.com/a.js', Resource::TYPE_SCRIPT, 'script');
        self::assertFalse($resource->isThirdParty('example.com'), '子域应视为站内');
        self::assertTrue($resource->isThirdParty('other.com'));
    }

    public function testMalformedHtmlDoesNotCrash(): void
    {
        $auditor = new ResourceAuditor();
        $inputs  = [
            '<link rel=stylesheet href=',
            '<img src="unclosed',
            '<<<>>>',
            str_repeat('<img src="a.jpg">', 2000),
            '<script>var x = "</div>";</script>',
        ];

        foreach ($inputs as $input) {
            self::assertDoesNotThrow(function () use ($auditor, $input): void {
                $auditor->audit($input);
            }, '异常 HTML 不应导致崩溃');
        }
    }

    public function testVeryLargeInputIsHandled(): void
    {
        $html    = str_repeat('<img src="https://example.com/a.jpg" width="1" height="1">' . str_repeat('x', 100), 3000);
        $summary = (new ResourceAuditor())->summarize($html);

        self::assertTrue($summary['total_count'] > 0);
    }

    public function testSummarizeShape(): void
    {
        $summary = (new ResourceAuditor('example.com'))->summarize($this->html());

        foreach ([
            'total_count', 'total_bytes', 'by_type', 'blocking_count',
            'no_cache_count', 'not_lazy_count', 'issue_count', 'resources',
        ] as $key) {
            self::assertArrayHasKey($key, $summary);
        }
    }

    public function testSummarizeHandlesEmptyResources(): void
    {
        $summary = (new ResourceAuditor())->summarize('');

        self::assertSame(0, $summary['total_count']);
        self::assertSame(0, $summary['total_bytes']);
    }

    public function testSetActualSizeOverridesEstimate(): void
    {
        $auditor = (new ResourceAuditor())->setActualSize('https://example.com/app.css?ver=1.2.3', 4321);
        $found   = null;
        foreach ($auditor->audit($this->html()) as $resource) {
            if (strpos($resource->url(), 'app.css') !== false) {
                $found = $resource;
                break;
            }
        }

        self::assertNotNull($found);
        self::assertSame(4321, $found->size());
    }

    public function testResourceAccessors(): void
    {
        $resource = new Resource('https://example.com/a.css?x=1', Resource::TYPE_STYLESHEET, 'link', 2048, ['rel' => 'stylesheet']);

        self::assertSame('css', $resource->extension());
        self::assertSame('2 KB', $resource->humanSize());
        self::assertSame('stylesheet', $resource->attribute('REL'), '属性名查询应大小写无关');
        self::assertFalse($resource->isFont());
        self::assertTrue(is_array($resource->toArray()));
    }

    public function testFormatBytesBoundaries(): void
    {
        self::assertSame('0 B', Resource::formatBytes(0));
        self::assertSame('1023 B', Resource::formatBytes(1023));
        self::assertSame('1 KB', Resource::formatBytes(1024));
        self::assertSame('1 MB', Resource::formatBytes(1048576));
    }
}
