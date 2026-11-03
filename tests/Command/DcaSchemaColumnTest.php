<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaSchemaCommand;

/**
 * `dca:schema` says which fields are columns of their own.
 *
 * A DCA field without `sql` has no column: a virtual field lives in a JSON
 * column, others are saved by a callback elsewhere. Listed without this flag,
 * both looked like columns, and SQL written from the answer named columns that
 * do not exist.
 */
class DcaSchemaColumnTest extends TestCase
{
    /**
     * @param array<string, mixed> $definition
     */
    private function hasColumn(array $definition): bool
    {
        $method = new \ReflectionMethod(DcaSchemaCommand::class, 'hasColumn');

        return $method->invoke(null, $definition);
    }

    /**
     * @dataProvider definitions
     *
     * @param array<string, mixed> $definition
     */
    public function testAColumnIsATruthySql(array $definition, bool $expected): void
    {
        $this->assertSame($expected, $this->hasColumn($definition));
    }

    /**
     * @return array<string, array{array<string, mixed>, bool}>
     */
    public static function definitions(): array
    {
        return [
            'sql string'       => [['sql' => "varchar(255) NOT NULL default ''"], true],
            'sql array'        => [['sql' => ['type' => 'string', 'length' => 255, 'default' => '']], true],
            'no sql'           => [['inputType' => 'text'], false],
            // As DcaExtractor checks it: an empty `sql` is no column either.
            'empty sql'        => [['sql' => ''], false],
            'null sql'         => [['sql' => null], false],
            'virtual field'    => [['inputType' => 'text', 'targetColumn' => 'jsonData'], false],
        ];
    }
}
