<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\LanguageVersionsCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Language\LanguageVersions;
use Webwerkwien\ContaoAiCoreBundle\Tests\Service\Language\LanguageVersionsTest;

/**
 * contao:language:versions (v1.11.0) over the SQLite site of LanguageVersionsTest.
 */
class LanguageVersionsCommandTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function versions(string $table, int $id): array
    {
        $db = LanguageVersionsTest::database();
        $tester = new CommandTester(new LanguageVersionsCommand(new LanguageVersions($db), $db));
        $tester->execute(['table' => $table, 'id' => (string) $id]);

        return json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testTheLoginArticleNamesItsGermanVersion(): void
    {
        $answer = self::versions('tl_article', 36);

        self::assertSame('ok', $answer['status']);
        self::assertSame('tl_article', $answer['table']);
        self::assertSame('en', $answer['record']['language']);
        self::assertSame([102], array_column($answer['versions'], 'id'));
        self::assertSame([76, 50], array_column($answer['otherRoots'], 'id'), 'the own root is not among the others');
        self::assertArrayNotHasKey('note', $answer);
    }

    public function testNothingLinkedSaysThatAVersionMayStillExist(): void
    {
        $answer = self::versions('tl_page', 90);

        self::assertFalse($answer['linked']);
        self::assertStringContainsString('do not say there is none', $answer['note']);
        self::assertSame([1, 50], array_column($answer['otherRoots'], 'id'));
    }

    public function testARootIsRefusedWithTheReason(): void
    {
        $answer = self::versions('tl_page', 76);

        self::assertSame('error', $answer['status']);
        self::assertStringContainsString('languageRoot', $answer['message']);
    }

    public function testATableTheSiteDoesNotHaveIsRefused(): void
    {
        $answer = self::versions('tl_faq', 1);

        self::assertSame('error', $answer['status']);
        self::assertStringContainsString('not installed', $answer['message']);
    }

    public function testAnUnknownTableIsRefused(): void
    {
        $answer = self::versions('tl_content', 1);

        self::assertSame('error', $answer['status']);
        self::assertStringContainsString('tl_page, tl_article', $answer['message']);
    }

    public function testAMissingRecordIsAnError(): void
    {
        self::assertSame('error', self::versions('tl_page', 4711)['status']);
    }
}
