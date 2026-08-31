<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\NewsletterChannelModel;
use Contao\NewsletterDenyListModel;
use Contao\NewsletterRecipientsModel;
use Contao\Validator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;

/**
 * Add a recipient to a newsletter channel.
 *
 * ## Why this exists at all, given double opt-in
 *
 * Double opt-in in Contao guards the **front end self-subscription**, not the
 * table. `ModuleSubscribe` creates the row with `active=0` and `addedOn=<time>`,
 * mails an opt-in token, and only the confirmation sets `active=1`. But Contao
 * itself offers two paths that skip all of it: adding a recipient in the back
 * end (`active` is a plain checkbox in the palette), and the CSV import, which
 * inserts with `'active' => 1` hard-coded and no token at all.
 *
 * 🎯 **So the model is: opt-in protects people from being signed up by someone
 * else through the website; an operator importing a list carries the consent
 * themselves.** This command is the CSV import in single-record form, and it
 * applies the import's rules rather than inventing its own.
 *
 * ## The rules, taken from Newsletter::importRecipients()
 *
 *  1. `Validator::isEmail()` — the DCA's `rgxp => 'email'` would be enforced by
 *     `DC_Table`, and this write path goes around it.
 *  2. no duplicate `(pid, email)` — there is a unique index behind it, so
 *     skipping the check would trade a clear message for an SQL error.
 *  3. **not on the channel's deny list** — the one rule Contao repeats on every
 *     path (`save_callback` *and* import), and the only one with no database
 *     constraint behind it. Missing it would silently re-add someone who
 *     unsubscribed, and they would get the next newsletter.
 *
 * 🎯 **Rule 3 carries more weight than it looks.** Both opt-out paths write the
 * deny list entry and then *delete* the recipient row
 * (`BlockRecipientListener`, `ModuleUnsubscribe`). So rule 2 finds no duplicate
 * for someone who unsubscribed — the row is gone. The deny list is the only
 * thing left that remembers the opt-out, and it is not administratively
 * removable: `tl_newsletter_deny_list` has no `dataContainer`, no back end
 * module, no command. `ModuleSubscribe::activateRecipient()` deletes the entry
 * after a confirmed opt-in and nothing else does (Contao #4999) — the person
 * who opted out is the only one who can lift it, by opting back in.
 *
 * Rule 4 of the import, the `CreateAction` permission check, is deliberately
 * dropped: it gates a back end user against their module rights, and whoever
 * reaches this command already has shell access.
 *
 * ## `addedOn` stays empty, on purpose
 *
 * The import leaves it empty too, and Contao reads that: the recipient list
 * labels a row with `addedOn` as "subscribed on <date>" and one without it as
 * "added manually". A record this command writes is not an opt-in and should
 * not look like one.
 *
 * ## `active` defaults to off
 *
 * Passing neither `--active` nor `--set active=1` creates an inactive
 * recipient, which receives nothing until someone activates it. The CLI wrapper
 * is what asks the question; here the value is simply explicit or absent.
 */
#[AsCommand(name: 'contao:newsletter-recipient:create', description: 'Add a recipient to a newsletter channel')]
class NewsletterRecipientCreateCommand extends AbstractWriteCommand
{
    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption('email',  null, InputOption::VALUE_REQUIRED, 'Recipient e-mail address');
        $this->addOption('pid',    null, InputOption::VALUE_REQUIRED, 'Newsletter channel ID');
        $this->addOption(
            'active', null, InputOption::VALUE_NONE,
            'Create the recipient active. Without this the recipient is created inactive '
            . 'and receives nothing until someone activates it.',
        );
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();

        $email = trim((string) $this->input->getOption('email'));
        $pid   = (string) $this->input->getOption('pid');

        if ('' === $email || '' === $pid) {
            return $this->outputError('--email and --pid are required');
        }

        if (null === NewsletterChannelModel::findById((int) $pid)) {
            return $this->outputError(\sprintf(
                'Newsletter channel not found: %s. List them with `newsletter channels`.',
                $pid,
            ));
        }

        // Rule 1 — the DCA says rgxp => 'email'; nothing on this path enforces it.
        if (!Validator::isEmail($email)) {
            return $this->outputError(\sprintf('"%s" is not a valid e-mail address.', $email));
        }

        // Rule 2 — a unique index on (pid, email) would catch this as a raw SQL
        // error; catching it here says which channel and which address.
        if (NewsletterRecipientsModel::countBy(['pid=?', 'email=?'], [(int) $pid, $email]) > 0) {
            return $this->outputError(\sprintf(
                '"%s" is already a recipient of channel %s.',
                $email,
                $pid,
            ));
        }

        // Rule 3 — the deny list. No database constraint behind this one, so
        // skipping the check would re-subscribe someone who opted out and
        // nothing would look wrong until the next newsletter went out.
        if (NewsletterDenyListModel::countBy(['pid=?', 'hash=?'], [(int) $pid, md5($email)]) > 0) {
            return $this->outputError(\sprintf(
                '"%s" is on the deny list for channel %s — they unsubscribed from it. '
                . 'Contao refuses this on every path that adds recipients, and so does this '
                . 'command. There is no back end screen for the deny list and no command to '
                . 'clear it: tl_newsletter_deny_list has no dataContainer. The entry is '
                . 'removed only when the person subscribes again through the front end and '
                . 'confirms the opt-in mail — see ModuleSubscribe::activateRecipient(). '
                . 'Lifting an opt-out is theirs to do, not an administrator\'s.',
                $email,
                $pid,
            ));
        }

        if (!isset($GLOBALS['TL_DCA']['tl_newsletter_recipients']['fields'])) {
            Controller::loadDataContainer('tl_newsletter_recipients');
        }

        $fields = $this->convertFields('tl_newsletter_recipients', $fields);

        $recipient         = new NewsletterRecipientsModel();
        $recipient->tstamp = time();
        $recipient->pid    = (int) $pid;
        $recipient->email  = $email;
        // 0/1, not '1'/''. `tl_newsletter_recipients.active` is declared
        // `['type' => 'boolean']` — a real tinyint column, not the char(1) that
        // older DCAs use for flags — and MySQL in strict mode rejects '' for it
        // with "Incorrect integer value". Found on c5, 2026-08-31: the --active
        // path passed and the default path died, because only the empty string
        // is invalid. Mocked tests cannot see this; only a live write can.
        $recipient->active = $this->input->getOption('active') ? 1 : 0;
        // addedOn stays empty: this is not an opt-in, and the back end labels a
        // row without it as "added manually", which is exactly what it is.

        foreach ($fields as $key => $value) {
            $recipient->$key = $value;
        }

        $recipient->save();
        $this->createVersion('tl_newsletter_recipients', (int) $recipient->id);

        $this->outputSuccess([
            'id'     => (int) $recipient->id,
            'email'  => $email,
            'active' => (bool) $recipient->active,
        ]);

        return Command::SUCCESS;
    }
}
