<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsletterChannelModel;
use Contao\NewsletterModel;
use Contao\StringUtil;
use Contao\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a newsletter inside a channel.
 *
 * Mandatory is `subject` from the `default` palette, plus `files` — but that
 * one only once `addFile` is switched on, because it lives in the `addFile`
 * subpalette. `missingMandatoryFields()` covers both levels, so:
 *
 *   newsletter create --pid 1 --subject "Juni-Ausgabe"
 *       → accepted
 *   newsletter create --pid 1 --subject "Juni" --set addFile=1
 *       → refused, files missing
 *   newsletter create --pid 1 --subject "Juni" --set addFile=1 --set files=<uuid>
 *       → accepted
 *
 * `files` is a `fileTree` field with `multiple`, so `convertFields()` turns
 * UUID strings into the binary, serialized form the column wants.
 *
 * The alias follows the pattern of every other create command in this bundle:
 * generated from the title with `StringUtil::generateAlias()`, because Contao's
 * own `generateAlias` is a `save_callback` and this write path does not run
 * those. Without it the alias would stay empty and the newsletter reader would
 * not resolve the entry.
 */
#[AsCommand(name: 'contao:newsletter:create', description: 'Create a newsletter')]
class NewsletterCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Newsletter subject');
        $this->addOption('pid',     null, InputOption::VALUE_REQUIRED, 'Newsletter channel ID');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $subject = (string) $this->input->getOption('subject');
        $pid     = (string) $this->input->getOption('pid');

        if ('' === $subject || '' === $pid) {
            return $this->outputError('--subject and --pid are required');
        }

        if (null === NewsletterChannelModel::findById((int) $pid)) {
            return $this->outputError(\sprintf(
                'Newsletter channel not found: %s. List them with `newsletter channels`.',
                $pid,
            ));
        }

        if (null !== $refusal = NewsletterSendGuard::refuse($fields)) {
            return $this->outputError($refusal);
        }

        if (!isset($GLOBALS['TL_DCA']['tl_newsletter']['fields'])) {
            Controller::loadDataContainer('tl_newsletter');
        }

        $missing = $this->missingMandatoryFields('tl_newsletter', 'default', $fields, ['subject']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required: %s. Pass them with --set. (files is mandatory only because '
                . 'addFile is switched on — leave addFile off and it is not needed.)',
                implode(', ', $missing),
            ));
        }

        if (isset($fields['sender']) && !Validator::isEmail((string) $fields['sender'])) {
            return $this->outputError(\sprintf(
                '"%s" is not a valid e-mail address. tl_newsletter.sender carries '
                . "rgxp => 'email' in the DCA.",
                (string) $fields['sender'],
            ));
        }

        $fields = $this->preparedFields('tl_newsletter', [
            'pid'     => (int) $pid,
            'subject' => $subject,
            'alias'   => $this->resolveAlias('tl_newsletter', '', $subject),
        ], $fields);

        $newsletter          = new NewsletterModel();
        $newsletter->tstamp  = time();

        foreach ($fields as $key => $value) {
            $newsletter->$key = $value;
        }

        $newsletter->save();
        $this->createVersion('tl_newsletter', (int) $newsletter->id);

        $this->outputSuccess(['id' => (int) $newsletter->id, 'subject' => $subject]);

        return Command::SUCCESS;
    }
}
