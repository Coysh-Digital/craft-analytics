<?php

/**
 * Actions behind a URL rule with a token in it must take that token as a
 * method argument.
 *
 * Craft's Request::resolve() hands a matched route's params straight to
 * runAction() and, unlike Yii's own implementation, never merges them back
 * into the query params. So `getRequiredParam('authorId')` on a route like
 * `content/author/<authorId:\d+>` cannot see the value and throws a 400 on a
 * URL that is perfectly valid.
 *
 * That is not hypothetical: both content drill-downs shipped that way and
 * every click on an author or section name returned "Request missing required
 * param" until somebody reported it. A controller test would need a booted
 * Craft application; this reads the signatures instead, which is enough to
 * stop it happening again.
 */

use coyshdigital\craftanalytics\controllers\ContentController;
use coyshdigital\craftanalytics\controllers\GoalsController;

/**
 * @return string[] the names of an action's parameters
 */
function actionParams(string $class, string $method): array
{
    return array_map(
        static fn(ReflectionParameter $p): string => $p->getName(),
        (new ReflectionMethod($class, $method))->getParameters(),
    );
}

test('the content drill-downs take their ids as action arguments', function(string $method, string $param) {
    expect(actionParams(ContentController::class, $method))->toContain($param);
})->with([
    ['actionSection', 'sectionId'],
    ['actionAuthor', 'authorId'],
]);

test('the goal and funnel editors take their uid the same way', function(string $method) {
    // The pattern these two got right, and the reason the fix above is a
    // correction rather than an invention.
    expect(actionParams(GoalsController::class, $method))->toContain('uid');
})->with(['actionEdit', 'actionEditFunnel']);

test('no controller reads a route token out of the request instead', function() {
    // getRequiredParam is fine for a POST body or a query string. It is never
    // right for something that only ever arrives in the path.
    $tokens = ['sectionId', 'authorId', 'uid'];

    foreach (glob(dirname(__DIR__, 2) . '/src/controllers/*.php') ?: [] as $file) {
        $source = (string)file_get_contents($file);

        foreach ($tokens as $token) {
            expect($source)->not->toContain("getRequiredParam('$token')")
                ->and($source)->not->toContain("getParam('$token')");
        }
    }
});
