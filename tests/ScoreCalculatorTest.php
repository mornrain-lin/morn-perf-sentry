<?php
/**
 * 性能评分器测试。
 *
 * @package MornRain\PerfSentry\Tests
 */

declare(strict_types=1);

namespace MornRain\PerfSentry\Tests;

use InvalidArgumentException;
use MornRain\PerfSentry\ScoreCalculator;

/**
 * ScoreCalculator 测试。
 */
class ScoreCalculatorTest extends TestCase
{
    /**
     * 优质页面：无阻塞脚本、图都有尺寸与懒加载。
     */
    private function goodHtml(): string
    {
        return <<<'HTML'
<!DOCTYPE html><html><head></head><body>
<img src="https://example.com/a.jpg" width="1" height="1" loading="lazy">
<link rel="preload" as="font" href="https://example.com/f.woff2?ver=12345678" crossorigin>
</body></html>
HTML;
    }

    /**
     * 问题页面：大量未优化资源。
     */
    private function badHtml(): string
    {
        $imgs = '';
        for ($i = 0; $i < 6; $i++) {
            $imgs .= '<img src="https://example.com/p' . $i . '.jpg">';
        }

        return '<!DOCTYPE html><html><head>'
            . '<link rel="stylesheet" href="https://example.com/a.css">'
            . '<link rel="stylesheet" href="https://example.com/b.css">'
            . '<link rel="stylesheet" href="https://example.com/c.css">'
            . '<script src="https://example.com/a.js"></script>'
            . '<script src="https://example.com/b.js"></script>'
            . '<script src="https://example.com/c.js"></script>'
            . '<script src="https://cdn.other.com/d.js"></script>'
            . '</head><body>' . $imgs . '</body></html>';
    }

    public function testGoodPageScoresHigh(): void
    {
        $result = (new ScoreCalculator())->scoreHtml($this->goodHtml());

        self::assertTrue($result['score'] >= 90, '优质页面应得高分，实际 ' . $result['score']);
        self::assertSame('A', $result['grade']);
    }

    public function testBadPageScoresLow(): void
    {
        $result = (new ScoreCalculator())->scoreHtml($this->badHtml());

        self::assertTrue($result['score'] < 60, '问题页面应得低分，实际 ' . $result['score']);
        self::assertTrue(count($result['deductions']) > 0, '应产出扣分明细');
    }

    public function testScoreIsAlwaysInRange(): void
    {
        foreach (['', $this->goodHtml(), $this->badHtml()] as $html) {
            $score = (new ScoreCalculator())->scoreHtml($html)['score'];

            self::assertTrue($score >= 0 && $score <= 100, "分数越界：{$score}");
            self::assertTrue(is_int($score));
        }
    }

    public function testEmptySummaryScoresPerfectly(): void
    {
        $result = (new ScoreCalculator())->score(['total_count' => 0]);

        self::assertSame(100, $result['score']);
        self::assertCount(0, $result['deductions']);
    }

    public function testPartialSummaryIsTolerated(): void
    {
        $calculator = new ScoreCalculator();
        // 缺字段的摘要不应导致致命错误
        self::assertDoesNotThrow(static function () use ($calculator): void {
            $calculator->score([]);
        });
    }

    public function testUnknownWeightIsRejected(): void
    {
        self::assertThrows(InvalidArgumentException::class, static function (): void {
            new ScoreCalculator(['not_a_dimension' => 10]);
        });
    }

    public function testNegativeWeightIsRejected(): void
    {
        self::assertThrows(InvalidArgumentException::class, static function (): void {
            new ScoreCalculator(['render_blocking' => -5]);
        });
    }

    public function testGradeBoundaries(): void
    {
        $c = new ScoreCalculator();

        self::assertSame('A', $c->grade(90));
        self::assertSame('A', $c->grade(100));
        self::assertSame('B', $c->grade(75));
        self::assertSame('C', $c->grade(60));
        self::assertSame('D', $c->grade(40));
        self::assertSame('E', $c->grade(39));
        self::assertSame('E', $c->grade(0));
    }

    public function testCustomWeightsAreApplied(): void
    {
        $calculator = new ScoreCalculator(['image_dimensions' => 30]);

        self::assertSame(30, $calculator->weights()['image_dimensions']);
        self::assertSame(22, $calculator->weights()['render_blocking'], '未覆盖项保持默认');
    }

    public function testThresholdsAccessor(): void
    {
        $thresholds = (new ScoreCalculator())->thresholds();

        self::assertArrayHasKey('blocking_script_free', $thresholds);
        self::assertArrayHasKey('payload_free', $thresholds);
    }

    public function testVerdictMentionsTopIssue(): void
    {
        $result = (new ScoreCalculator())->scoreHtml($this->badHtml());

        self::assertStringContains('得分', $result['summary']);
        self::assertStringContains('首要问题', $result['summary']);
    }

    public function testDeductionsAreCappedByWeight(): void
    {
        $result = (new ScoreCalculator())->scoreHtml($this->badHtml());

        foreach ($result['deductions'] as $deduction) {
            self::assertTrue(
                $deduction['penalty'] <= $deduction['max'],
                "维度 {$deduction['dimension']} 扣分 {$deduction['penalty']} 超过上限 {$deduction['max']}"
            );
        }
    }
}
