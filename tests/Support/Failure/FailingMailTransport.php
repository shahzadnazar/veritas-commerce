<?php

declare(strict_types=1);

namespace Tests\Support\Failure;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * A mail transport that refuses, in the two ways a real one refuses.
 *
 * The distinction matters more than it looks. A transient failure — the
 * provider is down, the connection dropped — should be retried, because
 * the message is fine and the world will be too in a minute. A permanent
 * rejection — the address does not exist, the account is suspended, the
 * message was refused for content — will be refused identically on every
 * one of the next four attempts, and retrying it is four more chances to
 * page somebody about a delivery that was never going to happen.
 *
 * Both are `TransportException` in Symfony, which is why the application
 * has to look at more than the class to tell them apart.
 */
final class FailingMailTransport implements TransportInterface
{
    public function __construct(private readonly bool $permanent = false) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        throw new TransportException(
            $this->permanent
                ? 'Recipient address rejected: 550 5.1.1 user unknown'
                : 'Connection could not be established with host mail.invalid',
        );
    }

    public function __toString(): string
    {
        return 'drill_failing';
    }
}
