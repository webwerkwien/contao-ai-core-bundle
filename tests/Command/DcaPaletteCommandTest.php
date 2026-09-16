<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Tests\Command;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoAiCoreBundle\Command\DcaPaletteCommand;

/**
 * `contao:dca:palette <table> --set type=root [--set enableCsp=1]` — the fields a record
 * of that kind actually has, and which of them are mandatory.
 *
 * 🟡 **Measured on 2026-09-16 on c5 (Contao 5.7.13):** `schema mandatory tl_page` listed
 * 13 fields for every page type together — `url` (only redirects), `twoFactorJumpTo`
 * (only with two-factor enabled), `csp` (only with `enableCsp`), `newsArchives`,
 * `eventCalendars`, `conshoPathTemplate` — and a caller creating a root page could not
 * tell which applied.
 *
 * The palette is Contao's own: `DataContainer::getPalette()` with a DataContainer that
 * answers the `--set` values as the record (RecordDataContainer). That runs the
 * `PaletteBuilder` in 5.7 and 6.0 and the inline code of 5.3 — selectors, sub-palettes,
 * `onpalette_callback` included. Pinned here: reading a palette string into fields and
 * picking the mandatory ones.
 */
class DcaPaletteCommandTest extends TestCase
{
    public function testAPaletteStringBecomesItsFields(): void
    {
        $palette = '{title_legend},title,type;{meta_legend},pageTitle,language;'
            . '{global_legend:hide},dns,fallback;{csp_legend},enableCsp,[enableCsp],csp,cspReportOnly,[EOF];{publish_legend},published';

        $this->assertSame(
            ['title', 'type', 'pageTitle', 'language', 'dns', 'fallback', 'enableCsp', 'csp', 'cspReportOnly', 'published'],
            DcaPaletteCommand::fieldsOf($palette),
        );
    }

    public function testOnlyTheMandatoryFieldsOfThePaletteAreMandatory(): void
    {
        $dca = [
            'title'           => ['eval' => ['mandatory' => true]],
            'language'        => ['eval' => ['mandatory' => true]],
            'dns'             => ['eval' => []],
            'url'             => ['eval' => ['mandatory' => true]],   // redirect pages only
            'twoFactorJumpTo' => ['eval' => ['mandatory' => true]],   // not in this palette
        ];

        $this->assertSame(['title', 'language'], DcaPaletteCommand::mandatoryOf(['title', 'type', 'language', 'dns'], $dca));
    }
}
