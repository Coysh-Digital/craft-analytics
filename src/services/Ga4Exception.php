<?php

namespace coyshdigital\craftanalytics\services;

/**
 * A failure talking to Google, carrying a message fit to show an operator.
 *
 * Google's own error text is preserved where it helps ("Google Analytics Data
 * API has not been used in project ... before or it is disabled"), because that
 * sentence is usually the whole answer to why an import did not run.
 */
class Ga4Exception extends \RuntimeException
{
}
