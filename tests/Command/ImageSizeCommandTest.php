<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\ImageSizeModel;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeCreateCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeDeleteCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeReadCommand;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;
use Webwerkwien\ContaoAiCoreBundle\Tests\NeedsContaoContainerTrait;

/**
 * The theme layer, first entity.
 *
 * Image sizes were unreachable in either direction until 2026-08-31. Reading
 * came first, through the generic record group; this is the write half.
 *
 * Update and delete are six-line subclasses on purpose — they inherit
 * versioning, the system log, `--ids` and the cascade from the abstract bases,
 * and adding per-entity code would only be a place for those to drift apart.
 * The tests here therefore cover what is actually specific: the create
 * command's own argument handling, and that each command is bound to the right
 * model.
 */
class ImageSizeCommandTest extends TestCase
{
    use NeedsContaoContainerTrait;

    private function fw(): ContaoFramework
    {
        return $this->createMock(ContaoFramework::class);
    }

    private function creator(): ImageSizeCreateCommand
    {
        $cmd = new ImageSizeCreateCommand($this->fw());
        $cmd->setLogger($this->createMock(LoggerInterface::class));
        $cmd->setVersionManager($this->createMock(VersionManager::class));

        return $cmd;
    }

    /**
     * @return array<string, mixed>
     */
    private function runCreate(array $input): array
    {
        $tester = new CommandTester($this->creator());
        $tester->execute($input);

        $decoded = json_decode($tester->getDisplay(), true);
        $this->assertIsArray($decoded, 'The command must answer with JSON.');

        return $decoded;
    }

    // --- create: its own arguments ---

    public function testCreateRefusesAMissingName(): void
    {
        $out = $this->runCreate(['--pid' => '1']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--name', $out['message']);
    }

    /**
     * `tl_image_size.ptable` is `tl_theme`. A size that belongs to no theme is
     * not something Contao has, so this is a refusal rather than a default.
     */
    public function testCreateRefusesAMissingTheme(): void
    {
        $out = $this->runCreate(['--name' => 'Tourenbild']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--pid', $out['message']);
    }

    /**
     * @dataProvider badThemeIds
     */
    public function testCreateRefusesAThemeIdThatIsNotOne(string $pid): void
    {
        $out = $this->runCreate(['--name' => 'Tourenbild', '--pid' => $pid]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--pid', $out['message']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badThemeIds(): iterable
    {
        yield 'zero'        => ['0'];
        yield 'a word'      => ['default'];
        yield 'a decimal'   => ['1.5'];
        yield 'a negative'  => ['-1'];
    }

    // --- the model binding ---

    /**
     * @dataProvider imageSizeCommands
     */
    public function testTheCommandIsBoundToTheImageSizeModel(string $class): void
    {
        $command = new $class($this->fw());
        $method  = new \ReflectionMethod($class, 'modelClass');

        $this->assertSame(ImageSizeModel::class, $method->invoke($command));
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function imageSizeCommands(): iterable
    {
        yield 'read'   => [ImageSizeReadCommand::class];
        yield 'update' => [ImageSizeUpdateCommand::class];
        yield 'delete' => [ImageSizeDeleteCommand::class];
    }

    /**
     * The whole reason update and delete can stay six lines long: they inherit
     * the bases that carry versioning, the system log and the cascade. If that
     * inheritance is ever broken, these commands silently lose an audit trail
     * rather than fail, which is the worst way for it to go.
     */
    public function testUpdateAndDeleteInheritTheSharedWritePath(): void
    {
        $this->assertInstanceOf(
            \Webwerkwien\ContaoAiCoreBundle\Command\AbstractModelUpdateCommand::class,
            new ImageSizeUpdateCommand($this->fw()),
        );
        $this->assertInstanceOf(
            \Webwerkwien\ContaoAiCoreBundle\Command\AbstractModelDeleteCommand::class,
            new ImageSizeDeleteCommand($this->fw()),
        );
    }

    public function testCreateAcceptsSizesAndDensitiesThroughSet(): void
    {
        $this->skipWithoutContaoContainer();

        $out = $this->runCreate([
            '--name' => 'PHPUnit Tourenbild',
            '--pid'  => '1',
            '--set'  => ['width=1600', 'sizes=(max-width: 1100px) 100vw, 1000px'],
        ]);

        $this->assertSame('ok', $out['status']);
        $this->assertSame('PHPUnit Tourenbild', $out['name']);
        $this->assertGreaterThan(0, $out['id']);
    }
}
