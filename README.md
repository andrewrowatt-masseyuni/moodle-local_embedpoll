# Embedded polls (local_embedpoll)

A lightweight, anonymous, single-question poll that teachers add to any text from the editor: a
label (text and media area), a page, a book chapter, a lesson page, an activity description, or a
section summary. There is no activity to set up.

Students vote, and can change their vote later. A student sees the results only after voting.
Teachers and managers always see the results, and by default cannot vote. Votes are cast over AJAX,
so the page does not reload.

The question itself is ordinary text written around the poll. The poll is only the options.

## The three plugins

Embedded polls need three plugins, because Moodle has three extension points they need, and each
belongs to a different type of plugin:

| Plugin | Why it has to be its own plugin |
| --- | --- |
| `local_embedpoll` (this one) | Holds everything else: storage, votes, the poll form, rendering, and backup. It is a *local* plugin because core only lets `format`, `report`, `plagiarism`, `local` and `tool` plugins add data to another module's backup (`backup/moodle2/backup_stepslib.php`). A poll has to follow its activity through backup, restore, duplicate and course copy. |
| `filter_embedpoll` | Replaces each embedded token with the poll. Only a filter sees every piece of formatted text. |
| `tiny_embedpoll` | The TinyMCE button teachers use to add, edit, move and delete polls. |

The other two both declare a dependency on this one.

The design follows `local_edguidance`, which embeds teacher guidance the same way.

## Requirements

| | |
| --- | --- |
| Moodle | 4.5 (build 2024100700). `$plugin->supported` is pinned to `[405, 405]`. |
| PHP | 8.1 or later. |
| Editor | TinyMCE, for adding polls. Polls already in the text show whichever editor is in use. |

## Installation

1. Install all three plugins:

   ```
   local/embedpoll
   filter/embedpoll
   lib/editor/tiny/plugins/embedpoll
   ```

2. Visit **Site administration > Notifications**, or run `php admin/cli/upgrade.php`.

3. Turn on the **Embed poll** filter in **Site administration > Plugins > Filters > Manage
   filters**. Until it is on, no poll is shown on any page.

## Capabilities

| Capability | Default roles | Purpose |
| --- | --- | --- |
| `local/embedpoll:vote` | Student | Cast or change a vote. |
| `local/embedpoll:viewresults` | Non-editing teacher, Teacher, Manager | Always see the results, without voting. |
| `local/embedpoll:manage` | Teacher, Manager | Add, edit, move and delete polls in the editor. |

All three are defined at the course level, so they can be overridden in a course or an activity.

## Settings

*Site administration > Plugins > Local plugins > Embedded polls*

| Setting | Default | Purpose |
| --- | --- | --- |
| **Maximum number of options** (`maxoptions`) | 7 | The most options a poll can have. It must be 2 or more. |

The limit is checked on the server, not only in the form. A poll that already has more options than
the limit, because the limit was lowered after it was written, keeps them all and can still be
saved, but no more can be added to it. Otherwise editing it would mean deleting options, and the
votes cast for them.

At the limit, the tooltip on **Add option** tells teachers to contact Stream Support. That is the
`maxoptionsreached` string, which a site can change with the language customisation tool.

## Usage

In the editor, click the **Poll** button on the toolbar (also under **Insert**). Write each option on
a line of its own, in the order they should appear, then save.

* A new poll starts with three lines. **Add option** adds another, and the bin beside a line
  deletes it. Blank lines are ignored.
* To reorder the options, drag a line by its handle. From the keyboard, press Enter or Space on the
  handle, then choose where the line goes.
* Enter in a line goes to the next line, adding one at the end if there is room.
* A poll needs at least two options, and can have at most seven, unless the site's limit is
  different (see *Settings*). At the limit, **Add option** is greyed out, and its tooltip says why.
* Options must be distinct; options that differ only in case count as the same.
* Each option can be up to 255 characters long.

Set **Compact** to **Yes** to show the options side by side, on one line, rather than one per line.
It suits emoji, Yes/No, or other short sets of options. They wrap onto a second line only when they
do not fit. **Compact** is **No** by default.

A compact poll has no progress bars and no check icons. The chosen option is the tinted button, and
`aria-pressed` gives the same information to screen readers. Progress bars are left out because
they compare options only when every option is the same width, and side-by-side buttons are not.

