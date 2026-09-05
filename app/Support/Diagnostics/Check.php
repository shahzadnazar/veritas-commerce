<?php

declare(strict_types=1);

namespace App\Support\Diagnostics;

/**
 * One thing the production check looked at, and what it found.
 *
 * Four outcomes rather than pass/fail, because "we could not tell" is a
 * real answer and reporting it as a pass is how a broken deployment gets
 * a green tick. FAIL stops a release; WARN is something an operator
 * should know before launch but that does not by itself make the
 * configuration unsafe; SKIPPED is a check that does not apply here.
 *
 * A Check never carries a secret. `detail` describes what was found —
 * "set, 24 characters", "points at localhost" — and the value itself
 * stays where it was.
 */
final readonly class Check
{
    public const PASS = 'pass';

    public const WARN = 'warn';

    public const FAIL = 'fail';

    public const SKIPPED = 'skipped';

    private function __construct(
        public string $group,
        public string $name,
        public string $status,
        public string $detail,
        public ?string $remedy = null,
    ) {}

    public static function pass(string $group, string $name, string $detail): self
    {
        return new self($group, $name, self::PASS, $detail);
    }

    /** Something an operator should know, that does not block a release. */
    public static function warn(string $group, string $name, string $detail, ?string $remedy = null): self
    {
        return new self($group, $name, self::WARN, $detail, $remedy);
    }

    /** Unsafe or missing. The command exits non-zero on any of these. */
    public static function fail(string $group, string $name, string $detail, ?string $remedy = null): self
    {
        return new self($group, $name, self::FAIL, $detail, $remedy);
    }

    public static function skipped(string $group, string $name, string $detail): self
    {
        return new self($group, $name, self::SKIPPED, $detail);
    }

    public function isFailure(): bool
    {
        return $this->status === self::FAIL;
    }

    /**
     * How a value is described without disclosing it.
     *
     * Length and presence are what a deployment check needs; the value is
     * what an attacker needs. §21 and §126: never reveal secret values.
     */
    public static function describeSecret(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return 'not set';
        }

        return 'set, '.mb_strlen($value).' characters';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'group' => $this->group,
            'name' => $this->name,
            'status' => $this->status,
            'detail' => $this->detail,
            'remedy' => $this->remedy,
        ], static fn (?string $value): bool => $value !== null);
    }
}
