<?php
declare(strict_types=1);
namespace AdelPos\License\Tests;

final class FrozenClock extends \AdelPos\License\Infrastructure\Clock
{
    public function __construct(public \DateTimeImmutable $instant) {}
    public function now(): \DateTimeImmutable { return $this->instant; }
}
