<?php

// This file is part of Pollaris (Lumen Care adaptation).
// Copyright 2026 Lumen Care
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Lumen;

use App\Controller\BaseController;
use App\Entity;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class SessionsController extends BaseController
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {
    }

    #[Route(
        '/polls/{pollId:poll}/{token}/sessions/{proposalId:proposal}/confirm',
        name: 'lumen confirm session',
        methods: ['POST'],
    )]
    public function confirm(
        #[MapEntity(mapping: ['poll' => 'id'])]
        Entity\Poll $poll,
        #[MapEntity(mapping: ['proposal' => 'id'])]
        Entity\Proposal $proposal,
        string $token,
        Request $request,
    ): Response {
        if (!$this->checkAccess($poll, $proposal, $token, $request, 'lumen confirm session')) {
            return $this->redirectToAdmin($poll);
        }

        $this->sessionManager->confirm($proposal);

        $this->addFlash('lumen', 'Session confirmée : les participants ont été prévenus par e-mail.');

        return $this->redirectToAdmin($poll);
    }

    #[Route(
        '/polls/{pollId:poll}/{token}/sessions/{proposalId:proposal}/unconfirm',
        name: 'lumen unconfirm session',
        methods: ['POST'],
    )]
    public function unconfirm(
        #[MapEntity(mapping: ['poll' => 'id'])]
        Entity\Poll $poll,
        #[MapEntity(mapping: ['proposal' => 'id'])]
        Entity\Proposal $proposal,
        string $token,
        Request $request,
    ): Response {
        if (!$this->checkAccess($poll, $proposal, $token, $request, 'lumen unconfirm session')) {
            return $this->redirectToAdmin($poll);
        }

        $this->sessionManager->unconfirm($proposal);

        $this->addFlash('lumen', 'Confirmation annulée : la date est de nouveau libre pour les autres sessions (aucun e-mail envoyé).');

        return $this->redirectToAdmin($poll);
    }

    private function checkAccess(
        Entity\Poll $poll,
        Entity\Proposal $proposal,
        string $token,
        Request $request,
        string $csrfId,
    ): bool {
        if ($poll->getAdminToken() !== $token) {
            throw $this->createNotFoundException('The admin token doesn’t match.');
        }

        if ($proposal->getPoll()?->getId() !== $poll->getId()) {
            throw $this->createNotFoundException('Proposal is not part of the poll');
        }

        return $this->isCsrfTokenValid($csrfId, $request->request->getString('_csrf_token', ''));
    }

    private function redirectToAdmin(Entity\Poll $poll): Response
    {
        return $this->redirectToRoute('poll admin', [
            'id' => $poll->getId(),
            'token' => $poll->getAdminToken(),
            '_fragment' => 'sessions',
        ]);
    }
}
