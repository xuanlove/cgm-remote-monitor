<?php
declare(strict_types=1);

namespace NightPanel;

/**
 * A failure talking to Nightscout.
 *
 * Carries the HTTP status when one exists, so callers can distinguish "the
 * record is gone" (404) from "Nightscout is unreachable" (null) without
 * parsing message text.
 */
final class NsException extends \RuntimeException
{
    private ?int $httpStatus;

    public function __construct(string $message, ?int $httpStatus = null)
    {
        parent::__construct($message);
        $this->httpStatus = $httpStatus;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
