<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaOptionsCommand;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\OptionsResolver;

/**
 * `contao:dca:options <table> <field> [--set field=value]` — the values a field offers,
 * asked from the installation instead of a list in the CLI.
 *
 * Replaces the CLI's hard-coded page type list, which could not know types a bundle
 * registers (`consho_product` was missing on c5, 2026-09-16). See OptionsResolverTest.
 */
class DcaOptionsCommandTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function execute(OptionsResolver $resolver, array $input): array
    {
        $tester = new CommandTester(new DcaOptionsCommand($this->createMock(ContaoFramework::class), $resolver));
        $tester->execute($input);

        return json_decode($tester->getDisplay(), true);
    }

    public function testTheResolvedOptionsAndValuesAreReturned(): void
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->method('options')->willReturn(['regular' => 'Regular page', 'consho_product' => 'Product']);

        $out = $this->execute($resolver, ['table' => 'tl_page', 'field' => 'type']);

        $this->assertSame('ok', $out['status']);
        $this->assertSame(['regular', 'consho_product'], $out['values']);
        $this->assertSame('Product', $out['options']['consho_product']);
    }

    public function testSetValuesBecomeTheActiveRecord(): void
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->expects($this->once())
            ->method('options')
            ->with('tl_content', 'customTpl', ['type' => 'text'])
            ->willReturn(['content_element/text/conpai_hero' => 'text (conpai_hero)']);

        $out = $this->execute($resolver, ['table' => 'tl_content', 'field' => 'customTpl', '--set' => ['type=text']]);

        $this->assertSame(['content_element/text/conpai_hero'], $out['values']);
    }

    public function testAnUnresolvableFieldSaysWhy(): void
    {
        $resolver = $this->createMock(OptionsResolver::class);
        $resolver->method('options')->willReturn(null);
        $resolver->method('lastError')->willReturn('no request on the console');

        $out = $this->execute($resolver, ['table' => 'tl_member', 'field' => 'groups']);

        $this->assertSame('error', $out['status']);
        $this->assertStringContainsString('no request on the console', $out['message']);
    }
}
