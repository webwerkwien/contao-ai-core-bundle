<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\EventListener;

/**
 * Stands in for a console error whose message quoted a secret (v1.6.0).
 *
 * The log serialises the exception in its context — message, file and line, and the
 * same for each previous one. Where one of those messages held a value that
 * {@see RedactingConsoleErrorListener} blanked in the line, this takes the
 * exception's place: the original class in the message, the message blanked, file and
 * line kept. The stack trace is not: it cannot be carried over, and the one of this
 * object would only point at the listener.
 */
final class RedactedConsoleError extends \RuntimeException
{
    /**
     * @param \Closure(string): string $strike
     */
    public static function of(\Throwable $error, \Closure $strike): self
    {
        $previous = $error->getPrevious();
        $copy     = new self(
            $error::class . ': ' . $strike($error->getMessage()),
            0,
            null === $previous ? null : self::of($previous, $strike),
        );
        $copy->file = $error->getFile();
        $copy->line = $error->getLine();

        return $copy;
    }
}
