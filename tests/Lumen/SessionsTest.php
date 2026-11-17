<?php

// This file is part of Pollaris (Lumen Care adaptation).
// Copyright 2026 Lumen Care
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Tests\Lumen;

use App\Entity;
use App\Tests\Factory;
use App\Tests\Helper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Zenstruck\Foundry;

class SessionsTest extends WebTestCase
{
    use Foundry\Test\Factories;
    use Foundry\Test\ResetDatabase;
    use Helper\CsrfHelper;
    use Helper\FactoryHelper;

    private const WEEKEND = '2027-01-09';

    /**
     * A completed date poll with one proposal (the weekend).
     *
     * @return array{Entity\Poll, Entity\Proposal}
     */
    private function newSession(string $title, int $threshold = 4, string $day = self::WEEKEND): array
    {
        $poll = Factory\PollFactory::new([
            'title' => $title,
            'authorName' => 'Olivier',
            'authorEmail' => 'admin@example.com',
            'notifyOnVotes' => false,
            'confirmThreshold' => $threshold,
            'sessionGroup' => 'formation',
            'closedAt' => null,
            'completedAt' => new \DateTimeImmutable(),
        ])->date()->create();

        $date = Factory\DateFactory::createOne([
            'poll' => $poll,
            'value' => new \DateTimeImmutable($day),
        ]);

        // The DateListener creates the proposal of the date.
        $proposal = $date->getProposals()->first();
        $this->assertNotFalse($proposal);
        $proposal->setLabel('Week-end');
        $this->flush();

        return [$poll, $proposal];
    }

    private function flush(): void
    {
        static::getContainer()->get('doctrine')->getManager()->flush();
    }

    private function clearEntityManager(): void
    {
        static::getContainer()->get('doctrine')->getManager()->clear();
    }

    private function addYes(Entity\Proposal $proposal, string $name, ?string $email = null): Entity\Vote
    {
        $vote = Factory\VoteFactory::createOne([
            'poll' => $proposal->getPoll(),
            'authorName' => $name,
            'authorEmail' => $email ?? strtolower($name) . '@example.com',
        ]);

        Factory\AnswerFactory::createOne([
            'vote' => $vote,
            'proposal' => $proposal,
            'value' => 'yes',
        ]);

        return $vote;
    }

    private function postVote(
        KernelBrowser $client,
        Entity\Poll $poll,
        string $name,
        ?string $email,
        string $value,
    ): void {
        $data = [
            '_token' => $this->getCsrf($client, 'vote'),
            'authorName' => $name,
            'answers' => [
                ['value' => $value],
            ],
        ];

        if ($email !== null) {
            $data['authorEmail'] = $email;
        }

        $client->request(Request::METHOD_POST, "/polls/{$poll->getSlug()}", ['vote' => $data]);
    }

    private function confirmUrl(Entity\Poll $poll, Entity\Proposal $proposal, string $action = 'confirm'): string
    {
        return "/polls/{$poll->getId()}/{$poll->getAdminToken()}/sessions/{$proposal->getId()}/{$action}";
    }

