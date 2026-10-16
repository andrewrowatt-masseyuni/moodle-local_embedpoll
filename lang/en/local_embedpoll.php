<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * English strings for embedded polls.
 *
 * @package    local_embedpoll
 * @category   string
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['embedpoll:manage'] = 'Add and edit embedded polls';
$string['embedpoll:viewresults'] = 'Always view embedded poll results';
$string['embedpoll:vote'] = 'Vote in an embedded poll';
$string['error_invalidchoice'] = 'The selected option is not valid for this poll.';
$string['novotesyet'] = 'No votes yet';
$string['options'] = 'Options';
$string['options_inlinehelp'] = 'One option per line, in the order they should appear. At least two.';
$string['optionsduplicate'] = 'The option "{$a}" appears more than once.';
$string['optionsmin'] = 'A poll needs at least {$a} options.';
$string['optionstoolong'] = 'Each option can be at most {$a->max} characters long: "{$a->option}" is longer.';
$string['pluginname'] = 'Embedded polls';
$string['polloptions'] = 'Poll options';
$string['previewnotfound'] = 'This poll belongs to another activity, or has been removed, so it will not show here.';
$string['privacy:metadata:local_embedpoll_vote'] = 'Stores the option each user voted for in an embedded poll.';
$string['privacy:metadata:local_embedpoll_vote:optionid'] = 'The option the user voted for.';
$string['privacy:metadata:local_embedpoll_vote:pollid'] = 'The poll the vote belongs to.';
$string['privacy:metadata:local_embedpoll_vote:timecreated'] = 'The time the vote was first cast.';
$string['privacy:metadata:local_embedpoll_vote:timemodified'] = 'The time the vote was last changed.';
$string['privacy:metadata:local_embedpoll_vote:userid'] = 'The user who voted.';
$string['resultshiddenhint'] = 'Select an option to vote and see the results.';
$string['task:purgedrafts'] = 'Delete abandoned poll drafts';
$string['votecount'] = '{$a} vote';
$string['votecountplural'] = '{$a} votes';
$string['votesnotice'] = 'This poll has {$a} vote. Options you leave unchanged keep their votes, even if you reorder them. Rewording or removing an option discards the votes cast for it.';
$string['votesnoticeplural'] = 'This poll has {$a} votes. Options you leave unchanged keep their votes, even if you reorder them. Rewording or removing an option discards the votes cast for it.';
