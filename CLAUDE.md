# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Where this plugin sits

This directory is the `local_embedpoll` plugin and **its own git repository** (remote
`git@github.com:andrewrowatt-masseyuni/moodle-local_embedpoll.git`, branch `main`). Its two
companions are their own repositories too:

| Plugin | Path | Remote |
| --- | --- | --- |
| `filter_embedpoll` | `filter/embedpoll` | `andrewrowatt-masseyuni/moodle-filter_embedpoll` |
| `tiny_embedpoll` | `lib/editor/tiny/plugins/embedpoll` | `andrewrowatt-masseyuni/moodle-tiny_embedpoll` |

Both depend on this one (`$plugin->dependencies`), and a change to polls usually touches more than
one of the three. Each repository's CI fetches the others from `main` with
`moodle-plugin-ci add-plugin`, so a change that needs more than one must be pushed to all of them
before CI can pass.

All three are checked out inside a **Moodle 4.5 core checkout** (branch `MOODLE_405_STABLE`) at
`/home/arowatt/moodle405_filter_embedpoll`, the development host and the session's working
directory. Commit plugin work from inside each plugin's own directory; never commit into the outer
core repo. `local/codechecker`, `local/moodlecheck`, `theme/snap` and `lib/editor/tiny/plugins/c4l`
are also untracked there and unrelated to polls.

The design copies `local_edguidance` (`/home/arowatt/moodle405_local_edguidance`), which embeds
teacher guidance the same way; look there when a pattern here is unclear.

[README.md](README.md) is the design record for all three plugins: *why* each non-obvious decision
was made, with the failure it prevents. **Read the relevant section before changing that area, and
update it in the same change.** Notes below are operating context only.

`filter/embedpoll/filter.php` is only a `class_alias` shim; the filter's code is in
`classes/text_filter.php`.

## Commands

From the Moodle root, inside the container (`docker exec moodle405_filter_embedpoll-webserver-1 ...`,
cwd `/var/www/html`):

```bash
vendor/bin/phpunit --testsuite local_embedpoll_testsuite      # also filter_ and tiny_embedpoll_testsuite
vendor/bin/phpunit --filter test_merge_keeps_ids_by_text local/embedpoll/tests/poll_test.php   # one test
vendor/bin/phpunit privacy/tests/privacy/provider_test.php
php admin/tool/behat/cli/run.php --tags=@local_embedpoll --format progress   # as -u www-data; also @filter_ and @tiny_embedpoll
php admin/tool/phpunit/cli/init.php && php admin/tool/behat/cli/init.php     # after db/ or version.php changes
php admin/cli/upgrade.php --non-interactive && php admin/cli/purge_caches.php
```

To run one feature, or to take screenshots, call Behat directly (as `-u www-data`):
`vendor/bin/behat --config /var/www/behatdata/behatrun/behat/behat.yml <feature file>`, adding
`--format=moodle_screenshot` for screenshots. `admin/tool/behat/cli/run.php` mangles the JSON in
`--format-settings`.

On the host, from the Moodle root: `../moodle-plugin-ci/bin/moodle-plugin-ci phpcs --max-warnings 0
./local/embedpoll` (and phplint, phpmd, mustache, phpcbf). For PHPDoc, run
`php local/moodlecheck/cli/moodlecheck.php -p=local/embedpoll -f=text` in the container. On the
host, **from the plugin directory**: `grunt --max-lint-warnings=0 amd` (`amd/build` is committed,
so rebuild after every `amd/src` change, then purge caches), `stylelint`, `gherkinlint`. Each
plugin's `.vscode/tasks.json` holds the same tasks for that plugin. CI also runs `validate`,
`savepoints`, `phpdoc --max-warnings 0` and `phpunit --fail-on-warning`.

## How the pieces connect

The README has the detail. This is the map:

* **Adding or editing:** `tiny_embedpoll/ui` opens `local_embedpoll\form\embed_form` with
  `core_form/modalform`. `api::save_embed()` returns a key, and the editor writes only
  `token::html($key)` into the text.
