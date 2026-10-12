# Claude instructions for `mod_googlemeet`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. **Fleet-wide standards live in `~/dev/CLAUDE.md`** (coding
style, CI gates, lang-string rules, the `mdl` environment, git rules) — do not
repeat them here. This file keeps only what is true for this plugin, as rules
with a one-line reason each; keep it under ~250 lines. Measurements, incidents
and audit reports go to `docs/` (export-ignored) with a pointer from the rule.

Plugin context: a Moodle **activity module** ("Google Meet™ for Moodle") that creates Google
Meet rooms through the Google Calendar API, lists the sessions, syncs their recordings (with Meet
transcripts and "Notes by Gemini") from Google Drive, and optionally analyses a recording with the
Google Gemini API (summary, key points, chapters, practice questions published to the question
bank). It owns 13 tables (`db/install.xml`), five of them keyed to a user (notification log,
recording subscriptions, viewing progress, practice attempts, attendance). It talks to Google
through a core OAuth 2 issuer (`\core\oauth2\rest`) and to Gemini with an admin API key. Supports
Moodle **5.2 and 5.3** (`$plugin->requires = 2026042000`, `$plugin->supported = [502, 503]`).
CI is the moodle-an-hochschulen reusable workflow, one job per supported branch in
`.github/workflows/ci.yml` — **update those jobs when `supported` changes**.

**Lineage.** A fork of `hyukudan/moodle-mod_googlemeet` (itself a fork of
`ronefel/moodle-mod_googlemeet`). The owner chose on 2026-10-12 to diverge: this repo follows
the fleet standards, and upstream releases are merged by hand (expect conflicts). Keep every
third-party `@copyright` line where it is; new files carry the fleet copyright.

## Agent orchestration budget (fleet rule, repeated here on purpose)

Section 6 of `~/dev/CLAUDE.md` (`moodle-dev/CLAUDE.fleet.md`) is the authority and says why.
This short copy reaches sessions that do not load that file: cloud sessions and checkouts
outside `~/dev`. Every subagent gets the model and effort of its role from its agent
definition, and none runs on the session model.

| Role | model | effort | agent |
|---|---|---|---|
| Mechanical sweeps, greps, renames, counts, log reading | `haiku` | `medium` | `fleet-sweeper` |
| Checklists against evidence (handoff counts, spec lines against a sweep log, lang lockstep) | `haiku` | `high` | `fleet-checker` |
| Readers, measurers, graders | `sonnet` | `medium` | `fleet-reader` |
| Refuters and verifiers of a blocking finding | `sonnet` | `high` | `fleet-verifier` |
| Well-scoped implementation (established cause, settled design, written recipe) | `sonnet` | `medium` | `fleet-fixer` |
| Non-trivial implementation (open design, several files, long tasks) | `opus` | `high` | `fleet-implementer` |
| Consolidators, critics, estimators, ADR and documentation drafters | `opus` | `high` | `fleet-synthesist` |

- Launch the `Agent` tool with `subagent_type: "fleet-*"`; it has no `effort` parameter, so
  the role's effort comes from that definition (`mdl claude-setup` installs them). Where they
  are not installed, pass `model`. Aliases only; never `fable`; `xhigh` only for a long-horizon
  implementer whose prompt says why; never `xhigh`/`max` on Sonnet or Haiku.
- No long command inside a subagent: `mdl ci --matrix`, `mdl mutate` and Behat run from the
  main session in a background Bash command; a subagent runs the fast gate its prompt names and
  reports the command with its counts.
- Workflows only on the user's opt-in, every `agent()` with `agentType: 'fleet-*'`, under 10
  agents. Advisor off by default.

## Commands

```sh
mdl ci moodle-mod_googlemeet --matrix --behat      # the pre-merge gate (5.3 full, 5.2 one DB)
mdl ci moodle-mod_googlemeet --branch MOODLE_503_STABLE --php 8.4 --only phpcs,phpdoc
mdl phpunit m502 mod_googlemeet                    # once the plugin is mounted (plugins.conf)
mdl grunt m502 mod/googlemeet                      # rebuild amd/build (commit with src)
```

In a Claude Code cloud session none of `mdl`, the stacks or `~/dev/CLAUDE.md` exist; use `mpci`
(see `moodle-dev/cloud/README.md`) and leave the merge to the owner after the local matrix.

