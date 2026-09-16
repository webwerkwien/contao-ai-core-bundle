<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\ImageSizeUpdateCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;

/**
 * A custom template has to exist.
 *
 * 🟡 **Measured on 2026-09-16 on c5 (Contao 5.7.13)**: `content update 649 --set
 * customTpl=content_element/text/gibtesnicht` answered `{"status":"ok"}`. The element
 * then renders its default template, and nothing says why the variant is ignored.
 *
 * `options_callback` fields are not enforced in general, and that stays: of 106 such
 * fields many need a request or a user (see OptionValueRefusalTest). `customTpl` is the
 * exception, because its callback — Contao's `TemplateOptionsListener` — needs only the
 * element type, which the record being written carries. The check runs only when the
 * options could be resolved; an unresolvable list refuses nothing.
 */
class CustomTemplateRefusalTest extends TestCase
{
    private function subject(?array $resolvedValues): ImageSizeUpdateCommand
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->method('values')->willReturn($resolvedValues);

        $command = new ImageSizeUpdateCommand($this->createMock(ContaoFramework::class));
        $command->setOptionsResolver($resolver);

        return $command;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $record
     */
    private function refuse(ImageSizeUpdateCommand $command, array $fields, array $record = ['type' => 'text']): void
    {
        (new \ReflectionMethod($command, 'refuseUnknownTemplates'))->invoke($command, 'tl_content', $fields, $record);
    }

    public function testAnExistingVariantPasses(): void
    {
        $this->refuse($this->subject(['content_element/text', 'content_element/text/conpai_hero']), ['customTpl' => 'content_element/text/conpai_hero']);
        $this->addToAssertionCount(1);
    }

    public function testAMissingTemplateIsRefusedAndTheOptionsNamed(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/customTpl=content_element\/text\/gibtesnicht.*conpai_hero/s');

        $this->refuse($this->subject(['content_element/text', 'content_element/text/conpai_hero']), ['customTpl' => 'content_element/text/gibtesnicht']);
    }

    public function testClearingTheTemplatePasses(): void
    {
        $this->refuse($this->subject(['content_element/text']), ['customTpl' => '']);
        $this->addToAssertionCount(1);
    }

    public function testAnUnresolvableListRefusesNothing(): void
    {
        $this->refuse($this->subject(null), ['customTpl' => 'content_element/text/whatever']);
        $this->addToAssertionCount(1);
    }

    public function testConvertFieldsRunsTheCheck(): void
    {
        $this->assertStringContainsString(
            'refuseUnknownTemplates(',
            (string) file_get_contents(__DIR__ . '/../../src/Command/AbstractWriteCommand.php'),
        );
    }
}
