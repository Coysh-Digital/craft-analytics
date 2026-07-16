<?php

namespace coyshdigital\craftanalytics\integrations;

use coyshdigital\craftanalytics\Plugin;
use Craft;
use yii\base\Event;

/**
 * Counts completed Commerce orders as events, carrying the order total.
 *
 * Wired by class name only when Commerce is installed, so this plugin never
 * requires it.
 *
 * What is recorded is an event named `order` with the order's total as its
 * value. What is *not* recorded: the customer, the email, the address, the
 * line items. Order-level revenue is the analytics question; everything else
 * is already in Commerce, where it belongs, and copying it here would put
 * personal data in an analytics table for no benefit (C5, C6).
 */
class CommerceIntegration
{
    public const EVENT_CLASS = 'craft\commerce\elements\Order';
    public const EVENT_NAME = 'afterCompleteOrder';

    public static function isAvailable(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    public static function attach(): void
    {
        if (!self::isAvailable()) {
            return;
        }

        Event::on(
            self::EVENT_CLASS,
            self::EVENT_NAME,
            static function(Event $event) {
                self::handle($event);
            },
        );
    }

    private static function handle(Event $event): void
    {
        $order = $event->sender;

        if (!is_object($order) || !method_exists($order, 'getTotalPrice')) {
            return;
        }

        // Fires on completion, which is the only moment an order is a
        // conversion. A cart that will never be paid for is not revenue.
        Plugin::getInstance()->getCapture()->trackEvent(
            'order',
            (float)$order->getTotalPrice(),
        );
    }
}
