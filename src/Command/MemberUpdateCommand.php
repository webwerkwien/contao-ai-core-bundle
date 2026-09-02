<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\MemberModel;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'contao:member:update', description: 'Update a frontend member field')]
class MemberUpdateCommand extends AbstractWriteCommand
{
    // 'password' deliberately excluded to prevent credential manipulation.
    // 'disable' and 'login' are intentionally allowed — sysadmin CLI is trusted to manage account state.
    private const ALLOWED_FIELDS = [
        'firstname', 'lastname', 'email', 'phone', 'mobile',
        'dateOfBirth', 'gender', 'language', 'company', 'street',
        'postal', 'city', 'state', 'country', 'website',
        'groups', 'login', 'disable', 'start', 'stop',
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

        // Input is validated before the record is loaded: a rejected field must
        // not depend on whether the member happens to exist, and the check stays
        // reachable without a database — which is what makes it testable.
        if (empty($fields)) {
            return $this->outputError('No fields specified. Use --set field=value');
        }

        $disallowedFields = array_diff(array_keys($fields), self::ALLOWED_FIELDS);
        if (!empty($disallowedFields)) {
            return $this->outputError('Field(s) not allowed: ' . implode(', ', $disallowedFields));
        }

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
