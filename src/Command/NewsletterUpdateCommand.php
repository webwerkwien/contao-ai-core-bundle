<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsletterModel;
use Contao\Validator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Update a newsletter.
 *
 * Two checks on top of the generic update path, both of them the same rules the
 * create command applies — a rule enforced only on create is half a rule, since
 * anything refused there can be written afterwards by editing.
 *
 *  - `NewsletterSendGuard` refuses `sent` and `date`. This is the route that
 *    matters most: an agent reaching for `--set sent=1` on an existing
 *    newsletter is a far likelier idea than passing it at creation time.
 *  - `sender` carries `rgxp => 'email'`, which `DC_Table` validates through the
 *    widget and this path does not.
 *
 * Both sit in `doExecute()` rather than `preProcessFields()`: neither needs the
 * stored record, so they can refuse before anything is read, and they cover the
 * `--ids` bulk form with the same code.
 */
#[AsCommand(name: 'contao:newsletter:update', description: 'Update a newsletter')]
class NewsletterUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsletterModel::class; }
    protected function entityName(): string { return 'Newsletter'; }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        if (null !== $refusal = NewsletterSendGuard::refuse($fields)) {
            return $this->outputError($refusal);
        }

        if (isset($fields['sender']) && !Validator::isEmail((string) $fields['sender'])) {
            return $this->outputError(\sprintf(
                '"%s" is not a valid e-mail address. tl_newsletter.sender carries '
                . "rgxp => 'email' in the DCA.",
                (string) $fields['sender'],
            ));
        }

        return parent::doExecute($fields);
    }
}