**Results for each option** sets the figure beside each option once results are revealed:
**Percentage** (the default), **Number of votes**, or **Nothing**. It changes only that figure.
Progress bars and the total number of votes still show, and a student still sees no results until
they vote. A screen reader hears the number of votes in words either way ("67% (2 votes)", "2 votes").

The poll appears in the editor exactly as it will on the page, with the results you can see. It
cannot be voted in there.

* **To edit a poll**, click it, or put the cursor on it and click the button. The button shows as
  pressed while the cursor is on a poll.
* **To delete a poll**, use **Delete** in its form, which asks first. Deleting it by hand does the
  same, without asking. Until the text is saved, undo brings it back.
* **To move a poll**, hover over it and use the up and down arrows at its bottom right. Each click
  moves it past one paragraph or other block.
* **To write above or after a poll**, hover over it and use **Insert space above poll** or
  **Insert space after poll**, beside the arrows. Each adds an empty paragraph there, with the
  cursor in it. The cursor cannot go into a poll, so without them a poll at the start or end of the
  text, or next to another poll, would leave nowhere to type on that side of it.

A teacher without `manage` (a non-editing teacher, by default) sees polls in the editor, but cannot
open, move or change them. Their button is disabled.

### Editing a poll that has votes

Votes follow the **text** of an option:

* An option whose text is unchanged keeps its votes, even when you move it or add other options.
* Rewording an option, or removing it, discards the votes cast for it. Students who voted for it
  can vote again.

The form warns you when the poll already has votes.

## Technical details

### The token

All that goes into the text is an empty element:

```html
<div class="embedpoll-embed" data-embedpoll="0123456789abcdef"></div>
```

The key is 16 random hex characters. The options and votes live in this plugin's tables.

Because the poll has an identity of its own, independent of its text and position:

* Rewording an option does not start a new poll.
* Moving identical polls does not swap their results.
* Two polls with the same options in the same text are two separate polls.

Where the filter does not run, the token renders as nothing: TinyMCE pads an empty div with
`&nbsp;`, so `styles.css` hides tokens that were not replaced.

The token survives because every host this is for displays its text with `noclean`:

* book chapters (`mod/book/view.php`);
* pages (`mod/page/view.php`);
* lesson pages;
* activity descriptions (`format_module_intro()`);
* section summaries.

HTMLPurifier strips `data-*` attributes. So text that *is* cleaned - anywhere, or everywhere under
`$CFG->forceclean` - loses the token, and shows no poll. `filter_embedpoll`'s tests pin this.

A key belongs to one activity. Lookups are always by (activity, key), so a token copied into
another activity does not resolve there:

* someone who may manage polls sees a "not found" notice;
* everyone else sees nothing.

A section's key belongs to its course (see *Polls in section summaries*).

### Data

`local_embedpoll`:

| Column | Role |
| --- | --- |
| `courseid`, `cmid` | Where the poll belongs. `cmid = 0` is either a section's poll or a *draft* (see below). |
| `sectionid` | The section whose summary holds the poll, with `cmid = 0`. It is 0 for an activity's poll and for a draft, so a draft is `cmid = 0 AND sectionid = 0`. |
| `embedkey` | Matches the token. Unique per activity, and unique per course among sections and drafts. |
| `options` | A JSON array of `{id, label}`, in display order. The ids belong to the poll. |
| `compact` | 1 to show the options side by side, on one line. Set by the form's **Compact** setting. |
| `stats` | What each option shows once results are revealed: `percent`, `count` or `none` (`poll::STATS`). |

`local_embedpoll_vote` holds one row per user per poll: `optionid` names the chosen option by its
id. `poll::merge_options()` is the only place that assigns option ids:

* an option keeps its id for as long as its text is exactly the same;
* a new or reworded option gets an id higher than any the poll has used.

So a vote can never come to name a different option.

`api::save_embed()` saves the new options and deletes the votes for any options that are gone, in a
single transaction.

### Rendering

`poll::export_for_template()` is the single source of truth. Three paths use it:

* the filter, for the first render;
* the `local_embedpoll_vote` web service, which re-renders a poll after a vote;
* the editor's preview.

All three use `templates/poll.mustache`, so the results are worked out the same way everywhere.

A student who has not voted never receives the tallies. They are not even sent to the browser.

Polls are read a whole course at a time, and held for the request (`poll::for_course()`). A course
page can run the filter over a dozen texts, and one query per course keeps that cost flat.

