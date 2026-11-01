# env-doctor

CLI PHP **sans dépendance** qui attrape un `.env` cassé **avant** le déploiement.

Le cas classique Laravel / Symfony / n'importe quel dotenv : `.env.example` a dérivé, `APP_KEY` est vide, un mot de passe d'exemple (`changeme`, `smtp.example.com`) est parti en prod, ou pire, un vrai token a été collé dans le fichier d'exemple qui va être commité.

Zero-dependency PHP CLI. Compare `.env` with `.env.example`, fail the build on missing keys and on credentials that landed in a file you are about to commit.

## Lancer

PHP 8.1+ suffit. Pas de Composer, pas d'extension.

```bash
php bin/env-doctor check --example examples/app.env.example --env examples/app.env
php bin/env-doctor check --example examples/app.env.example --env examples/app.env.broken
php bin/env-doctor lint .env.example
php bin/env-doctor secrets .env.example --format=json
```

Dans un projet, à la racine :

```bash
php /chemin/vers/env-doctor/bin/env-doctor check
```

Par défaut : `--example .env.example` et `--env .env`.

## Ce qui est signalé

| Code | Niveau | Sens |
| --- | --- | --- |
| `missing_key` | error | Clé présente dans l'exemple, absente du `.env` |
| `app_key_empty` | error | `APP_KEY` vide ou encore un placeholder. `php artisan key:generate` |
| `committed_secret` | error | Forme connue de secret (AWS, GitHub `ghp_`, Stripe live, Slack, Google, OpenAI, JWT, clé privée) dans un fichier d'exemple |
| `malformed_line` / `unclosed_quote` | error | Ligne illisible |
| `stale_placeholder` | warning | La valeur du `.env` est encore celle de l'exemple (`changeme`, `example.com`, clé AWS de la doc…) |
| `empty_value` | warning | Valeur vide alors que l'exemple montrait un échantillon |
| `duplicate_key` | warning | Clé en double, la dernière gagne |
| `unquoted_whitespace` | warning | Espace dans une valeur non quotée |
| `hash_comment` | warning | `#` non quoté avale la valeur (`COLOR=#fff` devient vide). Écrire `COLOR="#fff"` |
| `extra_key` | info | Présente dans `.env` mais pas dans l'exemple. Passe en error avec `--strict-extra` |

La clé d'exemple documentée par AWS (`AKIAIOSFODNN7EXAMPLE`) n'est pas traitée comme une fuite. La recopier telle quelle dans le `.env` reste un placeholder oublié.

`--strict` fait échouer le processus aussi sur les warnings.

## Codes de sortie

| Code | Signification |
| --- | --- |
| 0 | Rien de bloquant |
| 1 | Erreurs (ou warnings si `--strict`) |
| 2 | Mauvais arguments, ou fichier illisible |

`--format json` écrit un seul objet JSON sur stdout, pratique en CI :

```json
{
  "ok": false,
  "errors": 1,
  "warnings": 0,
  "infos": 0,
  "summary": "Checked .env.example (12 keys) against .env (11 keys).",
  "issues": []
}
```

## CI

```yaml
- name: Dotenv preflight
  run: php bin/env-doctor check --example .env.example --env .env --strict-extra --format=json
```

Le workflow du dépôt lance `php tests/run.php` sur PHP 8.1, 8.2, 8.3 et 8.4.

## Limites

Les motifs de secrets sont des heuristiques, pas un audit. Un mot de passe banal (`hunter2`) ne sera pas reconnu, et l'outil ne remplace pas un gestionnaire de secrets. Il empêche les oublis qui cassent un deploy ou qui finissent sur git.

Les valeurs entre guillemets simples sont littérales (pas d'échappement). Les guillemets doubles comprennent `\n`, `\r`, `\t`, `\"` et `\\`, y compris sur plusieurs lignes.

## Tests

```bash
php tests/run.php
```

## Licence

MIT.
