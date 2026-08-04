<?php

use coyshdigital\craftanalytics\db\SchemaBuilder;
use coyshdigital\craftanalytics\db\Table;
use coyshdigital\craftanalytics\migrations\Install;
use coyshdigital\craftanalytics\models\Funnel;
use coyshdigital\craftanalytics\models\Goal;
use coyshdigital\craftanalytics\services\FunnelsService;
use coyshdigital\craftanalytics\services\GoalsService;
use coyshdigital\craftanalytics\tests\TestDb;
use craft\db\Query;

/**
 * Goals and funnels are written straight to the database.
 *
 * They used to be written to project config and mirrored here by its event
 * handlers, which meant they could not be created in production at all. These
 * cover the write paths that replaced that, because nothing else did: the old
 * tests exercised the config plumbing, and the plumbing is gone.
 */
beforeEach(function() {
    if (!TestDb::available()) {
        $this->markTestSkipped('No test database configured (CRAFT_ANALYTICS_TEST_* env vars).');
    }

    $db = TestDb::connection();
    TestDb::dropTables(SchemaBuilder::allTables());
    (new Install(['db' => $db]))->up();

    $this->goals = new GoalsService(['db' => $db]);
    $this->funnels = new FunnelsService(['db' => $db, 'goals' => $this->goals]);
});

function newGoal(string $handle, string $target = '/thank-you', int $sortOrder = 0): Goal
{
    $goal = new Goal();
    $goal->name = ucfirst($handle);
    $goal->handle = $handle;
    $goal->type = 'url';
    $goal->target = $target;
    $goal->sortOrder = $sortOrder;

    return $goal;
}

test('a saved goal comes back with an id and a uid', function() {
    $goal = newGoal('signup');

    expect($this->goals->save($goal))->toBeTrue()
        ->and($goal->id)->toBeGreaterThan(0)
        ->and($goal->uid)->not->toBe('');

    $this->goals->clearCaches();
    $stored = $this->goals->getByHandle('signup');

    expect($stored)->not->toBeNull()
        ->and($stored->id)->toBe($goal->id)
        ->and($stored->target)->toBe('/thank-you');
});

test('saving an existing goal updates it rather than inserting a second', function() {
    $goal = newGoal('signup');
    $this->goals->save($goal);

    $goal->target = '/welcome';
    expect($this->goals->save($goal))->toBeTrue();

    $this->goals->clearCaches();

    expect($this->goals->all())->toHaveCount(1)
        ->and($this->goals->getByHandle('signup')->target)->toBe('/welcome');
});

test('a duplicate handle is a form error, not an integrity violation', function() {
    // The column is uniquely indexed, so without this check the insert throws
    // and the operator gets a 500 instead of being told which field is wrong.
    $this->goals->save(newGoal('signup'));

    $clash = newGoal('signup', '/somewhere-else');

    expect($this->goals->save($clash))->toBeFalse()
        ->and($clash->getErrors('handle'))->not->toBeEmpty();

    $this->goals->clearCaches();
    expect($this->goals->all())->toHaveCount(1);
});

test('reordering rewrites sort order and survives a re-read', function() {
    $first = newGoal('alpha', '/a');
    $second = newGoal('beta', '/b');
    $third = newGoal('gamma', '/c');

    foreach ([$first, $second, $third] as $goal) {
        $this->goals->save($goal);
    }

    $this->goals->reorder([$third->uid, $first->uid, $second->uid]);
    $this->goals->clearCaches();

    expect(array_map(static fn(Goal $g): string => $g->handle, $this->goals->all()))
        ->toBe(['gamma', 'alpha', 'beta']);
});

test('deleting a goal takes its conversions with it', function() {
    // The cascade is deliberate: a conversion count nobody can name a goal for
    // is worse than no count. The CP warns before this happens.
    $goal = newGoal('signup');
    $this->goals->save($goal);

    TestDb::connection()->createCommand()->insert(Table::GOALS_ROLLUP, [
        'siteId' => 1,
        'date' => '2026-07-16',
        'goalId' => $goal->id,
        'conversions' => 12,
    ])->execute();

    $this->goals->deleteByUid($goal->uid);
    $this->goals->clearCaches();

    expect($this->goals->all())->toBeEmpty()
        ->and((new Query())->from([Table::GOALS_ROLLUP])->exists(TestDb::connection()))->toBeFalse();
});

test('a funnel saves its steps in order, resolved to goal ids', function() {
    foreach (['landed', 'basket', 'checkout'] as $i => $handle) {
        $this->goals->save(newGoal($handle, '/' . $handle, $i));
    }

    $funnel = new Funnel();
    $funnel->name = 'Purchase';
    $funnel->handle = 'purchase';
    $funnel->steps = ['landed', 'basket', 'checkout'];

    expect($this->funnels->save($funnel))->toBeTrue()
        ->and($funnel->id)->toBeGreaterThan(0);

    $stored = (new FunnelsService(['db' => TestDb::connection(), 'goals' => $this->goals]))
        ->getByHandle('purchase');

    expect($stored->steps)->toBe(['landed', 'basket', 'checkout']);
});

test('a funnel step naming a goal that does not exist is refused, not silently dropped', function() {
    // saveSteps() skips an unresolvable step, which is right for a one-way
    // import and wrong for a form: the funnel would look saved and then report
    // drop-off across a step that was never stored.
    $this->goals->save(newGoal('landed', '/landed'));
    $this->goals->save(newGoal('basket', '/basket'));

    $funnel = new Funnel();
    $funnel->name = 'Purchase';
    $funnel->handle = 'purchase';
    $funnel->steps = ['landed', 'basket', 'nonexistent'];

    expect($this->funnels->save($funnel))->toBeFalse()
        ->and($funnel->getErrors('steps'))->not->toBeEmpty();

    expect((new Query())->from([Table::FUNNELS])->exists(TestDb::connection()))->toBeFalse();
});

test('resaving a funnel replaces its steps rather than appending', function() {
    foreach (['landed', 'basket', 'checkout'] as $i => $handle) {
        $this->goals->save(newGoal($handle, '/' . $handle, $i));
    }

    $funnel = new Funnel();
    $funnel->name = 'Purchase';
    $funnel->handle = 'purchase';
    $funnel->steps = ['landed', 'basket', 'checkout'];
    $this->funnels->save($funnel);

    $funnel->steps = ['landed', 'checkout'];
    $this->funnels->save($funnel);

    $count = (new Query())
        ->from([Table::FUNNEL_STEPS])
        ->where(['funnelId' => $funnel->id])
        ->count('*', TestDb::connection());

    expect((int)$count)->toBe(2);

    $stored = (new FunnelsService(['db' => TestDb::connection(), 'goals' => $this->goals]))
        ->getByHandle('purchase');

    expect($stored->steps)->toBe(['landed', 'checkout']);
});

test('a goal created in the same request can be used as a funnel step', function() {
    // The goal list is memoised for the request, so a funnel saved just after
    // the goal it names would otherwise resolve against the list as it was
    // before that save and drop the step.
    $this->goals->save(newGoal('landed', '/landed'));
    $this->goals->save(newGoal('brandnew', '/new'));

    $funnel = new Funnel();
    $funnel->name = 'Fresh';
    $funnel->handle = 'fresh';
    $funnel->steps = ['landed', 'brandnew'];

    expect($this->funnels->save($funnel))->toBeTrue();

    $stored = (new FunnelsService(['db' => TestDb::connection(), 'goals' => $this->goals]))
        ->getByHandle('fresh');

    expect($stored->steps)->toBe(['landed', 'brandnew']);
});
