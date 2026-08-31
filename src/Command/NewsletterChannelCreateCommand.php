<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsletterChannelModel;
use Contao\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Create a newsletter channel — the container newsletters and recipients hang off.
 *
 * `tl_newsletter_channel` is a root table (no `ptable`) with
 * `ctable => [tl_newsletter, tl_newsletter_recipients]`, so it is the entry
 * point for the whole module: neither a newsletter nor a recipient can be
 * created without one.
 *
 * Two mandatory fields, both in the `default` palette and no subpalette at all:
 * `title` and `sender`. `sender` carries `rgxp => 'email'`, which `DC_Table`
 * would validate through the widget and this write path would not — so it is
 * checked here explicitly. See the `rgxp` requirement in the project file: the
 * write layer validates no `rgxp` at all yet, and until it does, every command
 * that writes an address field carries its own check.
 *
 *   newsletter channel-create --title "Kundeninfo"
 *       → refused, sender missing
 *   newsletter channel-create --title "Kundeninfo" --set sender=info@example.com
 *       → accepted
 *
 * `jumpTo` sits in the palette without being mandatory. It is a `pageTree`
 * field, so `convertFields()` turns a page ID into the int the column wants.
 */
#[AsCommand(name: 'contao:newsletter-channel:create', description: 'Create a newsletter channel')]
class NewsletterChannelCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('title', null, InputOption::VALUE_REQUIRED, 'Channel title (back end label)');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $title = (string) $this->input->getOption('title');

        if ('' === $title) {
            return $this->outputError('--title is required');
        }

        if (!isset($GLOBALS['TL_DCA']['tl_newsletter_channel']['fields'])) {
            Controller::loadDataContainer('tl_newsletter_channel');
        }

        $missing = $this->missingMandatoryFields('tl_newsletter_channel', 'default', $fields, ['title']);
        if ([] !== $missing) {
            return $this->outputError(\sprintf(
                'Also required: %s. Pass them with --set. (sender is the From address every '
                . 'newsletter in this channel is sent from.)',
                implode(', ', $missing),
            ));
        }

        if (isset($fields['sender']) && !Validator::isEmail((string) $fields['sender'])) {
            return $this->outputError(\sprintf(
                '"%s" is not a valid e-mail address. tl_newsletter_channel.sender carries '
                . "rgxp => 'email' in the DCA.",
                (string) $fields['sender'],
            ));
        }

        $fields = $this->convertFields('tl_newsletter_channel', $fields);

        $channel         = new NewsletterChannelModel();
        $channel->tstamp = time();
        $channel->title  = $title;

        foreach ($fields as $key => $value) {
            $channel->$key = $value;
        }

        $channel->save();
        $this->createVersion('tl_newsletter_channel', (int) $channel->id);

        $this->outputSuccess(['id' => (int) $channel->id, 'title' => $title]);

        return Command::SUCCESS;
    }
}
