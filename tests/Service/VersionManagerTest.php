<?php declare(strict_types=1);

namespace Webwerkwien\ContaoCliBridgeBundle\Tests\Service;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoCliBridgeBundle\Service\VersionManager;

class VersionManagerTest extends TestCase
{
    public function testAllowedTableAcceptsKnownTable(): void
    {
        $vm = new VersionManager($this->createMock(Connection::class));
        $this->assertTrue($vm->isAllowedTable('tl_article'));
        $this->assertTrue($vm->isAllowedTable('tl_page'));
        $this->assertTrue($vm->isAllowedTable('tl_user'));
    }

    public function testAllowedTableRejectsArbitraryTable(): void
    {
        $vm = new VersionManager($this->createMock(Connection::class));
        $this->assertFalse($vm->isAllowedTable("tl_user; DROP TABLE tl_user"));
        $this->assertFalse($vm->isAllowedTable('information_schema.tables'));
        $this->assertFalse($vm->isAllowedTable(''));
        $this->assertFalse($vm->isAllowedTable('tl_unknown_table'));
    }

    public function testLoadVersionDataReturnsFalseOnMissing(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn(false);
        $vm = new VersionManager($conn);
        $this->assertFalse($vm->loadVersionData('tl_article', 1, 99));
    }

    public function testLoadVersionDataReturnsArrayOnValidData(): void
    {
        $conn = $this->createMock(Connection::class);
        $payload = serialize(['id' => 1, 'title' => 'Test']);
        $conn->method('fetchAssociative')->willReturn(['data' => $payload]);
        $vm = new VersionManager($conn);
        $result = $vm->loadVersionData('tl_article', 1, 1);
        $this->assertIsArray($result);
        $this->assertSame('Test', $result['title']);
    }

    public function testLoadVersionDataRejectsMaliciousObjects(): void
    {
        $conn = $this->createMock(Connection::class);
        // Serialized stdClass object -- should not be returned as stdClass
        $malicious = serialize(new \stdClass());
        $conn->method('fetchAssociative')->willReturn(['data' => $malicious]);
        $vm = new VersionManager($conn);
        // stdClass serializes to object, not array -> returns false
        $result = $vm->loadVersionData('tl_article', 1, 1);
        $this->assertFalse($result);
    }

    public function testCreateVersionInsertsAndDeactivatesPrevious(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->once())
            ->method('fetchAssociative')
            ->willReturn(['id' => 1, 'title' => 'Test']);

        $conn->expects($this->once())
            ->method('fetchOne')
            ->willReturn('3');

        $conn->expects($this->once())
            ->method('executeStatement');

        $conn->expects($this->once())
            ->method('insert');

        $vm = new VersionManager($conn);
        $vm->createVersion('tl_article', 1);
    }

    public function testMarkActiveVersionCallsExecuteStatementTwice(): void
    {
        $conn = $this->createMock(Connection::class);

        $conn->expects($this->exactly(2))
            ->method('executeStatement');

        $vm = new VersionManager($conn);
        $vm->markActiveVersion('tl_article', 1, 2);
    }
}