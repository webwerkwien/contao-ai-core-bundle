<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Service\Cloner;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Service\Cloner\DcaCopyReset;

/**
 * What a copied row does not take from its source, by the rules of DC_Table::copyChildren().
 */
class DcaCopyResetTest extends TestCase
{
    public function testDoNotCopyAndUniqueFieldsAreEmptied(): void
    {
        $fields = [
            'alias'  => ['eval' => ['doNotCopy' => true, 'unique' => true], 'sql' => "varchar(255) NOT NULL default ''"],
            'formID' => ['eval' => ['doNotCopy' => true], 'sql' => "varchar(64) NOT NULL default ''"],
            'sent'   => ['eval' => ['doNotCopy' => true], 'sql' => ['type' => 'boolean', 'default' => false]],
            'title'  => ['eval' => ['mandatory' => true], 'sql' => "varchar(255) NOT NULL default ''"],
        ];

        $values = DcaCopyReset::values(['id' => 4, 'alias' => 'kontakt', 'formID' => 'contact', 'sent' => '1', 'title' => 'Kontakt'], $fields);

        // `false` for the boolean column, as Contao's empty value; the cloner writes it as 0.
        $this->assertSame(['alias' => '', 'formID' => '', 'sent' => false], $values);
    }

    /**
     * `unique` on its own, without `doNotCopy` — as `tl_member.username` in Contao or an
     * extension's code field. Every other test field carries both, and removing the
     * `unique` branch went unnoticed (Fable review before v1.8.0).
     */
    public function testAUniqueFieldWithoutDoNotCopyIsEmptiedToo(): void
    {
        $fields = ['code' => ['default' => 'x', 'eval' => ['unique' => true], 'sql' => "varchar(32) NOT NULL default ''"]];

        // The empty value, not the default: Contao resets unique fields before it looks at defaults.
        $this->assertSame(['code' => ''], DcaCopyReset::values(['code' => 'A-17'], $fields));
    }

    public function testADoNotCopyFieldWithADefaultGetsTheDefault(): void
    {
        $fields = [
            'type'    => ['default' => 'text', 'eval' => ['doNotCopy' => true], 'sql' => "varchar(64) NOT NULL default ''"],
            'options' => ['default' => ['a', 'b'], 'eval' => ['fallback' => true], 'sql' => 'blob NULL'],
            'dynamic' => ['default' => static fn (): string => 'x', 'eval' => ['doNotCopy' => true], 'sql' => "varchar(8) NOT NULL default ''"],
        ];

        $values = DcaCopyReset::values(['type' => 'select', 'options' => 'x', 'dynamic' => 'y'], $fields);

        $this->assertSame('text', $values['type']);
        $this->assertSame(serialize(['a', 'b']), $values['options']);
        // A closure default needs a back-end DataContainer; the empty value instead.
        $this->assertSame('', $values['dynamic']);
    }

    public function testPasswordsAreNeverCopied(): void
    {
        $fields = ['password' => ['inputType' => 'password', 'sql' => "varchar(255) NOT NULL default ''"]];

        $this->assertSame(['password' => ''], DcaCopyReset::values(['password' => '$2y$13$hash'], $fields));
    }

    public function testFieldsWithoutSqlOrWithoutAColumnAreLeftAlone(): void
    {
        $fields = [
            'virtual' => ['eval' => ['doNotCopy' => true]],
            'missing' => ['eval' => ['doNotCopy' => true], 'sql' => "varchar(8) NOT NULL default ''"],
            'id'      => ['eval' => ['doNotCopy' => true], 'sql' => 'int(10) unsigned NOT NULL auto_increment'],
        ];

        // `missing` has no column in the row, `virtual` no sql, `id` is never copied anyway.
        $this->assertSame([], DcaCopyReset::values(['id' => 3, 'virtual' => 'v'], $fields));
    }
}
