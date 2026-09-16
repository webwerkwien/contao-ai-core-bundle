<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\VersionManager;

/**
 * A new record does not restore the history of an earlier record that had its ID.
 *
 * 🟡 **Measured on 2026-09-16 on c5 (Contao 5.7.13):** layout 25, created that day, had
 * versions 1–3 from **2026-08-31** — a deleted layout with the same ID. The database had
 * handed the ID out again. `tl_version` is keyed by table and ID only, so `version
 * restore --ver 2` would have written the deleted layout's data onto the new one.
 * `tl_undo` could not tell: its entries are purged after the undo period.
 *
 * Contao's back end shows the same history; the bundle can do better because it knows
 * when it creates a record. The first version of a created record carries
 * `description = VersionManager::CREATED`, and everything older belongs to another
 * record: `version list` marks it, `version restore` refuses it. For a record created
 * in the back end there is no marker and nothing changes.
 */
class RecordIdReuseTest extends TestCase
{
    public function testTheFirstVersionOfACreatedRecordIsMarkedAndEarlierOnesCounted(): void
    {
        $inserted   = null;
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchAssociative')->willReturn(['id' => 25, 'name' => 'ConpAI']);
        $connection->method('fetchOne')->willReturn(3);
        $connection->method('transactional')->willReturnCallback(static fn (callable $fn) => $fn());
        $connection->method('insert')->willReturnCallback(static function (string $table, array $data) use (&$inserted): int {
            $inserted = $data;

            return 1;
        });

        $earlier = (new VersionManager($connection))->createInitialVersion('tl_layout', 25, 'cli');

        $this->assertSame(3, $earlier);
        $this->assertSame(4, $inserted['version']);
        $this->assertSame(VersionManager::CREATED, $inserted['description']);
    }

    /**
     * @dataProvider versions
     */
    public function testAVersionBeforeTheCreationBelongsToAnEarlierRecord(?int $creation, int $version, bool $expected): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($creation ?? false);

        $this->assertSame($expected, (new VersionManager($connection))->belongsToEarlierRecord('tl_layout', 25, $version));
    }

    /**
     * @return array<string, array{?int, int, bool}>
     */
    public static function versions(): array
    {
        return [
            'the measured case'        => [4, 2, true],
            'the creation itself'      => [4, 4, false],
            'after the creation'       => [4, 7, false],
            'no marker (back end)'     => [null, 2, false],
        ];
    }

    public function testEveryCreatePathMarksTheFirstVersion(): void
    {
        $root = __DIR__ . '/../../src/';

        foreach (glob($root . 'Command/*CreateCommand.php') as $file) {
            $source = (string) file_get_contents($file);

            if (!str_contains($source, 'createVersion(') || str_contains(basename($file), 'VersionCreate')) {
                continue;
            }

            $this->assertMatchesRegularExpression('/createVersion\([^;]*created:\s*true/', $source, basename($file) . ' must mark the first version of the record it created');
        }

        foreach (glob($root . 'Service/Cloner/*.php') as $file) {
            $this->assertStringNotContainsString('->createVersion(', (string) file_get_contents($file), basename($file) . ' creates records — use createInitialVersion()');
        }
    }

    public function testRestoreRefusesAndListMarks(): void
    {
        $this->assertStringContainsString('belongsToEarlierRecord(', (string) file_get_contents(__DIR__ . '/../../src/Command/VersionRestoreCommand.php'));
        $this->assertStringContainsString("'before_creation'", (string) file_get_contents(__DIR__ . '/../../src/Command/VersionListCommand.php'));
    }
}
