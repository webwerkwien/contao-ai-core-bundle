<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\CopiesSourceRows;

/**
 * Pins the fix for a silent tinyint truncation.
 *
 * Doctrine DBAL maps every MySQL `tinyint` to Types::BOOLEAN regardless of the
 * declared length (AbstractMySQLPlatform: 'tinyint' => Types::BOOLEAN), so
 * Contao's Model::convertToPhpValue() hands out `true` for a stored `2`. A
 * cloner copying through Model::row() therefore never sees the real value —
 * it writes 1 back. `0` survives because `false` casts to 0, which is why the
 * damage looks like "everything >= 1 becomes 1".
 *
 * The cloners must copy from the raw database row instead. These tests cover
 * the two halves of that: reading the row unconverted, and copying it verbatim.
 */
class CopiesSourceRowsTest extends TestCase
{
    private function subject(Connection $connection): object
    {
        return new class ($connection) {
            use CopiesSourceRows;

            public function __construct(private readonly Connection $connection)
            {
            }

            /**
             * @return array<string, mixed>
             */
            public function readRow(string $table, int $id): array
            {
                return $this->fetchSourceRow($table, $id);
            }

            /**
             * @param array<string, mixed> $row
             */
            public function copyRow(object $target, array $row): void
            {
                $this->copySourceRow($target, $row);
            }
        };
    }

    public function testReturnsNumericTinyintValuesUnconverted(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );
        $connection->expects($this->once())
            ->method('fetchAssociative')
            ->with('SELECT * FROM `tl_page` WHERE id = ?', [42])
            ->willReturn(['id' => '42', 'duration' => '2', 'level' => '2', 'stops' => '0']);

        $row = $this->subject($connection)->readRow('tl_page', 42);

        // The whole point: 2 stays 2. Through Model::row() this would be `true`.
        $this->assertSame('2', $row['duration']);
        $this->assertSame('2', $row['level']);
        $this->assertSame('0', $row['stops']);
    }

    public function testThrowsWhenTheSourceRowIsGone(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $name): string => '`' . $name . '`'
        );
        $connection->method('fetchAssociative')->willReturn(false);

        // A refusal, not a defect: JsonErrorBoundary::defectOf() tells them apart by this (v1.3.0).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('tl_page');

        $this->subject($connection)->readRow('tl_page', 4711);
    }

    public function testCopiesEveryValueVerbatimButSkipsTheId(): void
    {
        $target = new \stdClass();

        $this->subject($this->createMock(Connection::class))->copyRow($target, [
            'id'       => '42',
            'duration' => '2',
            'level'    => '4',
            'capacity' => '350',
            'title'    => 'About us',
            'nullable' => null,
        ]);

        $this->assertFalse(property_exists($target, 'id'), 'The id must never be carried over to a clone.');
        $this->assertSame('2', $target->duration);
        $this->assertSame('4', $target->level);
        $this->assertSame('350', $target->capacity);
        $this->assertSame('About us', $target->title);
        $this->assertNull($target->nullable);
    }

    /**
     * Structural guard. The bug was not in any single cloner but in the shared
     * habit of copying through Model::row(); a new cloner written from the old
     * template would reintroduce it silently. Fails loudly instead.
     */
    public function testNoClonerCopiesThroughTheModelRow(): void
    {
        $dir = \dirname(__DIR__, 3) . '/src/Service/Cloner';
        $offenders = [];

        foreach (glob($dir . '/*Cloner.php') ?: [] as $file) {
            if (str_contains((string) file_get_contents($file), '->row() as $key => $value')) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Cloners must copy from fetchSourceRow(), not Model::row() — see the tinyint truncation in the class docblock.'
        );
    }
}
