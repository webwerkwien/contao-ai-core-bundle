<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:member:update', description: 'Update a frontend member field')]
class MemberUpdateCommand extends AbstractWriteCommand
{
    /**
     * What may NOT be written — everything else is decided by the table itself.
     *
     * 🔴 Until v1.0.0 this was an ALLOW list of twenty field names, and it had a
     * consequence nobody intended: **a bundle that adds columns to `tl_member`
     * could not be served through the CLI at all.** Found on 2026-09-23 while
     * taking delivery of the Consho shipping address — `--set
     * consho_shippingStreet=…` answered *"Field(s) not allowed"*, although
     * `contao:dca:schema tl_member` lists the very same field as writable.
     * Two commands of this bundle contradicted each other about one table.
     *
     * 🎯 The protection was right, the mechanism was not. An allow list says
     * *"everything not enumerated is forbidden"* — to keep two fields out, it
     * kept every third-party field out with them, and every field Contao itself
     * might add later. A deny list names what it protects and lets the generic
     * column check in AbstractWriteCommand::refuseUnknownFields() handle the
     * rest, so a misspelled field is still refused — against the real schema
     * rather than against a list maintained by hand.
     *
     * Only credentials and authentication state are listed. `login`, `disable`,
     * `start` and `stop` stay writable on purpose: the CLI operator is trusted
     * to manage account state. `lastLogin` and `currentLogin` become writable
     * with this change — they were excluded by omission before, they are not
     * credentials, and refusing them would start the same enumeration over.
     */
    // Lower case throughout — deniedAmong() compares that way, see there.
    public const DENIED_FIELDS = [
        'password',             // writing it directly bypasses Contao's hashing
        'secret',               // TOTP seed
        'usetwofactor',         // switching it off would disarm the second factor
        'backupcodes',          // 2FA recovery codes
        'trustedtokenversion',  // bumping it forges "this device is trusted"
        'session',              // forging a session is forging a login
    ];

    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED, 'Username of the member');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $username = $this->input->getArgument('username');

        // The credential guard runs before the record is loaded: refusing a
        // password must not depend on whether the member happens to exist, and
        // the check stays reachable without a database — which is what makes it
        // testable. The column check cannot work that way, it needs the schema.
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        $denied = $this->deniedAmong($fields, self::DENIED_FIELDS);
        if ([] !== $denied) {
            return $this->outputError(\sprintf(
                'Field(s) not allowed: %s. Credentials and two-factor state are never written '
                . 'through --set; the password has its own command (contao:member:password). '
                . 'Nothing was written.',
                implode(', ', $denied),
            ));
        }
        // Everything else is checked against the table's real columns further
        // down, in convertFields() -> refuseUnknownFields(). That is what makes
        // fields from other bundles usable here.

        $member = MemberModel::findByUsername($username);
        if ($member === null) {
            return $this->outputError("Member not found: $username");
        }

        $id = (int) $member->id;

        // 🔴 H-2 (Audit 2026-09-02): hier wurden die Werte ROH aufs Model
        // geschrieben und gespeichert. Zwei Fehler in einem:
        //
        //  1. kein tl_version-Snapshot — die Änderung war nicht rückholbar
        //  2. keine DCA-Konvertierung — `--set groups=1,2` schrieb den String in
        //     eine serialisierte Spalte und meldete `ok`
        //
        // 🎯 Punkt 2 ist derselbe Fehler, den AbstractWriteCommand in seinem
        // eigenen Docblock beschreibt: *"of eleven create commands that accept
        // --set … the rest wrote a raw string into a serialized column and
        // reported success"*. Er wurde am 31.08. für den generischen Pfad
        // behoben und mit einem Test abgesichert — dieser Befehl lief daran
        // vorbei, weil er den Datensatz über den Benutzernamen sucht.
        $fields = $this->convertFields(MemberModel::getTable(), $fields, $id);

        $updated = $this->writer()->update(
            MemberModel::getTable(),
            $id,
            $fields,
            $this->resolveOperator(),
        );

        $this->outputSuccess(['username' => $username, 'updated' => $updated ?? []]);
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
