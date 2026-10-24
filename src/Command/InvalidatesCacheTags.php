<?php declare(strict_types=1);

namespace Webwerkwien\ContaoAiCoreBundle\Command;

use Symfony\Contracts\Service\Attribute\Required;
use Webwerkwien\ContaoAiCoreBundle\Service\Cache\CacheTagInvalidator;
use Webwerkwien\ContaoAiCoreBundle\Service\Dca\DcaCallbacks;

/**
 * Cache invalidation for a write command, and what it tells the caller.
 *
 * Shared by AbstractWriteCommand and the two write commands that do not extend
 * it (`version restore`, `record clone`). See CacheTagInvalidator for what is
 * invalidated and why.
 */
trait InvalidatesCacheTags
{
    /**
     * Nullable for the same reason as the record writer: the command tests
     * construct commands by hand. In the container it is always injected.
     */
    protected ?CacheTagInvalidator $cacheTags = null;

    #[Required]
    public function setCacheTagInvalidator(CacheTagInvalidator $cacheTags): void
    {
        $this->cacheTags = $cacheTags;
    }

    /** The record callbacks the write ran (v1.4.0); nullable like the invalidator. */
    protected ?DcaCallbacks $dcaCallbacks = null;

    #[Required]
    public function setDcaCallbacks(DcaCallbacks $dcaCallbacks): void
    {
        $this->dcaCallbacks = $dcaCallbacks;
    }

    /**
     * At the start of a command: forget what an earlier command reported. Both services
     * outlive one command — in the back-end chat several run in one request, and a
     * refused one never drained its report (found before v1.4.0 and v1.4.1).
     */
    protected function forgetEarlierReports(): void
    {
        $this->cacheTags?->reset();
        $this->dcaCallbacks?->drainReport();
        $this->dcaCallbacks?->drainMessages();
    }

    /**
     * `messages`: what the callbacks told the editor through Contao's messages (v1.6.0),
     * on success and on refusal alike — a callback that refuses often says why there.
     *
     * @return array{messages?: list<array{type: string, text: string}>}
     */
    protected function callbackMessages(): array
    {
        $messages = $this->dcaCallbacks?->drainMessages() ?? [];

        return [] === $messages ? [] : ['messages' => $messages];
    }

    /**
     * Adds `cacheTags` (what was invalidated) and — only when something went
     * wrong — `cacheWarnings` (why part of the cache may still be stale).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function withCacheReport(array $data): array
    {
        $report = $this->cacheTags?->drainReport() ?? [];

        if ([] !== ($report['tags'] ?? [])) {
            $data['cacheTags'] = $report['tags'];
        }

        if ([] !== ($report['warnings'] ?? [])) {
            $data['cacheWarnings'] = $report['warnings'];
        }

        // Which DCA callbacks ran — said, so a caller can see what the write set off
        // besides itself (a notification mail, a search index entry removed).
        if ([] !== ($callbacks = $this->dcaCallbacks?->drainReport() ?? [])) {
            $data['callbacks'] = $callbacks;
        }

        return $data + $this->callbackMessages();
    }
}
