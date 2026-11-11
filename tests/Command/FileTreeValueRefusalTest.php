<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A fileTree value is a UUID, or a list of them — never text that resolves to nothing.
 *
 * 🔴 Measured on Contao 5.7.13 with v0.23.0:
 * `layout update <id> --set 'external=["<uuid>"]'` answered ok and stored the JSON
 * text. `layout read` answers that field as exactly such a JSON list, so the read
 * value could not be written back.
 */
class FileTreeValueRefusalTest extends TestCase
{
    private const A = '7cb2d18f-b29d-11f1-b9e4-525400338fcc';
    private const B = 'b4559fa5-b29d-11f1-b9e4-525400338fcc';

    protected function setUp(): void
    {
        $GLOBALS['TL_DCA']['tl_test']['fields'] = [
            'external'  => ['inputType' => 'fileTree', 'eval' => ['multiple' => true]],
            'singleSRC' => ['inputType' => 'fileTree'],
            'title'     => ['inputType' => 'text'],
        ];
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['TL_DCA']['tl_test']);
    }

    private function subject(): object
    {
        return new class($this->createMock(ContaoFramework::class)) extends ImageSizeUpdateCommand {
            public function refuse(array $fields): void
            {
                $this->refuseInvalidFileTreeValues('tl_test', $fields);
            }

            public function convert(array $fields): array
            {
                return $this->convertFileTreeFields('tl_test', $fields);
            }
        };
    }

    public function testAJsonListIsConvertedLikeTheCommaForm(): void
    {
        $json  = $this->subject()->convert(['external' => '["' . self::A . '", "' . self::B . '"]']);
        $comma = $this->subject()->convert(['external' => self::A . ',' . self::B]);

        $this->assertSame(serialize([StringUtil::uuidToBin(self::A), StringUtil::uuidToBin(self::B)]), $json['external']);
        $this->assertSame($comma['external'], $json['external']);
    }

    /**
     * @dataProvider accepted
     */
    public function testValidValuesPass(array $fields): void
    {
        $this->subject()->refuse($fields);
        $this->addToAssertionCount(1);
    }

    public static function accepted(): iterable
    {
        yield 'json list'        => [['external' => '["' . self::A . '"]']];
        yield 'comma list'       => [['external' => self::A . ', ' . self::B]];
        yield 'serialized'       => [['external' => serialize([StringUtil::uuidToBin(self::A)])]];
        yield 'empty list field' => [['external' => '']];
        yield 'single uuid'      => [['singleSRC' => self::A]];
        yield 'single binary'    => [['singleSRC' => StringUtil::uuidToBin(self::A)]];
        yield 'other field'      => [['title' => 'not-a-uuid']];
    }

    /**
     * @dataProvider refused
     */
    public function testValuesThatResolveToNoFileAreRefused(array $fields, string $named): void
    {
        try {
            $this->subject()->refuse($fields);
            $this->fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString($named, $e->getMessage());
            $this->assertStringContainsString('Nothing was written', $e->getMessage());
        }
    }

    public static function refused(): iterable
    {
        yield 'path in a list'      => [['external' => '["files/site.css"]'], 'files/site.css'];
        yield 'half-wrong comma'    => [['external' => self::A . ',site.css'], 'site.css'];
        yield 'half-wrong json'     => [['external' => '["' . self::A . '", "x"]'], 'external ("x")'];
        yield 'single path'         => [['singleSRC' => 'files/logo.svg'], 'singleSRC'];
        // Sixteen characters, the length of a binary UUID (since v0.24.0).
        yield 'single 16-char path' => [['singleSRC' => 'files/logo12.svg'], 'files/logo12.svg'];
        // Serialized text Contao reads differently from its comma parts (since v1.16.2).
        $serialized = 'external (serialized, but not a plain list of file UUIDs)';
        yield 'serialized, uuid after it'     => [['external' => 'a:0:{},' . self::A], $serialized];
        yield 'serialized, uuid in a key'     => [['external' => 'a:1:{s:38:",' . self::A . ',";s:0:"";}'], $serialized];
        yield 'serialized, uuid in a string'  => [['external' => serialize([',' . self::A])], $serialized];
        yield 'serialized map'                => [['external' => serialize([3 => StringUtil::uuidToBin(self::A)])], $serialized];
        yield 'serialized path'               => [['external' => serialize(['files/site.css'])], $serialized];
        yield 'serialized 16-char path'       => [['external' => serialize(['files/logo12.svg'])], $serialized];
        yield 'serialized non-string'         => [['external' => serialize([1, StringUtil::uuidToBin(self::A)])], $serialized];
        // "$" alone also matches before a final newline; hex2bin() then fails.
        yield 'serialized, newline after uuid' => [['external' => serialize([self::A . "\n"])], $serialized];
    }

    /**
     * Converting leaves a refused serialized value whole, should it ever get
     * there: split at commas, "a:0:{},<uuid>" would store the UUID.
     */
    public function testARefusedSerializedValueIsNotSplitByTheConversion(): void
    {
        $value = 'a:0:{},' . self::A;

        $this->assertSame(['external' => $value], $this->subject()->convert(['external' => $value]));
    }

    /**
     * Converting never splits serialized text at commas: Contao reads it whole.
     */
    public function testAStoredListIsWrittenAsItIs(): void
    {
        // 0x2c is a comma: a binary UUID can contain one.
        $stored = serialize([
            StringUtil::uuidToBin('2c2c2c2c-b29d-11f1-b9e4-525400338fcc'),
            StringUtil::uuidToBin(self::B),
        ]);

        $this->subject()->refuse(['external' => $stored]);
        $this->assertSame(['external' => $stored], $this->subject()->convert(['external' => $stored]));
    }

    public function testASerializedListOfUuidTextIsStoredBinary(): void
    {
        $value = serialize([self::A, strtoupper(self::B)]);

        $this->subject()->refuse(['external' => $value]);
        $this->assertSame(
            ['external' => serialize([StringUtil::uuidToBin(self::A), StringUtil::uuidToBin(self::B)])],
            $this->subject()->convert(['external' => $value]),
        );
    }

    public function testAnEmptyJsonListClearsTheField(): void
    {
        $this->subject()->refuse(['external' => '[ ]']);

        $this->assertSame(['external' => ''], $this->subject()->convert(['external' => '[ ]']));
    }
}
