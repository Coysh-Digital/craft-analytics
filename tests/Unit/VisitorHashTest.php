<?php

use coyshdigital\craftanalytics\services\IdentityService;

/**
 * The visitor hash format has one statement of the rule, because it is checked
 * in three places on the write path. A value that fails here must be dropped
 * where dropping is cheap — the alternative, historically, was an exception
 * deep inside the drain's commit that no retry could clear.
 */
test('a hash this build can use is 16 hex characters', function() {
    expect(IdentityService::isValidHash('deadbeefdeadbeef'))->toBeTrue()
        ->and(IdentityService::isValidHash('0123456789ABCDEF'))->toBeTrue();
});

test('anything else is rejected', function(string $hash) {
    expect(IdentityService::isValidHash($hash))->toBeFalse();
})->with([
    'empty' => '',
    // What a build with a different HASH_BYTES would mint.
    'too short' => 'deadbeef',
    'too long' => 'deadbeefdeadbeef0',
    'right length, not hex' => 'ZZZZZZZZZZZZZZZZ',
    'whitespace padded' => ' deadbeefdeadbee',
]);

test('what the identity service produces is always valid', function() {
    // The rule and the producer agree: this is what stops the check from
    // quietly rejecting every real hit if HASH_BYTES ever moves.
    $hash = bin2hex(substr(hash('sha256', 'salt|ip|ua|1', true), 0, IdentityService::HASH_BYTES));

    expect(IdentityService::isValidHash($hash))->toBeTrue();
});