    public function testVoteRequiresEmailWhenThresholdIsSet(): void
    {
        $client = static::createClient();
        [$poll] = $this->newSession('Session 1');

        $this->postVote($client, $poll, 'Alix', null, 'yes');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, Factory\VoteFactory::count());
    }

    public function testVoteSavesEmail(): void
    {
        $client = static::createClient();
        [$poll] = $this->newSession('Session 1');

        $this->postVote($client, $poll, 'Alix', 'alix@example.com', 'yes');

        $this->assertResponseRedirects("/polls/{$poll->getSlug()}", 302);
        $votes = Factory\VoteFactory::all();
        $this->assertSame(1, count($votes));
        $this->assertSame('alix@example.com', $votes[0]->getAuthorEmail());
        $this->assertEmailCount(0);
    }

    public function testThresholdAlertIsSentOnceToTheAdmin(): void
    {
        $client = static::createClient();
        [$poll, $proposal] = $this->newSession('Session 1', threshold: 2);
        $this->addYes($proposal, 'Alix');

        $this->postVote($client, $poll, 'Bea', 'bea@example.com', 'yes');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailAddressContains($email, 'To', 'admin@example.com');
        $this->assertEmailHeaderSame($email, 'Subject', '[Pollaris] Seuil atteint : Session 1');
        $this->assertEmailTextBodyContains($email, 'Alix');
        $this->assertEmailTextBodyContains($email, 'bea@example.com');
        $this->assertNotNull(Factory\ProposalFactory::find($proposal->getId())->getThresholdNotifiedAt());

        // A third "yes" doesn't alert again.
        $this->postVote($client, $poll, 'Chloé', 'chloe@example.com', 'yes');
        $this->assertEmailCount(0);
    }

    public function testConfirmNotifiesParticipantsAndBlocksSameDateInOtherSessions(): void
    {
        $client = static::createClient();
        [$poll1, $proposal1] = $this->newSession('Session 1', threshold: 2);
        [$poll2, $proposal2] = $this->newSession('Session 2', threshold: 2);
        [$poll3, $proposal3] = $this->newSession('Session 3', threshold: 2, day: '2027-01-23');
        $this->addYes($proposal1, 'Alix');
        $this->addYes($proposal1, 'Bea');
        $this->addYes($proposal1, 'Sans', email: '');

        $client->request(Request::METHOD_GET, "/polls/{$poll1->getId()}/{$poll1->getAdminToken()}/admin");
        $client->request(Request::METHOD_POST, $this->confirmUrl($poll1, $proposal1), [
            '_csrf_token' => $this->getCsrf($client, 'lumen confirm session'),
        ]);

        $this->assertResponseRedirects();
        $this->assertNotNull(Factory\ProposalFactory::find($proposal1->getId())->getConfirmedAt());
        // Same date in Session 2: blocked. Other date in Session 3: untouched.
        $this->assertSame($proposal1->getId(), Factory\ProposalFactory::find($proposal2->getId())->getBlockedBy()?->getId());
        $this->assertNull(Factory\ProposalFactory::find($proposal3->getId())->getBlockedBy());
        // Only the participants with an email are notified.
        $this->assertEmailCount(2);
        $email = $this->getMailerMessage(0);
        $this->assertNotNull($email);
        $this->assertEmailHeaderSame($email, 'Subject', '[Pollaris] Session confirmée : Session 1');
        $this->assertEmailTextBodyContains($email, 'samedi 9 janvier 2027');
    }

    public function testConfirmFailsWithInvalidCsrf(): void
    {
        $client = static::createClient();
        [$poll, $proposal] = $this->newSession('Session 1');

        $client->request(Request::METHOD_POST, $this->confirmUrl($poll, $proposal), [
            '_csrf_token' => 'not the token',
        ]);

        $this->assertResponseRedirects();
        $this->assertNull(Factory\ProposalFactory::find($proposal->getId())->getConfirmedAt());
    }

    public function testConfirmFailsWithInvalidAdminToken(): void
    {
        $client = static::createClient();
        [$poll, $proposal] = $this->newSession('Session 1');

        $client->request(
            Request::METHOD_POST,
            "/polls/{$poll->getId()}/not-the-token/sessions/{$proposal->getId()}/confirm",
            ['_csrf_token' => $this->getCsrf($client, 'lumen confirm session')],
        );

        $this->assertResponseStatusCodeSame(404);
        $this->assertNull(Factory\ProposalFactory::find($proposal->getId())->getConfirmedAt());
    }

    public function testLateYesOnConfirmedSessionGetsConfirmationEmail(): void
    {
        $client = static::createClient();
        [$poll, $proposal] = $this->newSession('Session 1', threshold: 1);
        $proposal->setConfirmedAt(new \DateTimeImmutable());
        $proposal->setThresholdNotifiedAt(new \DateTimeImmutable());
        $this->flush();

        $this->postVote($client, $poll, 'Dan', 'dan@example.com', 'yes');

        $this->assertEmailCount(1);
        $email = $this->getMailerMessage();
        $this->assertNotNull($email);
        $this->assertEmailAddressContains($email, 'To', 'dan@example.com');
        $this->assertEmailHeaderSame($email, 'Subject', '[Pollaris] Session confirmée : Session 1');
    }

    public function testNewVoteCannotSayYesOnBlockedDate(): void
    {
        $client = static::createClient();
        [, $proposal1] = $this->newSession('Session 1');
        [$poll2, $proposal2] = $this->newSession('Session 2');
        $proposal1->setConfirmedAt(new \DateTimeImmutable());
        $proposal2->setBlockedBy($proposal1);
        $this->flush();

        $this->postVote($client, $poll2, 'Eve', 'eve@example.com', 'yes');

        $this->assertResponseStatusCodeSame(422);
        $this->assertSame(0, Factory\VoteFactory::count());

        $this->postVote($client, $poll2, 'Eve', 'eve@example.com', 'no');

        $this->assertResponseRedirects("/polls/{$poll2->getSlug()}", 302);
        $this->assertSame(1, Factory\VoteFactory::count());
    }

    public function testUnconfirmFreesTheDateInOtherSessions(): void
    {
        $client = static::createClient();
        [$poll1, $proposal1] = $this->newSession('Session 1');
        [, $proposal2] = $this->newSession('Session 2');
        $proposal1->setConfirmedAt(new \DateTimeImmutable());
        $proposal2->setBlockedBy($proposal1);
        $this->flush();

        $client->request(Request::METHOD_GET, "/polls/{$poll1->getId()}/{$poll1->getAdminToken()}/admin");
        $client->request(Request::METHOD_POST, $this->confirmUrl($poll1, $proposal1, 'unconfirm'), [
            '_csrf_token' => $this->getCsrf($client, 'lumen unconfirm session'),
        ]);

        $this->assertResponseRedirects();
        $this->assertNull(Factory\ProposalFactory::find($proposal1->getId())->getConfirmedAt());
        $this->assertNull(Factory\ProposalFactory::find($proposal2->getId())->getBlockedBy());
        $this->assertEmailCount(0);
    }

    public function testAdminPageShowsSessionsAndStrongerCompetitorWarning(): void
    {
        $client = static::createClient();
        [$poll1, $proposal1] = $this->newSession('Session 1', threshold: 1);
        [, $proposal2] = $this->newSession('Session 2', threshold: 1);
        $this->addYes($proposal1, 'Alix');
        $this->addYes($proposal2, 'Bea');
        $this->addYes($proposal2, 'Chloé');

        $client->request(Request::METHOD_GET, "/polls/{$poll1->getId()}/{$poll1->getAdminToken()}/admin");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('#sessions', '1 / 1 oui');
        $this->assertSelectorTextContains('#sessions', 'alix@example.com');
        $this->assertSelectorTextContains('#sessions', '« Session 2 » a plus de « oui »');
    }

    public function testPollPageShowsCounterAndConfirmedBadge(): void
    {
        $client = static::createClient();
        [$poll, $proposal] = $this->newSession('Session 1');
        $this->addYes($proposal, 'Alix');
        $proposal->setConfirmedAt(new \DateTimeImmutable());
        $this->flush();

        $this->clearEntityManager();
        $client->request(Request::METHOD_GET, "/polls/{$poll->getSlug()}");

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.proposals-table__sum-line', '1/4');
        $this->assertSelectorTextContains('thead', 'Confirmée');
        $this->assertSelectorExists('input[name="vote[authorEmail]"]');
    }
}
