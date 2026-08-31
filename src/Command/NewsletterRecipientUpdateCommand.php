<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\NewsletterDenyListModel;
use Contao\NewsletterRecipientsModel;
use Contao\Validator;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Update a newsletter recipient.
 *
 * The create command's rules apply here too. A rule enforced only on create is
 * half a rule: an address that cannot be added could otherwise be written by
 * adding a harmless one and editing it afterwards.
 *
 * These checks need the stored record — the deny list and the duplicate rule
 * are both scoped to the recipient's channel, and `--set pid=…` is not how one
 * moves a recipient — so they sit in `preProcessFields()`, which receives it.
 * `updateMany()` already turns a throw into a per-record failure; `updateOne()`
 * is wrapped here so a single update reports the same JSON error instead of a
 * stack trace.
 *
 * ## The `active` check is a backstop, not a fix for a back-end gap
 *
 * It was written on the assumption that a recipient who unsubscribed could be
 * switched back on with the `active` toggle, since `checkDenyList` is a
 * `save_callback` on `email` and does not fire for it. Verified against the
 * source on 2026-08-31, and that assumption was wrong: **both** opt-out paths
 * write the deny list entry and then delete the recipient row.
 *
 *     BlockRecipientListener:  $objDenyList->save();  …  $recipient->delete();
 *     ModuleUnsubscribe:       $objDenyList->save();  …  $objRemove->delete();
 *
 * So a recipient row and a deny list entry for the same (pid, email) cannot
 * coexist through anything Contao itself does, and the check has nothing to
 * fire on. It stays as a backstop for a row that got in some other way — but
 * it is not the correction of a back-end oversight it was first described as.
 *
 * 🎯 What that verification did establish is why the deny list matters so much
 * on the *create* path: since the row is deleted, a re-import finds **no
 * duplicate** and sails straight through. The deny list is the only thing left
 * that remembers the opt-out — nothing else does.
 */
#[AsCommand(name: 'contao:newsletter-recipient:update', description: 'Update a newsletter recipient')]
class NewsletterRecipientUpdateCommand extends AbstractModelUpdateCommand
{
    protected function modelClass(): string { return NewsletterRecipientsModel::class; }
    protected function entityName(): string { return 'Newsletter recipient'; }

    protected function updateOne(int $id, array $fields): int
    {
        try {
            return parent::updateOne($id, $fields);
        } catch (\InvalidArgumentException $e) {
            return $this->outputError($e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when a rule of the create path would refuse this write
     */
    protected function preProcessFields(array $fields, object $record): array
    {
        $pid = (int) $record->pid;

        if (isset($fields['email'])) {
            $email = trim((string) $fields['email']);

            if (!Validator::isEmail($email)) {
                throw new \InvalidArgumentException(\sprintf('"%s" is not a valid e-mail address.', $email));
            }

            if ($email !== (string) $record->email) {
                if (NewsletterRecipientsModel::countBy(['pid=?', 'email=?'], [$pid, $email]) > 0) {
                    throw new \InvalidArgumentException(\sprintf(
                        '"%s" is already a recipient of channel %d.',
                        $email,
                        $pid,
                    ));
                }

                $this->refuseIfDenied($email, $pid);
            }

            $fields['email'] = $email;
        }

        // Stricter than the back end toggle — see the class docblock.
        if (isset($fields['active']) && '' !== (string) $fields['active'] && '0' !== (string) $fields['active']) {
            $this->refuseIfDenied((string) ($fields['email'] ?? $record->email), $pid);
        }

        return $fields;
    }

    /**
     * @throws \InvalidArgumentException when the address is on the channel's deny list
     */
    private function refuseIfDenied(string $email, int $pid): void
    {
        if (NewsletterDenyListModel::countBy(['pid=?', 'hash=?'], [$pid, md5($email)]) < 1) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            '"%s" is on the deny list for channel %d — they unsubscribed from it. There is no '
            . 'back end screen for the deny list and no command to clear it. The entry is '
            . 'removed only when the person subscribes again through the front end and '
            . 'confirms the opt-in mail — lifting an opt-out is theirs to do.',
            $email,
            $pid,
        ));
    }
}