Output depends on who is looking. That is safe because Moodle 4.5 has no text cache: filters run
per request, per user.

### The poll form

`form\embed_form` is a `core_form` dynamic form, which `tiny_embedpoll` opens in a modal.

**The options are still submitted as text,** one per line, from the form's `options` textarea. So
validation, saving and `poll::merge_options()` see the options as they always have.
`amd/src/options_editor.js` hides the textarea, puts a line for each option in its place, and writes
the lines back into it whenever they change.

* **Lines are moved with core's `sortable_list`**, which also gives keyboard users a dialogue of
  places to move a line to. Every new `SortableList` listens on the body for the life of the page,
  so one, which finds its list by selector, serves every form opened on the page.
* **The JavaScript is required in `render()`, not `definition()`.** The `core_form_dynamic_form`
  web service builds the form before it starts collecting the JavaScript to send back with it.
* **"Required" is checked on the server only.** On the client, a failed check would try to focus
  the hidden textarea, which goes nowhere.
* **The text starts with a spare newline.** When the form comes back with an error, the browser
  drops the newline straight after `<textarea>`. Without the spare, a blank first line would be lost,
  and every option would come back a line higher than it was written.
* **Enter in a line moves to the next line.** Otherwise it would submit the form.
* **At the limit, Add option is swapped for a stand-in** with the tooltip, rather than disabled.
  Bootstrap gives a disabled `.btn-link` `pointer-events: none`, so it would never show a tooltip.

### The preview in the editor

`tiny_embedpoll` shows each token as the page will show its poll.

**How the preview is built**

* The same template renders it, via `output\widget::render_preview()`.
* The editor fetches every token in the text at once, from `local_embedpoll_get_previews`.
* The preview is rendered for the current user, so a teacher sees the results as they will on the
  page.
* Its buttons are rendered disabled.

**Why it is never saved**

The preview must never be saved into the text, because the filter matches a token only up to its
first `</div>`. So the preview is never put inside the token:

* It goes in a **shadow root** attached to the token. `innerHTML` and `cloneNode()` leave a shadow
  root out, and so does everything TinyMCE builds from them: the saved text, autosave, copying and
  undo.
* As a second line of defence, a serializer attribute filter empties every token, and drops its
  `contenteditable`, however the text is read.

**Keeping previews attached**

TinyMCE rebuilds tokens freely: setting content, paste, drag and drop, undo. A rebuilt token has no
shadow root. Undo can rewrite the body without firing `SetContent`, so a `MutationObserver` on the
body attaches previews as tokens arrive.

**Styling**

The editor's iframe carries only the theme's *editor* stylesheet. So each shadow root also links the
page's own theme stylesheets (`theme_config::css_urls()`, which are passed to the editor in its
configuration). Browsers ignore `@font-face` inside a shadow root, so the page's Font Awesome font
faces are also copied onto the editor's document, for the option icons.

**Interaction**

Nothing in the preview takes the pointer. A click lands on the token itself, which opens the form
for someone who may manage polls. The "Click to edit" hint, the move arrows and the insert space
buttons sit in the same shadow root, so they are never saved either. They appear only while the poll is hovered, so that
otherwise the preview looks exactly as the page will.

### Drafts

A label's text, or a page's content, is written on the *add an activity* form, before the activity
exists. A poll made there cannot belong to the activity yet. The editor's context on that form is
the course, and `api::embed_target()` turns that into a **draft**: `cmid = 0`, `sectionid = 0`.

When the activity is saved, `local_embedpoll_coursemodule_edit_post_actions()` calls
`api::adopt_drafts()`. It reads **every text field of the activity's own record** for tokens, not
just the description, and gives the activity the drafts it finds there.

A cancelled form leaves its drafts behind. A daily task (`purge_drafts`) deletes drafts that are
more than a day old.

The editor button is offered in a course context only on two pages, so a draft cannot be made
anywhere else:

* `/course/modedit.php`;
* `/course/editsection.php`, for a section's poll.

### Polls in section summaries

A section summary is edited, and formatted, in the **course** context, as the *add an activity*
form is.

* **Editing.** `tiny_embedpoll` reads the section id from the page URL and passes it to the editor,
  which sends it back with every call. `api::embed_target()` checks that the section belongs to the
  course before anything is saved.
* **Filtering.** A filter is told only the context. So `filter_embedpoll` resolves a token in a
  course context against *any* section's poll in that course (`poll::for_sections()`).

