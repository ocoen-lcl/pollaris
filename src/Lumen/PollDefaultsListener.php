<?php

// This file is part of Pollaris (Lumen Care adaptation).
// Copyright 2026 Lumen Care
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Lumen;

use App\Entity;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Apply the default threshold and session group to the new polls.
 */
#[AsEntityListener(event: Events::prePersist, method: 'prePersist', entity: Entity\Poll::class)]
class PollDefaultsListener
{
    public function __construct(
        #[Autowire('%lumen.default_threshold%')]
        private int $defaultThreshold,
        #[Autowire('%lumen.default_group%')]
        private string $defaultGroup,
    ) {
    }

    public function prePersist(Entity\Poll $poll): void
    {
        if ($this->defaultThreshold > 0 && $poll->getConfirmThreshold() === null) {
            $poll->setConfirmThreshold($this->defaultThreshold);
        }

        if ($this->defaultGroup !== '' && $poll->getSessionGroup() === null) {
            $poll->setSessionGroup($this->defaultGroup);
        }
    }
}
