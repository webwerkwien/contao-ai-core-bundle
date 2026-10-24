<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Page;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageUrlGuard;
use Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner\PageCopyResetTest;

/**
 * A page write may not produce a URL Contao's back end refuses.
 *
 * 🔴 **Measured on Contao 5.7.13 with core-bundle v0.14.0:**
 *
 * | write | answer |
 * |---|---|
 * | `page update 132 --set urlPrefix=en` while root 138 on the same domain has `en` | `ok` — two roots `example.com/en` |
 * | `page update 141 --set alias=packages` while 140 in the same branch has it | `ok` — two pages at `/en/packages` |
 * | `record clone` of a root | two roots `example.com` without prefix, because `language`/`urlPrefix` were not accepted as modifications |
 *
 * Contao refuses all of it in `PageUrlListener` — `validateUrlPrefix()` and
 * `generateAlias()`, save callbacks the bundle does not run. Domain and prefix pick
 * the root, and with it the language, the 404 page, the sitemap and robots.txt; a
 * duplicate URL leaves one page unreachable.
 *
 * ## How the rules are applied
 *
 * - **Root uniqueness** is Contao's own query, verbatim (identical in 5.3, 5.7, 6.0):
 *   no other root with the same `dns` and `urlPrefix`.
 * - **Alias uniqueness** is not rebuilt. Contao compares complete URLs through the
 *   router, and a copy would drift. `PageUrlGuard` calls
 *   `PageUrlListener::generateAlias()` itself — it needs only `$dc->id` and reads the
 *   stored record, so the check runs after the write, inside a transaction that is
 *   rolled back on a refusal.
 * - `validateUrlPrefix()` cannot be called: it goes through `getCurrentRecord()`,
 *   which asks the permission voters and fails without a back-end user.
 */
class PageUrlGuardTest extends TestCase
{
    private function guard(Connection $connection): PageUrlGuard
    {
        return new PageUrlGuard($connection, $this->createMock(ContaoFramework::class));
    }

    /**
     * A second page with alias
     * `index` under the same root is accepted by Contao's back end too — it only shows
     * "the following pages have a similar alias that may conflict". The bundle saved it
     * silently. Contao's rule (`PageRoutingListener::generateRouteConflicts()`): same
     * domain, routable, same static URL prefix plus suffix.
     */
    public function testRouteConflictsFollowContaosBackEndHint(): void
    {
        $current = ['id' => 176, 'title' => 'Imprint', 'alias' => 'index', 'domain' => 'example.org', 'url' => '/en/index', 'path' => '/en/index{!parameters}', 'routable' => true];

        $conflicts = PageUrlGuard::selectRouteConflicts($current, [
            ['id' => 174, 'title' => 'Home', 'alias' => 'index', 'domain' => 'example.org', 'url' => '/en/index', 'path' => '/en/index{!parameters}', 'routable' => true],
            ['id' => 169, 'title' => 'Startseite', 'alias' => 'index', 'domain' => 'example.org', 'url' => '/index', 'path' => '/index{!parameters}', 'routable' => true],
            ['id' => 133, 'title' => 'Startseite', 'alias' => 'index', 'domain' => 'example.com', 'url' => '/en/index', 'path' => '/en/index{!parameters}', 'routable' => true],
            ['id' => 99, 'title' => 'Ordner', 'alias' => 'index', 'domain' => 'example.org', 'url' => '/en/index', 'path' => '/en/index', 'routable' => false],
        ]);

        $this->assertSame([['id' => 174, 'title' => 'Home', 'alias' => 'index', 'path' => '/en/index{!parameters}']], $conflicts);
    }

    public function testAnUnroutablePageHasNoRouteConflicts(): void
    {
        $current = ['id' => 5, 'title' => 'x', 'alias' => 'index', 'domain' => '', 'url' => '/index', 'path' => '/index', 'routable' => false];

        $this->assertSame([], PageUrlGuard::selectRouteConflicts($current, [
            ['id' => 6, 'title' => 'y', 'alias' => 'index', 'domain' => '', 'url' => '/index', 'path' => '/index', 'routable' => true],
        ]));
    }

