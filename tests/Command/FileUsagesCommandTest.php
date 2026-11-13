<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\FileUsagesCommand;

/**
 * The refusals of contao:file:usages. The search itself is FileUsageFinder's and is
 * verified there and live.
 */
class FileUsagesCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function execute(array $input, ?Connection $connection = null): array
    {
        $tester = new CommandTester(new FileUsagesCommand(
            $this->createMock(ContaoFramework::class),
            $connection ?? $this->createMock(Connection::class),
            sys_get_temp_dir() . '/file-usages-test-' . bin2hex(random_bytes(4)),
        ));
        $tester->execute($input);

        return json_decode($tester->getDisplay(), true);
    }

    public function testMissingPathReturnsAnError(): void
    {
        $out = $this->execute([]);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('--path', $out['message']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pathsOutsideFiles(): iterable
    {
        yield 'parent segment' => ['files/../config/parameters.yml'];
        yield 'outside files' => ['templates/x.html.twig'];
        yield 'files itself' => ['files'];
    }

    #[DataProvider('pathsOutsideFiles')]
    public function testPathsOutsideFilesAreRefusedBeforeAnyQuery(string $path): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects($this->never())->method('fetchAllAssociative');

        $out = $this->execute(['--path' => $path], $connection);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('files/', $out['message']);
    }

    public function testAPathNeitherInTheDbafsNorOnDiskIsNotFound(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAllAssociative')->willReturn([]);

        $out = $this->execute(['--path' => 'files/none.jpg'], $connection);

        $this->assertSame('error', $out['status']);
        $this->assertSame('Not found: files/none.jpg', $out['message']);
    }
}
