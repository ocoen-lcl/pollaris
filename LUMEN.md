# Pollaris — adaptation Lumen Care

Ce dépôt est une version modifiée de [Pollaris](https://framagit.org/framasoft/framadate/pollaris)
(logiciel libre sous licence AGPL v3, © Marien Fressinaud et contributeurs), utilisée par
Lumen Care pour organiser ses week-ends de formation sur https://cal.lumen-care.com.

Branche `lumen` = version officielle 1.2.3 + les modifications ci-dessous (marquées « Lumen Care »
dans le code). Modifications sous la même licence AGPL v3.

## Fonctions ajoutées (2026-11-17)

- **Seuil par sondage** (`confirmThreshold`) : une date est « confirmable » à partir de N « oui ».
  Le tableau affiche `n/N` et un badge « Seuil atteint ».
- **E-mail du participant** obligatoire quand un seuil est défini (visible par l'organisateur seulement).
- **Alerte** à l'organisateur quand une date atteint le seuil (une seule fois par date).
- **Confirmation en un clic** depuis la page d'administration (section « Sessions ») :
  e-mail aux participants « oui », le sondage reste ouvert (le seuil est un minimum),
  la même date est **bloquée** dans les autres sondages du même **groupe de sessions** (`sessionGroup`).
  Un participant qui dit « oui » plus tard à une date confirmée reçoit aussi l'e-mail.
  Si une autre session a plus de « oui » sur la date, un avertissement s'affiche
  (règle : la session la plus remplie l'emporte). La confirmation peut être annulée.

- **Accusé de réception** au participant après sa réponse (e-mail HTML) : récapitulatif des dates,
  progression vers le seuil et **lien personnel pour modifier sa réponse** depuis n'importe quel appareil.

## Configuration (.env.local)

```
LUMEN_REPLY_TO="formation@example.com"   # adresse de réponse des e-mails de session
LUMEN_DEFAULT_THRESHOLD=4                # seuil appliqué aux nouveaux sondages (0 = désactivé)
LUMEN_DEFAULT_GROUP="formation"          # groupe appliqué aux nouveaux sondages
```

## Code

- `src/Lumen/` : règles (SessionManager), contrôleur de confirmation, abonné aux votes, valeurs par défaut.
- `templates/lumen/` : section d'administration, badges, e-mails.
- `migrations/Version20261117163839LumenSessions.php` : nouvelles colonnes.
- `tests/Lumen/SessionsTest.php` : tests des règles.
