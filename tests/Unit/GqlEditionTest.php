<?php

/**
 * The GraphQL API is a Pro feature, and the edition is what decides it.
 *
 * This existed as a promise in the readme and the docs long before it existed
 * in the code: the queries were registered for everybody, and the only thing
 * standing in front of them was the schema scope, which an admin grants for
 * their own reasons and which says nothing about the licence. A Lite install
 * that ticked "Read traffic reports" got the whole API.
 *
 * The refusal is the only case worth pinning. Building the query list for Pro
 * instantiates Craft's GraphQL types, which needs an application; the empty
 * answer needs nothing, which is exactly why it can be tested here.
 */

use coyshdigital\craftanalytics\gql\AnalyticsQuery;

it('registers no queries on Lite', function() {
    expect(AnalyticsQuery::getQueries(checkToken: false, isPro: false))->toBe([]);
});

it('does not consult the schema scope before the edition', function() {
    // checkToken: true would reach Craft's Gql helper and fatal without an
    // application. Returning early on the edition is what keeps this green,
    // so this fails the moment the two checks are reordered.
    expect(AnalyticsQuery::getQueries(checkToken: true, isPro: false))->toBe([]);
});