Plugin CLI scripts (all `CLI_SCRIPT`, run from the Moodle root): `cli/backfill_chapters.php`,
`cli/backfill_questions.php`, `cli/process_transcripts.php`, `cli/cleanup_orphans.php`.

## Code layout

```
lib.php, locallib.php        legacy API callbacks + page builders (large; new logic goes to classes/local)
classes/client.php           Google OAuth 2 client (Calendar, Drive), per-teacher tokens
classes/rest.php             \core\oauth2\rest endpoints for Calendar/Drive/Meet
classes/gemini_client.php    Gemini REST + File API upload (core \curl::put, see gotchas)
classes/ai_service.php       analysis orchestration; classes/question_service.php practice questions
classes/external.php         legacy WS class (mod_googlemeet_external); classes/external/* newer WS
classes/local/               sync_manager, calendar_sync, attendance/*, ai_review, report_builder,
                             recording_cleanup (cascade delete, trash), reminders, impersonation
classes/task/                13 scheduled/adhoc tasks (autosync, notifications, AI, attendance)
classes/privacy/provider.php metadata + request + userlist providers for all user tables
templates/, amd/src/         recording hub, recordings table, practice, AI review UI (jQuery-based)
backup/moodle2/              backup/restore (AI content travels without user data)
```

## Versions

`$plugin->version` is date-based (`YYYYMMDDXX`) on the single `main` branch, which serves both
5.2 and 5.3. Release strings carry the fork suffix (`<upstream release>+uai.<n>`). Version bump
plus `CHANGELOG.md` in the same commit as JS rebuilds, services or DB changes.

## Architecture gotchas

- The organiser is `googlemeet.creatoruserid`, resolved only through `classes/local/creator.php`;
  add, edit and restore accept an organiser e-mail only when the acting user may name it, and
  `resolve()` never writes. Cron impersonates whoever it returns, so never bypass it.
- The activity declares FEATURE_GROUPS/GROUPINGS false: `cm->groupmode` is always 0, so group fences
  read the COURSE group mode (`report_builder::is_fenced()`).
- `install.xml` must never declare `DEFAULT=""` on a `char NOT NULL` column: XMLDB prints a
  debugging message on install, and moodle-plugin-ci's `phpunit --install` aborts on it.
- `gemini_client.php` streams the File API upload through core `\curl::put()` turned into a POST
  (`CURLOPT_CUSTOMREQUEST`), so core applies the proxy, `curlsecurityblockedhosts` and DNS pinning;
  `CURLOPT_FOLLOWLOCATION` stays off (core's emulated redirect would replay the API key). The upload
  URL comes from Google's response header: `is_trusted_upload_url()` (https, exactly
  generativelanguage.googleapis.com, `/upload/` path) must keep guarding it.
- Cron acts as the activity's creator (`classes/local/impersonation.php`): it impersonates the
  teacher to use their Google refresh token. Restore `$USER`/`$SESSION` in a `finally`.
- AI output, transcripts and recording names are untrusted text; templates triple-stash some of
  them only after `format_text()` in PHP. Keep that pairing when moving code.
- `calendar_sync::deletion_context()` classifies a deletion from the call stack; on 5.2+ core
  deletes modules through `core_courseformat\local\cmactions::delete()`, not
  `course_delete_module()`, so that check must name the 5.2+ frames too.
- No `.stylelintrc.json` in this repo: stylelint takes the nearest config, so a plugin-level file
  replaces Moodle's whole ruleset and the CSS gate silently checks nothing.
- Students never see unreviewed AI content (`classes/local/ai_review.php`); every WS that
  returns analysis text must apply `ai_review::is_visible_to_user()` AND the recording's
  `visible` flag.

## Testing notes

- `tests/generator/lib.php` creates activities, events and recordings; Behat configures an
  offline Google issuer (`tests/behat/behat_mod_googlemeet.php`) so the recordings list renders
  without network.
- `tests/fixtures/` holds the test doubles (Meet REST source, OAuth/Drive fakes, testable
  clients); one class per file, `require_once`d by the test that uses it.
- Two inherited Mustache lint messages are baselined in `.mustachelintbaseline` (Ionic `*ngIf`
  in the app template; `aria-controls` pointing into the parent template).

## When in doubt

Follow the patterns in existing files.
