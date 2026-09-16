<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;

/**
 * Every write path for pages goes through PageUrlGuard, inside a transaction.
 *
 * See PageUrlGuardTest for the measurement. The three paths:
 *
 * - `contao:page:create`
 * - `contao:page:update`
 * - `contao:record:clone` for tl_page (PageCloner)
 *
 * The cloner has two more duties from the same phase-4 run:
 * - a root may be cloned **into another language in one step** — `language`,
 *   `urlPrefix`, `urlSuffix`, `fallback` and `dns` are accepted for a root, and the
 *   result is checked; otherwise the forbidden duplicate root is the only way through
 * - aliases come from Contao's `generateAlias()`, as a back-end copy does
 *   (`tl_page.alias` carries `doNotCopy`) — not `<title>-kopie-<random>`, which turned
 *   `index` into `startseite-kopie-kopie-9224`
 * - the cloned root goes behind its last sibling, like every created record
 */
class PageUrlRulesWiringTest extends TestCase
{
    private function source(string $path): string
    {
        return (string) file_get_contents(__DIR__ . '/../../src/' . $path);
    }

    /**
     * @dataProvider writePaths
     */
    public function testTheWritePathChecksInsideATransaction(string $path): void
    {
        $source = $this->source($path);

        $this->assertStringContainsString('transactional(', $source, "{$path} must write and check in one transaction");
        $this->assertStringContainsString('assertRootUnique(', $source, "{$path} must check the root's domain and prefix");
        $this->assertStringContainsString('assertAliases(', $source, "{$path} must check the resulting URLs");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function writePaths(): array
    {
        return [
            'page create' => ['Command/PageCreateCommand.php'],
            'page update' => ['Command/PageUpdateCommand.php'],
            'page clone'  => ['Service/Cloner/PageCloner.php'],
        ];
    }

    public function testTheClonerNoLongerInventsAliases(): void
    {
        $source = $this->source('Service/Cloner/PageCloner.php');

        $this->assertStringNotContainsString("'-kopie-'", $source);
        $this->assertStringContainsString('->generateAlias(', $source);
    }

    /**
     * The generated alias is saved through the model layer (v0.15.1).
     *
     * `generateAlias()` detaches the instance it looks up, so the held `$clone` cannot
     * be saved again. v0.15.0 wrote the alias through the connection instead — it
     * worked, but left the model layer every other write of this bundle goes through.
     * Michael questioned it on 2026-09-16. A fresh instance from `findByPk()` is
     * registered and can be saved.
     */
    public function testTheClonedAliasIsSavedThroughAFreshModel(): void
    {
        $source = $this->source('Service/Cloner/PageCloner.php');

        $this->assertStringNotContainsString("connection->update('tl_page'", $source);
        $this->assertMatchesRegularExpression('/PageModel::findByPk\(\$newId\)/', $source);
    }

    public function testTheClonerAcceptsRootFieldsForARoot(): void
    {
        $source = $this->source('Service/Cloner/PageCloner.php');

        foreach (['language', 'urlPrefix', 'urlSuffix', 'fallback', 'dns'] as $field) {
            $this->assertMatchesRegularExpression("/ROOT_PAGE_MODIFICATIONS\s*=\s*\[[^\]]*'{$field}'/", $source, "root clones must accept {$field}");
        }
    }

    public function testTheClonedRootGoesBehindItsLastSibling(): void
    {
        $this->assertStringContainsString('Sorting::after(', $this->source('Service/Cloner/PageCloner.php'));
    }
}