* **Showing:** `filter_embedpoll\text_filter` resolves each key through `poll::for_cm()` (in a
  module context) or `poll::for_sections()` (in a course context), then calls
  `output\widget::render_row()`.
* **Voting:** `amd/src/poll.js` calls `local_embedpoll_vote` and re-renders `templates/poll.mustache`
  from what it returns.
* **Previewing:** `tiny_embedpoll/previews` calls `local_embedpoll_get_previews`, which uses
  `widget::render_preview()`.

All four paths go through `poll::export_for_template()`, the only place results are worked out.
Lifecycle code lives in `lib.php` (draft adoption, deleting an activity's polls), `observer.php`
(section events), `hook_callbacks.php` (course deletion), and `backup/moodle2/`.

## Traps

* **`cmid = 0` is not always a draft.** A section's poll has `cmid = 0` and `sectionid > 0`. Any
  query meant for drafts must also say `sectionid = 0`, or the daily `purge_drafts` deletes
  section polls.
* **Section keys are scoped to the course, not the section**, because a filter only knows the
  context. `api::claim_section_summary()` (on `course_section_updated`) is what stops two sections
  sharing a poll. It writes `course_sections` directly so that it does not re-fire the event.
  Restore holds it off with `api::hold_claims()` while a section step runs.
* **A vote names an option by id, and `poll::merge_options()` is the only thing that assigns
  ids.** An id is never reused or renumbered. Never key votes by position or by label.
* **A student who has not voted must never receive the tallies.** `export_for_template()` leaves
  them out unless `showresults`; do not compute or reveal results client-side.
* **The rendered poll must never contain `data-embedpoll`.** `mod_lesson` filters some text twice,
  and the filter's `test_filtering_twice_is_a_no_op` pins this.
* **The editor's preview lives in a shadow root on the token, never inside it.** Anything inside a
  token is saved into host text, and `token::PATTERN` stops at the first `</div>`. The move arrows
  and the "Click to edit" hint are in the shadow root for the same reason. XPath cannot see into
  it, so Behat must use `behat_tiny_embedpoll`'s steps.
* **Tokens survive only in text displayed with `noclean`.** HTMLPurifier strips `data-*`, and
  `test_cleaned_text_shows_no_poll` pins that a cleaned text shows nothing. Editing core, or a theme,
  is not the answer.
* **The client's `canmanage` only decides what the editor offers.** `embed_form` requires `manage`;
  `local_embedpoll_vote` requires `vote` and refuses drafts. Every new entry point must check again
  on the server.
* **Where the editor button appears is decided in two places.** `tiny_embedpoll\plugininfo::is_enabled()`
  allows a course context only on `/course/modedit.php` and `/course/editsection.php`, and never
  under `AJAX_SCRIPT`. `api::embed_target()` decides what a context's poll belongs to. A new host
  page needs both.
* **`api::adopt_drafts()` reads every string field of the activity's own record**, not just
  `intro`, because a page's content is on the same form as its description.
* **Polls are cached per request.** Call `poll::reset_cache()` after any write, as `api` and the
  test generator do. Tests also call it in `setUp()`.
* **`SITEID` is a string.** Compare it as `(int)SITEID`; a strict comparison with an int never
  matches.
* **The filter and the editor plugin check `get_config('local_embedpoll', 'version')` before doing
  anything**, so that they degrade quietly if this plugin is missing. Keep that check.
* **Behat polls come from the generator, not the editor:** `the following "local_embedpoll > polls"
  exist` (activity idnumber, `embedkey`, comma-separated options) or `"local_embedpoll > section
  polls"`. The feature itself puts the matching token into the activity's `intro`, or the section's
  `summary`.
* **The filter is not switched on when it is installed.** A Behat feature that shows polls on a
  page needs `the "embedpoll" filter is "on"`, and a PHPUnit test that goes through
  `format_text()` must call `filter_set_global_state()` itself.
* `$plugin->supported` is `[405, 405]` in all three. The filter and the editor plugin require
  `local_embedpoll` at 2026101700; raise that when they come to need something newer.
* Bump `version.php` with every `db/` change, paired with a savepoint in `db/upgrade.php`.
* Lang keys are kept in alphabetical order.
