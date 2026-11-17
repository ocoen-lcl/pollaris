<?php

// This file is part of Pollaris (Lumen Care adaptation).
// Copyright 2026 Lumen Care
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Lumen;

use App\Entity;
use App\Utils;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Training sessions rules (Lumen Care):
 *
 * - each poll is a session type, polls of the same "session group" compete
 *   for the same dates (weekends);
 * - when a date reaches the poll threshold (e.g. 4 "yes"), the admin is
 *   alerted by email;
 * - the admin confirms the date: participants who said "yes" get an email,
 *   the poll stays open (the threshold is a minimum) and the same date is
 *   blocked in the other polls of the group;
 * - a participant who says "yes" later on a confirmed date gets the email too.
 */
class SessionManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        #[Autowire('%app.name%')]
        private string $appName,
        #[Autowire('%lumen.reply_to%')]
        private string $replyTo,
    ) {
    }

    /**
     * The date of the proposal in French, for the emails (sent without
     * request, so the "dateTrans" Twig filter can't be used).
     */
    public static function describe(Entity\Proposal $proposal): string
    {
        $date = $proposal->getDate();
        $label = trim((string) $proposal->getLabel());

        if (!$date || !$date->getValue()) {
            return $label;
        }

        $formatter = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            null,
            null,
            'EEEE d MMMM yyyy',
        );
        $description = (string) $formatter->format($date->getValue());

        if ($label !== '') {
            $description .= " ({$label})";
        }

        return $description;
    }

    /**
     * Key used to match the "same date" across the polls of a group.
     */
    public static function matchKey(Entity\Proposal $proposal): string
    {
        $date = $proposal->getDate();

        if ($date && $date->getValue()) {
            return 'date:' . $date->getValue()->format('Y-m-d');
        }

        return 'label:' . mb_strtolower(trim((string) $proposal->getLabel()));
    }

    /**
     * @return Entity\Poll[]
     */
    public function siblingPolls(Entity\Poll $poll): array
    {
        $group = $poll->getSessionGroup();

        if ($group === null) {
            return [];
        }

        return $this->entityManager->createQuery(
            'SELECT p FROM App\Entity\Poll p
             WHERE p.sessionGroup = :group AND p.id != :id AND p.completedAt IS NOT NULL
             ORDER BY p.title ASC'
        )->setParameters([
            'group' => $group,
            'id' => $poll->getId(),
        ])->getResult();
    }

    /**
     * Proposals of the other polls of the group on the same date.
     *
     * @return Entity\Proposal[]
     */
    public function competitors(Entity\Proposal $proposal): array
    {
        $poll = $proposal->getPoll();

        if (!$poll) {
            return [];
        }

        $key = self::matchKey($proposal);
        $competitors = [];

        foreach ($this->siblingPolls($poll) as $sibling) {
            foreach ($sibling->getProposals() as $siblingProposal) {
                if (self::matchKey($siblingProposal) === $key) {
                    $competitors[] = $siblingProposal;
                }
            }
        }

        return $competitors;
    }

    /**
     * The competitors which have strictly more "yes" than the proposal.
     *
     * @return Entity\Proposal[]
     */
    public function strongerCompetitors(Entity\Proposal $proposal): array
    {
        $countYes = $proposal->countAnswers('yes');

        return array_values(array_filter(
            $this->competitors($proposal),
            function (Entity\Proposal $competitor) use ($countYes): bool {
                return !$competitor->isBlocked() && $competitor->countAnswers('yes') > $countYes;
            }
        ));
    }

    public function confirm(Entity\Proposal $proposal): void
    {
        if ($proposal->isConfirmed() || $proposal->isBlocked()) {
            return;
        }

        $proposal->setConfirmedAt(Utils\Time::now());

        foreach ($this->competitors($proposal) as $competitor) {
            if (!$competitor->isConfirmed() && !$competitor->isBlocked()) {
                $competitor->setBlockedBy($proposal);
            }
        }

        $this->entityManager->flush();

        foreach ($proposal->getAnswers() as $answer) {
            $this->sendConfirmationIfNeeded($answer);
        }

        $this->entityManager->flush();
    }

    public function unconfirm(Entity\Proposal $proposal): void
    {
        if (!$proposal->isConfirmed()) {
            return;
        }

        $proposal->setConfirmedAt(null);

        foreach ($this->competitors($proposal) as $competitor) {
            if ($competitor->getBlockedBy()?->getId() === $proposal->getId()) {
                $competitor->setBlockedBy(null);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Called after a vote is created or updated.
     */
    public function handleVote(Entity\Vote $vote): void
    {
        $poll = $vote->getPoll();

        if (!$poll->hasConfirmThreshold()) {
            return;
        }

        foreach ($vote->getAnswers() as $answer) {
            $this->sendConfirmationIfNeeded($answer);
        }

        foreach ($poll->getProposals() as $proposal) {
            // The answers collection may not contain the answers of the new
            // vote yet.
            $this->entityManager->refresh($proposal);

            if (
                $proposal->isConfirmed() ||
                $proposal->isBlocked() ||
                $proposal->getThresholdNotifiedAt() !== null ||
                !$proposal->isThresholdReached()
            ) {
                continue;
            }

            $this->sendThresholdAlert($proposal);
            $proposal->setThresholdNotifiedAt(Utils\Time::now());
        }

        $this->entityManager->flush();
    }

    private function sendConfirmationIfNeeded(Entity\Answer $answer): void
    {
        $proposal = $answer->getProposal();
        $vote = $answer->getVote();

        if (
            !$proposal ||
            !$vote ||
            !$proposal->isConfirmed() ||
            $answer->getValue() !== 'yes' ||
            $answer->getConfirmationSentAt() !== null ||
            !$vote->getAuthorEmail()
        ) {
            return;
        }

        $poll = $proposal->getPoll();

        $email = $this->newEmail()
            ->to(new Address((string) $vote->getAuthorEmail(), (string) $vote->getAuthorName()))
            ->subject("[{$this->appName}] Session confirmée : {$poll->getTitle()}")
            ->textTemplate('lumen/emails/session_confirmed.txt.twig')
            ->context([
                'poll' => $poll,
                'proposal' => $proposal,
                'date_label' => self::describe($proposal),
                'vote' => $vote,
            ]);

        $this->mailer->send($email);

        $answer->setConfirmationSentAt(Utils\Time::now());
    }

    private function sendThresholdAlert(Entity\Proposal $proposal): void
    {
        $poll = $proposal->getPoll();

        if (!$poll->getAuthorEmail()) {
            return;
        }

        $participants = [];
        foreach ($proposal->getAnswers() as $answer) {
            if ($answer->getValue() === 'yes') {
                $participants[] = $answer->getVote();
            }
        }

        $email = $this->newEmail()
            ->to(new Address($poll->getAuthorEmail(), $poll->getAuthorName()))
            ->subject("[{$this->appName}] Seuil atteint : {$poll->getTitle()}")
            ->textTemplate('lumen/emails/threshold_reached.txt.twig')
            ->context([
                'poll' => $poll,
                'proposal' => $proposal,
                'date_label' => self::describe($proposal),
                'participants' => $participants,
                'competitors' => $this->competitors($proposal),
            ]);

        $this->mailer->send($email);
    }

    private function newEmail(): TemplatedEmail
    {
        $email = (new TemplatedEmail())->locale('fr_FR');

        if ($this->replyTo !== '') {
            $email->replyTo($this->replyTo);
        }

        return $email;
    }
}
