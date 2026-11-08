<?php

declare(strict_types=1);

namespace PoltergeistSwitch\TurnLoop;

/**
 * Raised when the secrets configuration or a named key cannot be loaded
 * (Requirements 2.4, 2.5).
 *
 * The message MUST NOT contain any secret value or the secrets directory path
 * (Requirement 2.6); it carries only a short machine code and, for a key
 * failure, the client name (e.g. `anthropic`, `openai`), which is not a secret.
 */
final class SecretLoadException extends \RuntimeException
{
    public const CODE_CONFIG_MISSING = 'config_missing';
    public const CODE_CONFIG_INVALID = 'config_invalid';
    public const CODE_KEY_MISSING = 'key_missing';
    public const CODE_KEY_EMPTY = 'key_empty';

    private string $reason;

    public function __construct(string $reason, string $message)
    {
        parent::__construct($message);
        $this->reason = $reason;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public static function configMissing(): self
    {
        return new self(
            self::CODE_CONFIG_MISSING,
            'secrets configuration is missing or unreadable'
        );
    }

    public static function configInvalid(): self
    {
        return new self(
            self::CODE_CONFIG_INVALID,
            'secrets configuration is invalid'
        );
    }

    public static function keyMissing(string $name): self
    {
        return new self(
            self::CODE_KEY_MISSING,
            sprintf('key for client "%s" is missing or unreadable', $name)
        );
    }

    public static function keyEmpty(string $name): self
    {
        return new self(
            self::CODE_KEY_EMPTY,
            sprintf('key for client "%s" is empty', $name)
        );
    }
}
