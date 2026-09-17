<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\TemplateDeleteCommand;

/**
 * A template file is deleted as Contao's Template Studio deletes it.
 *
 * 🟡 **Nr. 51 of the ConpAI 1.0 acceptance test, 2026-09-17:** cleaning up c5 after phase
 * 5, the variants `conpai_hero` and `consho_hero` could only be removed with `ssh rm` — the
 * CLI knew `template list`, `read`, `write` and nothing else.
 *
 * ## What Contao does (Template Studio, 5.7: `DeleteOperation`, `AbstractDeleteVariantOperation`)
 *
 * 1. delete the user template file
 * 2. drop compiled templates and refresh the hierarchy (TemplateCacheRefresher)
 * 3. for a variant of a content element or front-end module: set `customTpl` of every
 *    record that used it to `''`, so it renders the default template again
 *    (`tl_content.customTpl`, `tl_module.customTpl`)
 *
 * The bundle also writes a version for each record it changes — Contao's Studio updates
 * them through the connection without one. Contao 5.3 has no Template Studio; the rules
 * are rebuilt here, not called.
 */
class TemplateDeleteCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null}>
     */
    public static function paths(): array
    {
        return [
            'variant'            => ['templates/content_element/text/consho_hero.html.twig', 'templates/content_element/text/consho_hero.html.twig'],
            'double slash, ./'   => ['templates//content_element/./text/x.html.twig', 'templates/content_element/text/x.html.twig'],
            'backslashes'        => ['templates\\frontend_module\\navigation\\x.html.twig', 'templates/frontend_module/navigation/x.html.twig'],
            'override'           => ['templates/content_element/text.html.twig', 'templates/content_element/text.html.twig'],
            'traversal'          => ['templates/../config/config.yaml', null],
            'outside templates'  => ['files/x.html.twig', null],
            'not a twig file'    => ['templates/content_element/text/x.html5', null],
            'the folder itself'  => ['templates', null],
        ];
    }

    /**
     * @dataProvider paths
     */
    public function testThePathIsCanonicalAndATwigTemplateBelowTemplates(string $path, ?string $expected): void
    {
        $this->assertSame($expected, TemplateDeleteCommand::canonicalPath($path));
    }

    /**
     * @return array<string, array{string, array{identifier: string, table: string}|null}>
     */
    public static function variants(): array
    {
        return [
            'content element variant' => ['templates/content_element/text/consho_hero.html.twig', ['identifier' => 'content_element/text/consho_hero', 'table' => 'tl_content']],
            'front-end module variant' => ['templates/frontend_module/navigation/main.html.twig', ['identifier' => 'frontend_module/navigation/main', 'table' => 'tl_module']],
            'an override is no variant' => ['templates/content_element/text.html.twig', null],
            // AbstractDeleteVariantOperation::canExecute() takes `<prefix>/[^/]+/.+`, and the
            // Finder offers such names as variants (Fable review before v0.21.0).
            'deeper variant'           => ['templates/content_element/text/a/b.html.twig', ['identifier' => 'content_element/text/a/b', 'table' => 'tl_content']],
            'theme folder'             => ['templates/mytheme/content_element/text/x.html.twig', null],
            'page variant'             => ['templates/page/regular/x.html.twig', null],
        ];
    }

    /**
     * @dataProvider variants
     *
     * @param array{identifier: string, table: string}|null $expected
     */
    public function testOnlyVariantsContaoMigratesHaveUsagesToReset(string $path, ?array $expected): void
    {
        $this->assertSame($expected, TemplateDeleteCommand::variantReference($path));
    }

    public function testAMissingTemplateIsRefusedBeforeAnythingElse(): void
    {
        $root = sys_get_temp_dir() . '/template-delete-' . bin2hex(random_bytes(4));
        mkdir($root . '/templates', 0777, true);

        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new TemplateDeleteCommand($framework, $this->createMock(Connection::class), $root));
        $tester->execute(['--path' => 'templates/content_element/text/gibtesnicht.html.twig']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('not found', $out['message']);

        rmdir($root . '/templates');
        rmdir($root);
    }

    private int $versions = 0;

    /** @var list<array<string, mixed>> audit payloads */
    private array $logged = [];

    /**
     * @param list<int> $failOn IDs whose update throws
     *
     * @return array{0: CommandTester, 1: string}
     */
    private function variantSetup(array $failOn): array
    {
        $root = sys_get_temp_dir() . '/template-delete-' . bin2hex(random_bytes(4));
        mkdir($root . '/templates/content_element/text', 0777, true);
        file_put_contents($root . '/templates/content_element/text/hero.html.twig', '{% extends "@Contao/content_element/text.html.twig" %}');

        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchFirstColumn')->willReturn(['7', '8']);
        $connection->method('update')->willReturnCallback(static function (string $table, array $data, array $criteria) use ($failOn): int {
            if (\in_array($criteria['id'], $failOn, true)) {
                throw new \RuntimeException('MySQL server has gone away');
            }

            return 1;
        });

        $versionManager = $this->createMock(\Webwerkwien\ContaoAiCoreBundle\Service\VersionManager::class);
        $versionManager->method('createVersion')->willReturnCallback(function (): void {
            ++$this->versions;
        });

        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        $logger->method('info')->willReturnCallback(function (string $message, array $context): void {
            $this->logged[] = $context['payload'];
        });

        $command = new TemplateDeleteCommand($this->createMock(ContaoFramework::class), $connection, $root);
        $command->setVersionManager($versionManager);
        $command->setLogger($logger);

        return [new CommandTester($command), $root];
    }

    private function removeTree(string $root): void
    {
        @unlink($root . '/templates/content_element/text/hero.html.twig');
        rmdir($root . '/templates/content_element/text');
        rmdir($root . '/templates/content_element');
        rmdir($root . '/templates');
        rmdir($root);
    }

    public function testEveryRecordUsingADeletedVariantIsResetWithAVersion(): void
    {
        [$tester, $root] = $this->variantSetup([]);

        $tester->execute(['--path' => 'templates/content_element/text/hero.html.twig']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status'], $tester->getDisplay());
        $this->assertSame(['table' => 'tl_content', 'field' => 'customTpl', 'ids' => [7, 8]], $out['migratedUsages']);
        $this->assertSame(2, $this->versions);
        $this->assertFileDoesNotExist($root . '/templates/content_element/text/hero.html.twig');

        $this->removeTree($root);
    }

    /**
     * Fable review before v0.21.0: the file was gone when the reset failed, and the answer
     * was a bare exception message with no log entry — unlike file delete.
     */
    public function testAResetFailingAfterTheFileIsGoneIsNamedAndLogged(): void
    {
        [$tester, $root] = $this->variantSetup([8]);

        $tester->execute(['--path' => 'templates/content_element/text/hero.html.twig']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status'], $tester->getDisplay());
        $this->assertStringContainsString('was deleted', $out['message']);
        $this->assertStringContainsString('tl_content 8', $out['message']);
        $this->assertStringContainsString('gone away', $out['message']);
        $this->assertSame(1, $this->versions);
        $this->assertFileDoesNotExist($root . '/templates/content_element/text/hero.html.twig');

        $this->assertCount(1, $this->logged);
        $this->assertSame([7], $this->logged[0]['migratedUsages']['ids']);
        $this->assertSame([8], $this->logged[0]['notReset']);

        $this->removeTree($root);
    }
}
