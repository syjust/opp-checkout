<?php

namespace App\Service;

use Stripe\Event;
use Stripe\Webhook;

class StripeWebhookService
{
    public function __construct(
        private readonly string $endpointSecret,
    ) {
    }

    public function constructEvent(string $payload, string $sigHeader): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $this->endpointSecret);
    }
}
