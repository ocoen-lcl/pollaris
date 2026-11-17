<?php

// This file is part of Pollaris (Lumen Care adaptation).
// Copyright 2026 Lumen Care
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Lumen;

use App\PollActivity\VoteEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class VoteSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            VoteEvent::NEW => 'onVote',
            VoteEvent::UPDATED => 'onVote',
        ];
    }

    public function __construct(
        private SessionManager $sessionManager,
    ) {
    }

    public function onVote(VoteEvent $event): void
    {
        $this->sessionManager->handleVote($event->getVote());
    }
}
