<?php

// This file is part of Pollaris (Lumen Care adaptation).
// Copyright 2026 Lumen Care
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Lumen;

use App\Entity;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TwigExtension extends AbstractExtension
{
    public function __construct(
        private SessionManager $sessionManager,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('lumenCompetitors', [$this, 'competitors']),
            new TwigFunction('lumenStrongerCompetitors', [$this, 'strongerCompetitors']),
        ];
    }

    /**
     * @return Entity\Proposal[]
     */
    public function competitors(Entity\Proposal $proposal): array
    {
        return $this->sessionManager->competitors($proposal);
    }

    /**
     * @return Entity\Proposal[]
     */
    public function strongerCompetitors(Entity\Proposal $proposal): array
    {
        return $this->sessionManager->strongerCompetitors($proposal);
    }
}
