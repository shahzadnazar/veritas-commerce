<?php

declare(strict_types=1);

namespace App\Modules\Reviews\Exceptions;

use App\Modules\Reviews\Data\ReviewEvidence;
use App\Modules\Reviews\Enums\ReviewStatus;
use RuntimeException;

/**
 * A review operation the domain refused, with a machine-readable reason
 * beside the sentence so a controller can map it without parsing English.
 */
final class ReviewRefused extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason)
    {
        parent::__construct($message);
    }

    public static function notEligible(ReviewEvidence $evidence): self
    {
        return new self(
            $evidence->message() ?? 'You cannot review this product.',
            $evidence->reason === null ? 'not_eligible' : $evidence->reason->value,
        );
    }

    public static function ratingOutOfRange(int $rating): self
    {
        return new self(
            "A rating is a whole number from 1 to 5; {$rating} was given.",
            'rating_out_of_range',
        );
    }

    public static function bodyTooShort(): self
    {
        return new self(
            'Tell other shoppers a little more — a review needs at least a sentence.',
            'body_too_short',
        );
    }

    public static function notTheAuthor(): self
    {
        return new self('You can only change your own review.', 'not_the_author');
    }

    public static function notEditable(ReviewStatus $status): self
    {
        return new self(
            "A {$status->label()} review can no longer be edited.",
            'not_editable',
        );
    }

    public static function invalidTransition(ReviewStatus $from, ReviewStatus $to): self
    {
        return new self(
            "A {$from->label()} review cannot become {$to->label()}.",
            'invalid_transition',
        );
    }

    public static function reasonRequired(ReviewStatus $to): self
    {
        return new self(
            "A reason is required to {$to->value} a review. The customer is told what it says.",
            'reason_required',
        );
    }
}
