<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Dca;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\TableName;

/**
 * A name, not a prefix. Until v1.9.2 the commands demanded `tl_`, and the
 * Catalog Manager refuses exactly that prefix for its catalog tables — so every
 * one of them was rejected as "Invalid table name".
 */
class TableNameTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function names(): array
    {
        return [
            'a core table'            => ['tl_news'],
            'a Catalog Manager table' => ['ai_produkte'],
            'another prefix'          => ['mm_cars'],
            'mixed case'              => ['tl_Consho_Product'],
            '64 characters'           => [str_repeat('a', 64)],
        ];
    }

    #[DataProvider('names')]
    public function testANameIsValid(string $table): void
    {
        $this->assertTrue(TableName::isValid($table));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notNames(): array
    {
        return [
            'empty'            => [''],
            'SQL in the name'  => ['tl_user; DROP TABLE tl_user'],
            'schema-qualified' => ['information_schema.tables'],
            'a space'          => ['ai produkte'],
            'a hyphen'         => ['ai-produkte'],
            'a backtick'       => ['tl_news`'],
            '65 characters'    => [str_repeat('a', 65)],
        ];
    }

    #[DataProvider('notNames')]
    public function testAnythingElseIsNot(string $table): void
    {
        $this->assertFalse(TableName::isValid($table));
    }
}
