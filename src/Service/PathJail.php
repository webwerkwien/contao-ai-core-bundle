<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Service;

/**
 * Whether a path stays in its root — `files/` or `templates/` — once symbolic links are
 * resolved (v1.14.0).
 *
 * The file and template commands checked against the project directory. A link inside
 * files/ that points at `../.env.local` or `config/` resolved inside the project and
 * passed: file read returned the secrets, file write overwrote the configuration. The
 * root itself may be a link — deployments share files/ that way — so the comparison is
 * against the resolved root, not the literal path.
 */
final class PathJail
{
    /**
     * @param string $root     the root below the project directory, e.g. `files`
     * @param string $absolute the target; for a path that does not exist yet its deepest
     *                         existing ancestor is checked
     */
    public static function contains(string $projectDir, string $root, string $absolute): bool
    {
        $check = $absolute;
        while (!file_exists($check) && $check !== \dirname($check)) {
            $check = \dirname($check);
        }

        $real = realpath($check);
        if (false === $real) {
            return false;
        }

        $realRoot = realpath(rtrim($projectDir, '/') . '/' . $root);
        if (false === $realRoot) {
            // The root does not exist yet (a fresh templates/): nothing on the way may
            // lead elsewhere, so the deepest existing ancestor is the project directory.
            return $real === realpath($projectDir);
        }

        return $real === $realRoot || str_starts_with($real . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR);
    }
}
