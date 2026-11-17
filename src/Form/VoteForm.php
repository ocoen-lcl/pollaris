<?php

// This file is part of Pollaris.
// Copyright 2024-2026 Marien Fressinaud
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Form;

use App\Entity;
use App\Validator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Contracts\Translation\TranslatorInterface;

class VoteForm extends AbstractType
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('authorName', Type\TextType::class, [
            'trim' => true,
            'empty_data' => '',
            'label' => new TranslatableMessage('forms.vote_form.author_name.label'),
            'attr' => [
                'maxlength' => Entity\Vote::MAX_AUTHOR_NAME_LENGTH,
                'data-form-leave-confirmation-target' => 'input',
            ],
        ]);

        $builder->add('answers', Type\CollectionType::class, [
            'entry_type' => AnswerForm::class,
            'entry_options' => [
                'label' => false,
            ],
        ]);

        $builder->add('submit', Type\SubmitType::class, [
            'label' => new TranslatableMessage('forms.vote_form.submit.label'),
            'attr' => [
                'class' => 'button--primary',
                'data-form-vote-validation-target' => 'submitButton',
            ],
        ]);

        $builder->addEventListener(FormEvents::POST_SUBMIT, [self::class, 'checkBlockedProposals']);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $form = $event->getForm();
            $vote = $event->getData();

            $poll = $vote->getPoll();

            // Lumen Care: the email is needed to tell the participant that
            // the session is confirmed.
            if ($poll->hasConfirmThreshold()) {
                $form->add('authorEmail', Type\EmailType::class, [
                    'trim' => true,
                    'required' => true,
                    'label' => 'Votre adresse e-mail',
                    'help' => 'Pour vous prévenir dès que la session est confirmée. Elle n’est visible que par l’organisateur.',
                    'attr' => [
                        'maxlength' => 255,
                        'autocomplete' => 'email',
                        'data-form-leave-confirmation-target' => 'input',
                    ],
                    'constraints' => [
                        new Assert\NotBlank(message: 'Indiquez votre adresse e-mail.'),
                    ],
                ]);
            }

            if ($poll->isVotePasswordProtected()) {
                $form->add('password', Type\PasswordType::class, [
                    'label' => new TranslatableMessage('forms.vote_form.password.label'),
                    'help' => new TranslatableMessage('forms.vote_form.password.help'),
                    'mapped' => false,
                    'constraints' => [
                        new Assert\NotBlank(
                            message: new TranslatableMessage('poll.password.required', domain: 'validators'),
                        ),
                        new Validator\PollPassword(
                            message: new TranslatableMessage('poll.password.incorrect', domain: 'validators'),
                            poll: $poll,
                        ),
                    ],
                ]);
            }

            if (count($vote->getAnswers()) > 0) {
                return;
            }

            $proposals = $poll->getProposals();

            foreach ($proposals as $proposal) {
                $answer = new Entity\Answer();
                $answer->setProposal($proposal);
                $vote->addAnswer($answer);
            }
        });
    }

    /**
     * Lumen Care: a new vote can't say "yes" or "maybe" to a date taken by
     * another session.
     */
    public static function checkBlockedProposals(FormEvent $event): void
    {
        $form = $event->getForm();
        $vote = $form->getData();

        if (!$vote instanceof Entity\Vote || $vote->getId() !== null) {
            return;
        }

        foreach ($vote->getAnswers() as $answer) {
            if (
                in_array($answer->getValue(), ['yes', 'maybe'], true) &&
                $answer->getProposal()?->isBlocked()
            ) {
                $form->addError(new FormError(
                    'Une des dates choisies vient d’être réservée pour une autre session. Merci de vérifier vos choix.'
                ));
                return;
            }
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $leaveConfirmation = $this->translator->trans('forms.vote_form.leave_confirmation');
        $requiredChoice = $this->translator->trans('forms.vote_form.required_choice');

        $resolver->setDefaults([
            'data_class' => Entity\Vote::class,
            'attr' => [
                'data-controller' => 'form-leave-confirmation form-vote-validation',
                'data-action' => (
                    'form-leave-confirmation#disableCheck'
                    . ' beforeunload@window->form-leave-confirmation#check'
                    . ' turbo:before-visit@window->form-leave-confirmation#check'
                    . ' submit->form-vote-validation#onSubmit'
                    . ' change->form-vote-validation#onChange'
                ),
                'data-form-leave-confirmation-confirmation-value' => $leaveConfirmation,
                'data-form-vote-validation-required-choice-value' => $requiredChoice,
            ],
        ]);
    }
}
