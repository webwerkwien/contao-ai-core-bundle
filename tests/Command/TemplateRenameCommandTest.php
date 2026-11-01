<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\TemplateRenameCommand;

/**
 * A template variant is renamed as Contao's Template Studio renames it.
 *
 * 🟡 **The gap:** templates are referenced by name, not by UUID. The only way to rename a
 * variant was `template write` under the new name and `template delete` of the old one —
 * and the delete resets every element using it to the default template.
 *
 * ## What Contao does (Template Studio, 5.7 and 6.0: `AbstractRenameVariantOperation`)
 *
 * 1. move the user template file; only the last segment of a variant changes
 * 2. drop compiled templates and refresh the hierarchy (TemplateCacheRefresher)
 * 3. give every record that named the variant the new name: `tl_content.customTpl`,
 *    `tl_module.customTpl`, `tl_layout.template`
 *
 * The bundle also writes a version for each record it changes, as for `template delete`.
 */
class TemplateRenameCommandTest extends TestCase
{
    /**
     * @return array<string, array{string, array{identifier: string, table: string, field: string}|null}>
     */
    public static function variants(): array
    {
        return [
            'content element variant'  => ['templates/content_element/text/hero.html.twig', ['identifier' => 'content_element/text/hero', 'table' => 'tl_content', 'field' => 'customTpl']],
            'front-end module variant' => ['templates/frontend_module/navigation/main.html.twig', ['identifier' => 'frontend_module/navigation/main', 'table' => 'tl_module', 'field' => 'customTpl']],
            'page variant'             => ['templates/page/layout/wide.html.twig', ['identifier' => 'page/layout/wide', 'table' => 'tl_layout', 'field' => 'template']],
            'deeper variant'           => ['templates/content_element/text/a/b.html.twig', ['identifier' => 'content_element/text/a/b', 'table' => 'tl_content', 'field' => 'customTpl']],
            'an override is no variant' => ['templates/content_element/text.html.twig', null],
            'theme folder'             => ['templates/mytheme/content_element/text/x.html.twig', null],
            'a component'              => ['templates/component/_figure/x.html.twig', null],
        ];
    }

    /**
     * @dataProvider variants
     *
     * @param array{identifier: string, table: string, field: string}|null $expected
     */
    public function testOnlyVariantsTheStudioRenamesAreRenamed(string $path, ?array $expected): void
    {
        $this->assertSame($expected, TemplateRenameCommand::variantReference($path));
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function names(): array
    {
        return [
            'plain'             => ['teaser', 'teaser'],
            'with the suffix'   => ['teaser.html.twig', 'teaser'],
            'underscore, dash'  => ['Teaser_wide-2', 'Teaser_wide-2'],
            'a folder'          => ['sub/teaser', null],
            'backslash'         => ['sub\\teaser', null],
            'a dot'             => ['teaser.v2', null],
            'leading dot'       => ['.teaser', null],
            'space'             => ['my teaser', null],
            'only the suffix'   => ['.html.twig', null],
            'empty'             => ['', null],
        ];
    }

    /**
     * @dataProvider names
     */
    public function testTheNewNameIsAVariantName(string $name, ?string $expected): void
    {
        $this->assertSame($expected, TemplateRenameCommand::variantName($name));
    }

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/template-rename-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/templates/content_element/text', 0777, true);
        file_put_contents($this->root . '/templates/content_element/text/hero.html.twig', '{% extends "@Contao/content_element/text.html.twig" %}');
        file_put_contents($this->root . '/templates/content_element/text/taken.html.twig', 'other');
        file_put_contents($this->root . '/templates/content_element/text.html.twig', 'override');
    }

    protected function tearDown(): void
    {
        foreach (['text/hero', 'text/taken', 'text/teaser', 'text'] as $file) {
            @unlink($this->root . '/templates/content_element/' . $file . '.html.twig');
        }
        @rmdir($this->root . '/templates/content_element/text');
        @rmdir($this->root . '/templates/content_element');
        @rmdir($this->root . '/templates');
        @rmdir($this->root);
    }

