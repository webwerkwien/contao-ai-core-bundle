<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\PageReadCommand;

/**
 * fileTree fields must come back out as UUID strings.
 *
 * Found on 2026-08-29 while chasing a CLI crash. `contao:page:read 98` returned
 *
 *     "navigationImage": "GW\u{FFFD}V+8\x11\u{FFFD}\u{FFFD}\0\0(\u{FFFD}T"
 *
 * Contao stores a file reference as a 16-byte binary UUID, the read path handed
 * that straight to json_encode(), and JSON_INVALID_UTF8_SUBSTITUTE turned every
 * byte that is not valid UTF-8 into U+FFFD. The value is destroyed there, in
 * PHP — not in the transport and not in the database, which both had it intact.
 *
 * Two consequences. The obvious one: a caller cannot tell which file a record
 * points at, so an agent reading a page learns nothing about its image. The one
 * that actually surfaced first: those replacement characters travel on to the
 * client, where a cp1252 stdout cannot print them and the command dies.
 *
 * The write path has converted UUID strings to binary since v0.2.1
 * (AbstractWriteCommand::convertFileTreeFields). This is the missing inverse.
 */
class FileTreeReadConversionTest extends TestCase
{

    private const UUID = '4e2fbd7a-6d3e-11ef-9b1a-0242ac120002';

    private function command(): PageReadCommand
    {
        return new PageReadCommand($this->createMock(ContaoFramework::class));
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     */
    private function seedDca(array $fields): void
    {
        $GLOBALS['TL_DCA']['tl_page']['fields'] = $fields;
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_page']);
        parent::tearDown();
    }

    public function testASingleBinaryUuidBecomesItsStringForm(): void
    {
        $this->seedDca(['navigationImage' => ['inputType' => 'fileTree']]);

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_page', [
            'id'              => 98,
            'navigationImage' => StringUtil::uuidToBin(self::UUID),
        ]);

        $this->assertSame(self::UUID, $row['navigationImage']);
    }

    public function testTheResultIsValidUtf8(): void
    {
        $this->seedDca(['navigationImage' => ['inputType' => 'fileTree']]);

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_page', [
            'navigationImage' => StringUtil::uuidToBin(self::UUID),
        ]);

        $json = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $this->assertIsString($json);
        $this->assertStringNotContainsString("\u{FFFD}", $json, 'A substituted byte is a destroyed value.');
    }

    public function testAMultipleFieldBecomesAListOfUuids(): void
    {
        $this->seedDca(['multiSRC' => ['inputType' => 'fileTree', 'eval' => ['multiple' => true]]]);
        $second = '5f3acb81-6d3e-11ef-9b1a-0242ac120002';

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_page', [
            'multiSRC' => serialize([
                StringUtil::uuidToBin(self::UUID),
                StringUtil::uuidToBin($second),
            ]),
        ]);

        $this->assertSame([self::UUID, $second], $row['multiSRC']);
    }

    public function testNonFileTreeFieldsAreUntouched(): void
    {
        $this->seedDca(['title' => ['inputType' => 'text']]);

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_page', ['title' => 'Balbersteine']);

        $this->assertSame('Balbersteine', $row['title']);
    }

    /**
     * A page without an image stores an empty string, not a UUID. Converting it
     * would invent a reference that is not there.
     *
     * @dataProvider emptyValues
     */
    public function testEmptyValuesStayEmpty(mixed $stored): void
    {
        $this->seedDca(['navigationImage' => ['inputType' => 'fileTree']]);

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_page', ['navigationImage' => $stored]);

        $this->assertSame($stored, $row['navigationImage']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function emptyValues(): iterable
    {
        yield 'empty string' => [''];
        yield 'null'         => [null];
    }

    public function testAValueThatIsAlreadyAStringUuidIsLeftAlone(): void
    {
        $this->seedDca(['navigationImage' => ['inputType' => 'fileTree']]);

        $row = $this->command()->convertFileTreeFieldsToUuid('tl_page', ['navigationImage' => self::UUID]);

        $this->assertSame(self::UUID, $row['navigationImage'], 'Defensive against a re-run.');
    }
}
