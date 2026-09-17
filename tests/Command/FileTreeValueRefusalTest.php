<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\StringUtil;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;

/**
 * A fileTree value is a UUID, or a list of them — never text that resolves to nothing.
 *
 * 🔴 Nr. 69, measured on 2026-09-17 on web.werk.wien (Contao 5.7.13, v0.23.0):
 * `layout update 2 --set 'external=["<uuid>"]'` answered ok and stored the JSON
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
        // Sixteen characters, the length of a binary UUID (review before v0.24.0).
        yield 'single 16-char path' => [['singleSRC' => 'files/logo12.svg'], 'files/logo12.svg'];
    }

    public function testAnEmptyJsonListClearsTheField(): void
    {
        $this->subject()->refuse(['external' => '[ ]']);

        $this->assertSame(['external' => ''], $this->subject()->convert(['external' => '[ ]']));
    }
}
