<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Page;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Page\PageUrlGuard;

/**
 * A page write may not produce a URL Contao's back end refuses.
 *
 * 🔴 **Measured on 2026-09-16 on c5 (Contao 5.7.13, core-bundle v0.14.0)** in phase 4
 * of the ConpAI 1.0 acceptance test:
 *
 * | write | answer |
 * |---|---|
 * | `page update 132 --set urlPrefix=en` while root 138 on the same domain has `en` | `ok` — two roots `conpai.eu/en` |
 * | `page update 141 --set alias=packages` while 140 in the same branch has it | `ok` — two pages at `/en/packages` |
 * | `record clone` of a root | two roots `conpai.eu` without prefix, because `language`/`urlPrefix` were not accepted as modifications |
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

    public function testASecondRootWithTheSameDomainAndPrefixIsRefused(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 132, 'type' => 'root', 'dns' => 'conpai.eu', 'urlPrefix' => 'en']);
        $connection->method('fetchOne')->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/conpai\.eu.*en/');

        $this->guard($connection)->assertRootUnique(132);
    }

    public function testAnEmptyPrefixCountsAsAPrefix(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 138, 'type' => 'root', 'dns' => 'conpai.eu', 'urlPrefix' => '']);
        $connection->expects($this->once())
            ->method('fetchOne')
            ->with($this->stringContains('urlPrefix'), $this->callback(static fn (array $p): bool => '' === $p['urlPrefix'] && 'conpai.eu' === $p['dns'] && 138 === $p['rootId']))
            ->willReturn(1);

        $this->expectException(\InvalidArgumentException::class);

        $this->guard($connection)->assertRootUnique(138);
    }

    public function testAUniqueRootPasses(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 138, 'type' => 'root', 'dns' => 'conpai.eu', 'urlPrefix' => 'en']);
        $connection->method('fetchOne')->willReturn(0);

        $this->guard($connection)->assertRootUnique(138);
        $this->addToAssertionCount(1);
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