    public function testASecondRootWithTheSameDomainAndPrefixIsRefused(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 132, 'type' => 'root', 'dns' => 'example.com', 'urlPrefix' => 'en']);
        $connection->method('fetchOne')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/example\.com.*en/');

        $this->guard($connection)->assertRootUnique(132);
    }

    public function testAnEmptyPrefixCountsAsAPrefix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 138, 'type' => 'root', 'dns' => 'example.com', 'urlPrefix' => '']);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('urlPrefix'), $this->callback(static fn (array $p): bool => '' === $p['urlPrefix'] && 'example.com' === $p['dns'] && 138 === $p['rootId']))
            ->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);

        $this->guard($connection)->assertRootUnique(138);
    }

    public function testAUniqueRootPasses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 138, 'type' => 'root', 'dns' => 'example.com', 'urlPrefix' => 'en']);
        $connection->method('fetchOne')->willReturn(0);

        $this->guard($connection)->assertRootUnique(138);
        $this->addToAssertionCount(1);
    }

    public function testASecondMainLanguageOnTheSameDomainIsRefused(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 150, 'type' => 'root', 'dns' => 'www.example.org', 'fallback' => 1]);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('fallback = 1'), ['dns' => 'www.example.org', 'rootId' => 150])
            ->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Root page 1 .*www\.example\.org.*page update 1 --set fallback=/s');

        $this->guard($connection)->assertSingleFallback(150);
    }

    public function testTheOnlyMainLanguagePasses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 150, 'type' => 'root', 'dns' => '', 'fallback' => 1]);
        $connection->method('fetchOne')->willReturn(false);

        $this->guard($connection)->assertSingleFallback(150);
        $this->addToAssertionCount(1);
    }

    /**
     * A clone is no main language unless the operator made it one, so the usual
     * second-language clone is not checked at all.
     */
    public function testARootThatIsNotTheMainLanguageIsNotChecked(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 150, 'type' => 'root', 'dns' => '', 'fallback' => 0]);
        $connection->expects($this->never())->method('fetchOne');

        $this->guard($connection)->assertSingleFallback(150);
    }

    /**
     * Inside the transaction, so a refusal rolls the clone back: after the clone and
     * before the closure returns its answer.
     */
    public function testTheClonerChecksTheMainLanguageBeforeTheCommit(): void
    {
        $code = PageCopyResetTest::codeOf(__DIR__ . '/../../../src/Service/Cloner/PageCloner.php');

        $transaction = strpos($code, '$this->connection->transactional(');
        $clone       = strpos($code, '$newRootId = $this->doClone(');
        $check       = strpos($code, '$this->pageUrlGuard->assertSingleFallback($newRootId);');
        $answer      = strpos($code, "'ignored_modifications' => \$ignoredMods");

        $this->assertNotFalse($transaction);
        $this->assertNotFalse($check);
        $this->assertNotFalse($answer);
        $this->assertLessThan($clone, $transaction);
        $this->assertLessThan($check, $clone);
        $this->assertLessThan($answer, $check);
    }

    public function testARegularPageIsNotCounted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 140, 'type' => 'regular', 'dns' => '', 'urlPrefix' => '']);
        $connection->expects($this->never())->method('fetchOne');

        $this->guard($connection)->assertRootUnique(140);
    }

    public function testContaosAliasCallbackIsFoundInTheDca(): void
    {
        $callbacks = [
            ['some.extension.listener', 'onSaveAlias'],
            ['contao.listener.data_container.page_url', 'generateAlias'],
        ];

        $this->assertSame(
            ['contao.listener.data_container.page_url', 'generateAlias'],
            PageUrlGuard::findAliasCallback($callbacks),
        );
    }

    public function testWithoutContaosAliasCallbackThereIsNothingToCall(): void
    {
        $this->assertNull(PageUrlGuard::findAliasCallback([['x', 'y'], static fn () => null]));
    }
}
