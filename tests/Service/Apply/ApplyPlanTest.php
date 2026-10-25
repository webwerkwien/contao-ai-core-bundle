<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Apply;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\ApplyPlan;
use Webwerkwien\ContaoAiCoreBundle\Service\Apply\ApplyPlanException;

/**
 * `contao:ai:apply` checks the whole plan before the first write (v1.13.0): a run
 * that fails at step 30 on a typo in step 30 has already written 29 steps, and only
 * the rollback stands between that and a half-built page.
 */
class ApplyPlanTest extends TestCase
{
    private const VALID = <<<'JSON'
        {"steps": [
          {"ref": "versand", "do": "page create", "title": "Shipping", "pid": 59, "set": {"published": "1"}},
          {"do": "language link", "table": "tl_page", "id": "@versand", "main": 92},
          {"ref": "intro", "do": "content create", "type": "text", "pid": "@versand.article", "ptable": "tl_article",
           "set": {"text": "<p>Mail info@example.com or @example, see {{link_url::@versand}}</p>"}},
          {"do": "content update", "id": 771, "set": {"headline": {"unit": "h2", "value": "Overview"}}}
        ]}
        JSON;

    public function testAValidPlanKeepsItsSteps(): void
    {
        $plan = ApplyPlan::fromJson(self::VALID);

        $this->assertCount(4, $plan->steps);
        $this->assertSame('language link', $plan->steps[1]['do']);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedPlans(): iterable
    {
        yield 'not JSON' => ['{steps: [', 'Not valid JSON'];
        yield 'no steps' => ['{"pages": []}', 'list "steps"'];
        yield 'empty' => ['{"steps": []}', 'No steps'];
        yield 'unknown do' => ['{"steps": [{"do": "page get", "id": 1}]}', 'unknown "do"'];
        yield 'unknown key' => ['{"steps": [{"do": "page update", "id": 1, "titel": "x"}]}', 'unknown key "titel"'];
        yield 'set on a move' => ['{"steps": [{"do": "page move", "id": 1, "to": 2, "set": {"a": "b"}}]}', 'unknown key "set"'];
        yield 'missing id' => ['{"steps": [{"do": "content delete"}]}', '"id" is required'];
        yield 'set as a list' => ['{"steps": [{"do": "page update", "id": 1, "set": ["title=x"]}]}', '"set" must be an object'];
        yield 'option as object' => ['{"steps": [{"do": "page create", "title": {"x": 1}}]}', '"title" must be text or a number'];
        yield 'bad ref name' => ['{"steps": [{"ref": "Versand Seite", "do": "page create", "title": "x"}]}', 'ref "Versand Seite"'];
        yield 'duplicate ref' => ['{"steps": [{"ref": "a", "do": "page create", "title": "x"}, {"ref": "a", "do": "page create", "title": "y"}]}', 'ref "a" is used twice'];
        yield 'forward reference' => ['{"steps": [{"do": "content create", "type": "text", "pid": "@later"}, {"ref": "later", "do": "article create", "title": "x"}]}', '"@later" does not name an earlier step'];
        yield 'self reference' => ['{"steps": [{"ref": "a", "do": "page create", "title": "x", "pid": "@a"}]}', '"@a" does not name an earlier step'];
        yield 'article of a non-page' => ['{"steps": [{"ref": "a", "do": "article create", "title": "x"}, {"do": "content create", "type": "text", "pid": "@a.article"}]}', '"@a.article" needs a page create'];
    }

    /**
     * @dataProvider refusedPlans
     */
    public function testRefused(string $json, string $expected): void
    {
        try {
            ApplyPlan::fromJson($json);
            $this->fail('accepted: ' . $json);
        } catch (ApplyPlanException $e) {
            $this->assertStringContainsString($expected, implode("\n", $e->problems));
        }
    }

    public function testEveryProblemIsListedNotOnlyTheFirst(): void
    {
        try {
            ApplyPlan::fromJson('{"steps": [{"do": "page get"}, {"do": "content delete"}, {"do": "page update", "id": 1, "x": 1}]}');
            $this->fail('accepted');
        } catch (ApplyPlanException $e) {
            $this->assertCount(3, $e->problems);
            $this->assertStringStartsWith('Step 0', $e->problems[0]);
            $this->assertStringStartsWith('Step 2', $e->problems[2]);
        }
    }

    public function testMoreThanTheLimitIsRefused(): void
    {
        $steps = array_fill(0, ApplyPlan::MAX_STEPS + 1, ['do' => 'page update', 'id' => 1]);

        $this->expectException(ApplyPlanException::class);
        ApplyPlan::fromJson(json_encode(['steps' => $steps], JSON_THROW_ON_ERROR));
    }

    public function testTheLimitItselfIsAccepted(): void
    {
        $steps = array_fill(0, ApplyPlan::MAX_STEPS, ['do' => 'page update', 'id' => 1]);

        $this->assertCount(ApplyPlan::MAX_STEPS, ApplyPlan::fromJson(json_encode(['steps' => $steps], JSON_THROW_ON_ERROR))->steps);
    }
}
