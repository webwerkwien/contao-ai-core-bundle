<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\UserModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:user:update', description: 'Update a backend user field')]
class UserUpdateCommand extends AbstractWriteCommand
{
    /**
     * What may NOT be written — everything else is decided by the table itself.
     *
     * Until v1.0.0 an ALLOW list of 22 names, with the same flaw as its twin in
     * MemberUpdateCommand (see the docblock there, and the note below): to keep
     * `admin` and `password` out, it kept every field of every other bundle out
     * as well, plus whatever Contao adds in a future version.
     *
     * The escalation guard is unchanged in substance — `admin` still cannot be
     * set from here, and neither can anything that is a credential or part of
     * the second factor. What changed is that the list now says what it guards
     * instead of enumerating the remainder.
     *
     * ⚠️ `groups` stays writable, and a group can carry admin-equivalent rights.
     * That was true before as well; the escalation this guards is the direct
     * one, not every path to power.
     */
    public const DENIED_FIELDS = [
        'admin',                // direct escalation to full control
        'password',             // writing it directly bypasses Contao's hashing
        'pwchange',             // forcing or clearing the password change flag
        'secret',               // TOTP seed
        'usetwofactor',         // switching it off would disarm the second factor
        'backupcodes',          // 2FA recovery codes
        'trustedtokenversion',  // bumping it forges "this device is trusted"
        'session',              // forging a session is forging a login
        // 🔴 Added after the pre-release review of v1.1.0: the old allow list
        // withheld it by omission, the first draft of this list let it through,
        // and it is not a preference.
        'amg',                  // allowed member groups: Contao's front-end preview
                                // authenticates AS those groups — see
                                // BackendPreviewSwitchController, which filters by
                                // it. Setting it is impersonation of member accounts
    ];

    // 🎯 `cud` was on this list for one draft and came back off. Worth recording,
    // because the reasoning nearly went the wrong way.
    //
    // It is the per-user create/update/delete permission table and looks like a
    // grant one should withhold. But in Contao 5.7 it is the SUCCESSOR of
    // `formp`: Version507\FieldPermissionMigration maps `formp` to
    // `tl_form::create` / `tl_form::delete` inside `cud`, and `formp` stood on
    // the old allow list. Denying `cud` would have made v1.1.0 **stricter than
    // v1.0.0 on 5.7+** — a capability taken away in passing, in the very release
    // whose point is that a guard must not withhold more than it protects.
    //
    // It also sits in the same class as `elements`, `pagemounts`, `fop`, `forms`
    // and `modules`, all writable before and after. Back-end permission
    // administration is not what this guard withholds; direct escalation
    // (`admin`), credentials and impersonation (`amg`) are.

    public function __construct(private readonly ContaoFramework $framework)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument('username', InputArgument::REQUIRED, 'Username of the user to update');
    }

    protected function doExecute(array $fields): int
    {
        $this->framework->initialize();
        $username = $this->input->getArgument('username');

        // The escalation guard runs before the record is loaded: refusing `admin`
        // must not depend on whether the user happens to exist, and the check
        // stays reachable without a database — which is what makes it testable.
        // The column check cannot work that way, it needs the schema.
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        $denied = $this->deniedAmong($fields, self::DENIED_FIELDS);
        if ([] !== $denied) {
            return $this->outputError(\sprintf(
                'Field(s) not allowed: %s. Admin rights, credentials and two-factor state are '
                . 'never written through --set. Nothing was written.',
                implode(', ', $denied),
            ));
        }
        // Everything else is checked against the table's real columns further
        // down, in convertFields() -> refuseUnknownFields(). That is what makes
        // fields from other bundles usable here.

        $user = UserModel::findByUsername($username);
        if ($user === null) {
            return $this->outputError("User not found: $username");
        }

        $id = (int) $user->id;

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
        $fields = $this->convertFields(UserModel::getTable(), $fields, $id);

        $updated = $this->writer()->update(
            UserModel::getTable(),
            $id,
            $fields,
            $this->resolveOperator(),
        );

        $this->outputSuccess(['username' => $username, 'updated' => $updated ?? []]);
        return \Symfony\Component\Console\Command\Command::SUCCESS;
    }
}
