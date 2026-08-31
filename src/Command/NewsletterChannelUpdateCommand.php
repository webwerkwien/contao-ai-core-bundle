<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsletterChannelModel;
use Contao\Validator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Update a newsletter channel.
 *
 * The one addition over the generic update path is the `sender` check. It is
 * the same rule the create command applies, for the same reason: the field
 * carries `rgxp => 'email'`, `DC_Table` enforces that through the widget, and
 * this write path goes around `DC_Table`. Checking only on create would leave
 * the rule half-applied — an address that cannot be created could still be
 * written by renaming it afterwards.
 *
 * The check sits in `doExecute()` rather than `preProcessFields()` on purpose:
 * it needs no record, so it can refuse before anything is read, and it applies
 * to the `--ids` path identically.
 */
#[AsCommand(name: 'contao:newsletter-channel:update', description: 'Update a newsletter channel')]
class NewsletterChannelUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsletterChannelModel::class; }
    protected function entityName(): string { return 'Newsletter channel'; }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        if (isset($fields['sender']) && !Validator::isEmail((string) $fields['sender'])) {
            return $this->outputError(\sprintf(
                '"%s" is not a valid e-mail address. tl_newsletter_channel.sender carries '
                . "rgxp => 'email' in the DCA.",
                (string) $fields['sender'],
            ));
        }

        return parent::doExecute($fields);
    }
}