That would let two summaries share one poll. Core makes that happen: duplicating a section copies
its summary verbatim, and a teacher can paste one section's token into another.

So, on `course_section_updated`, `api::claim_section_summary()` checks the section's summary. For
any poll in it that belongs to another section, it gives this section its own copy (same options,
no votes) under a new key, and rewrites the summary to match. During a restore, claims are held off
for the course while each section step runs, and the restore sorts the keys out itself.

### Deleting

Nothing in core deletes this plugin's rows, so the plugin deletes them itself:

| When | How | What goes |
| --- | --- | --- |
| An activity is deleted | `local_embedpoll_pre_course_module_delete()` | Its polls and votes. `tool_recyclebin` runs first, so a recycled activity is backed up with its polls. |
| A course is deleted | `\core_course\hook\before_course_deleted` | All its polls, drafts included. |
| A section is deleted | The `course_section_deleted` event | That section's polls. |
| A course's contents are deleted | The `course_content_deleted` event | Every section's polls. |

### Backup and restore

`backup_local_embedpoll_plugin` adds an activity's polls to its `module.xml`, and a section's polls
to its `section.xml`. That covers:

* course backup and restore;
* import and course copy;
* *Duplicate*;
* the recycle bin.

`embedkey` is carried verbatim, so the token in the restored text matches the same key.

**Votes are user data.** They are backed up only when user data is included. So a course copied for
its next run, or a duplicated activity, starts with fresh polls, and a full backup keeps the votes.

Restoring a section into an existing section is decided by the restored summary:

* A key that is not in the summary is skipped.
* A key already used by that section's own poll is skipped, and the token resolves to that poll.
* A key used anywhere else in the course gets a new key, and the summary follows it.

### Privacy

Polls are course content and name nobody. The only personal data is each user's vote, which belongs
to the poll's context: the activity's, or the course's for a section's poll. `privacy\provider`
exports and deletes votes there.

## Known limitations

* A poll token copied and pasted **within the same activity** (two book chapters, say) is the same
  poll in both places, and they share votes. Polls created with the button always get their own key.
* Polls are not shown in text that is cleaned before filtering (see *The token*).

## Testing

```
vendor/bin/phpunit --testsuite local_embedpoll_testsuite
vendor/bin/phpunit --testsuite filter_embedpoll_testsuite
vendor/bin/phpunit --testsuite tiny_embedpoll_testsuite
vendor/bin/phpunit privacy/tests/privacy/provider_test.php
php admin/tool/behat/cli/run.php --tags=@local_embedpoll
php admin/tool/behat/cli/run.php --tags=@tiny_embedpoll
```

**Behat coverage**

* Pages (`local_embedpoll`):
  * polls in labels and book chapters;
  * voting and changing a vote;
  * a compact poll;
  * a number of votes, or nothing, beside each option;
  * results hidden from students and shown to teachers;
  * identical polls kept separate;
  * a section summary;
  * a duplicated activity.
* The editor (`tiny_embedpoll`):
  * adding a poll to a chapter and to a new label (adopted from a draft);
  * the form's lines: adding, deleting and moving them, the limit and its tooltip, and options the
    server refuses coming back on the lines they were written on;
  * the preview, and a check that it is not saved;
  * editing, with votes following the option text;
  * deleting and moving;
  * a teacher who may not manage polls.

Each line of the form is found by its placeholder: *I set the field "Option 2" to "…"*. A line's
**Delete option 2** button also matches *"Delete" "button"*, so the form's own **Delete** is found in
`.modal-footer`.

The preview and its buttons are in a shadow root, which XPath cannot see into. Use
`tiny_embedpoll`'s own steps to test them:

* *the "Content" TinyMCE editor should preview a poll with "…"*
* *… should not save "…"*
* *I click on the poll with "…" in the "Content" TinyMCE editor*
* *I move the poll "up" in the "Content" TinyMCE editor*
* *I insert space "above" the poll in the "Content" TinyMCE editor*, then *I type "…" in the
  "Content" TinyMCE editor*. Core's *I type "…"* loses its keys after the editor takes focus from
  script, as these buttons give it.

## License

2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>

This program is free software: you can redistribute it and/or modify it under the terms of the GNU
General Public License as published by the Free Software Foundation, either version 3 of the
License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without
even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
General Public License for more details.

You should have received a copy of the GNU General Public License along with this program. If not,
see <https://www.gnu.org/licenses/>.