    /**
     * @return array<string, mixed>
     */
    private function refuse(string $path, string $name): array
    {
        $framework = $this->createMock(ContaoFramework::class);
        $framework->expects($this->never())->method('initialize');

        $tester = new CommandTester(new TemplateRenameCommand($framework, $this->createMock(Connection::class), $this->root));
        $tester->execute(['--path' => $path, '--name' => $name]);

        return json_decode($tester->getDisplay(), true);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusals(): array
    {
        return [
            'an override'      => ['templates/content_element/text.html.twig', 'teaser', 'no variant'],
            'outside'          => ['files/x.html.twig', 'teaser', 'below templates/'],
            'a folder in name' => ['templates/content_element/text/hero.html.twig', 'sub/teaser', 'variant name'],
            'missing'          => ['templates/content_element/text/gibtesnicht.html.twig', 'teaser', 'not found'],
            'same name'        => ['templates/content_element/text/hero.html.twig', 'hero', 'already has this name'],
            'taken'            => ['templates/content_element/text/hero.html.twig', 'taken', 'already exists'],
        ];
    }

    /**
     * Every refusal comes before the framework starts and leaves the files as they were.
     *
     * @dataProvider refusals
     */
    public function testARefusalChangesNothing(string $path, string $name, string $reason): void
    {
        $out = $this->refuse($path, $name);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString($reason, $out['message']);
        $this->assertStringContainsString('Nothing was renamed', $out['message']);
        $this->assertFileExists($this->root . '/templates/content_element/text/hero.html.twig');
        $this->assertSame('other', file_get_contents($this->root . '/templates/content_element/text/taken.html.twig'));
    }

    private int $versions = 0;

    /** @var list<array{string, array<string, mixed>, array<string, mixed>}> */
    private array $updates = [];

    /** @var list<array<string, mixed>> audit payloads */
    private array $logged = [];

    /**
     * @param list<int> $failOn IDs whose update throws
     */
    private function renamer(array $failOn): CommandTester
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnArgument(0);
        $connection->method('fetchFirstColumn')->willReturnCallback(function (string $sql, array $params): array {
            $this->assertSame('SELECT id FROM tl_content WHERE customTpl = ?', $sql);
            $this->assertSame(['content_element/text/hero'], $params);

            return ['7', '8'];
        });
        $connection->method('update')->willReturnCallback(function (string $table, array $data, array $criteria) use ($failOn): int {
            if (\in_array($criteria['id'], $failOn, true)) {
                throw new \RuntimeException('MySQL server has gone away');
            }
            $this->updates[] = [$table, $data, $criteria];

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

        $command = new TemplateRenameCommand($this->createMock(ContaoFramework::class), $connection, $this->root);
        $command->setVersionManager($versionManager);
        $command->setLogger($logger);

        return new CommandTester($command);
    }

    public function testEveryRecordUsingTheVariantFollowsTheNewNameWithAVersion(): void
    {
        $tester = $this->renamer([]);

        $tester->execute(['--path' => 'templates/content_element/text/hero.html.twig', '--name' => 'teaser']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('ok', $out['status'], $tester->getDisplay());
        $this->assertSame('templates/content_element/text/teaser.html.twig', $out['to']);
        $this->assertSame('content_element/text/teaser', $out['newIdentifier']);
        $this->assertSame(['table' => 'tl_content', 'field' => 'customTpl', 'ids' => [7, 8]], $out['migratedUsages']);
        $this->assertSame(2, $this->versions);
        $this->assertSame('content_element/text/teaser', $this->updates[0][1]['customTpl']);
        $this->assertSame(['id' => 8], $this->updates[1][2]);
        $this->assertFileDoesNotExist($this->root . '/templates/content_element/text/hero.html.twig');
        $this->assertFileExists($this->root . '/templates/content_element/text/teaser.html.twig');
    }

    public function testAnUpdateFailingAfterTheRenameIsNamedAndLogged(): void
    {
        $tester = $this->renamer([8]);

        $tester->execute(['--path' => 'templates/content_element/text/hero.html.twig', '--name' => 'teaser']);

        $out = json_decode($tester->getDisplay(), true);
        $this->assertSame('error', $out['status'], $tester->getDisplay());
        $this->assertStringContainsString('was renamed', $out['message']);
        $this->assertStringContainsString('tl_content 8', $out['message']);
        $this->assertStringContainsString("'content_element/text/teaser'", $out['message']);
        $this->assertSame(1, $this->versions);

        $this->assertCount(1, $this->logged);
        $this->assertSame([7], $this->logged[0]['migratedUsages']['ids']);
        $this->assertSame([8], $this->logged[0]['notMigrated']);
    }
}
