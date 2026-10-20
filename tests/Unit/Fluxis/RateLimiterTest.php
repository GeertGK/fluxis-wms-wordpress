<?php

use Fluxis\WooCommerce\Rest\RateLimiter;
use Tests\Stubs\MemoryWindowCounter;

beforeEach(function () {
    $this->counter = new MemoryWindowCounter();
    $this->limiter = new RateLimiter($this->counter);
});

it('allows verified requests up to the per-connection budget', function () {
    for ($i = 0; $i < RateLimiter::LIMIT_PER_MINUTE; $i++) {
        expect($this->limiter->hitVerified('01JCONNECTION'))->toBeTrue();
    }

    expect($this->limiter->hitVerified('01JCONNECTION'))->toBeFalse()
        ->and($this->limiter->hitVerified('01JOTHER'))->toBeTrue();
});

it('counts unsigned traffic per IP, separate from the connection budget', function () {
    for ($i = 1; $i <= RateLimiter::UNSIGNED_LIMIT_PER_MINUTE + 5; $i++) {
        $hits = $this->limiter->hitUnsigned('203.0.113.7');
    }

    expect($hits)->toBe(RateLimiter::UNSIGNED_LIMIT_PER_MINUTE + 5)
        ->and(RateLimiter::unsignedLimitExceeded($hits))->toBeTrue()
        ->and($this->limiter->hitUnsigned('198.51.100.1'))->toBe(1)
        ->and($this->counter->total('c'))->toBe(0)
        ->and($this->limiter->hitVerified('01JCONNECTION'))->toBeTrue();
});

it('allows exactly the unsigned limit per IP', function () {
    expect(RateLimiter::unsignedLimitExceeded(RateLimiter::UNSIGNED_LIMIT_PER_MINUTE))->toBeFalse()
        ->and(RateLimiter::unsignedLimitExceeded(RateLimiter::UNSIGNED_LIMIT_PER_MINUTE + 1))->toBeTrue();
});
