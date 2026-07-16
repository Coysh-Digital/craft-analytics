<?php

namespace coyshdigital\craftanalytics\events;

use coyshdigital\craftanalytics\enums\Channel;
use yii\base\Event;

/**
 * Lets a site add or override referrer host => channel rules.
 */
class RegisterChannelRulesEvent extends Event
{
    /**
     * Host fragments mapped to channels. A fragment matches the host itself
     * or any subdomain of it; a trailing dot ('google.') matches any TLD.
     *
     * @var array<string,Channel>
     */
    public array $rules = [];
}
