<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\LayoutModel;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Five columns here are `inputUnit` — `width`, `headerHeight`, `footerHeight`,
 * `widthLeft`, `widthRight` — and all five offer `px % em rem vw vh`. The
 * shared conversion already keeps a record's existing unit on update and
 * validates whatever it ends up with against those options, so nothing can be
 * written that Contao would not accept. What the default should be when there
 * is no existing value is the one thing it cannot know: `h2` is the headline
 * default inherited from tl_content, and it is meaningless for a layout width.
 *
 * Without this override the validation would silently fall through to the first
 * option, which happens to be `px` — the right answer for the wrong reason, and
 * one that a reordering of Contao's option list would quietly break.
 *
 * `sections` and `modules` are `sectionWizard` / `moduleWizard` columns holding
 * serialized structures. `--set` writes them as given; composing them belongs
 * to a caller that knows what it is doing, not to a field-setter.
 */
#[AsCommand(name: 'contao:layout:update', description: 'Update a page layout')]
class LayoutUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return LayoutModel::class; }
    protected function entityName(): string { return 'Layout'; }
    protected function defaultInputUnit(): string { return 'px'; }
}
