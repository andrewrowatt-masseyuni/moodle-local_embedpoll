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

namespace local_embedpoll;

/**
 * Tests for the embed token.
 *
 * @package    local_embedpoll
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embedpoll\token
 */
final class token_test extends \advanced_testcase {
    /**
     * Keys come out in document order, once each.
     */
    public function test_keys_in_order_and_unique(): void {
        $text = '<p>Intro</p>' . token::html('bbbbbbbbbbbbbbbb') . '<p>Middle</p>' .
            token::html('aaaaaaaaaaaaaaaa') . token::html('bbbbbbbbbbbbbbbb');

        $this->assertSame(['bbbbbbbbbbbbbbbb', 'aaaaaaaaaaaaaaaa'], token::keys_in($text));
    }

    /**
     * The shapes TinyMCE actually saves: padded with &nbsp;, attributes reordered, single quotes.
     */
    public function test_matches_what_the_editor_saves(): void {
        $variants = [
            '<div class="embedpoll-embed" data-embedpoll="0123456789abcdef">&nbsp;</div>',
            '<div data-embedpoll="0123456789abcdef" class="embedpoll-embed"></div>',
            "<div class='embedpoll-embed' data-embedpoll='0123456789abcdef'>\n</div>",
            '<DIV class="embedpoll-embed" data-embedpoll="0123456789abcdef"></DIV>',
        ];

        foreach ($variants as $variant) {
            $this->assertSame(['0123456789abcdef'], token::keys_in('<p>x</p>' . $variant), $variant);
            $this->assertSame('<p>x</p>[X]', token::replace('<p>x</p>' . $variant, fn() => '[X]'), $variant);
        }
    }

    /**
     * A token inside another block - a C4L component, say - is matched without taking the
     * component's own closing tag.
     */
    public function test_inside_another_block(): void {
        $text = '<div class="c4l-component"><p>Vote:</p>' . token::html('0123456789abcdef') . '</div>';

        $this->assertSame(
            '<div class="c4l-component"><p>Vote:</p>[X]</div>',
            token::replace($text, fn() => '[X]')
        );
    }

    /**
     * Anything that is not a well-formed key is left alone.
     */
    public function test_ignores_non_tokens(): void {
        $this->assertSame([], token::keys_in('<div data-embedpoll="short"></div>'));
        $this->assertSame([], token::keys_in('<div data-embedpoll="ZZZZZZZZZZZZZZZZ"></div>'));
        $this->assertSame([], token::keys_in('<p>No token here</p>'));
        $this->assertSame([], token::keys_in(null));
    }

    /**
     * A new key is always the right shape, and round-trips through html().
     */
    public function test_new_key_round_trips(): void {
        $key = token::new_key();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key);
        $this->assertSame([$key], token::keys_in(token::html($key)));
    }

    /**
     * Rekeying changes only the keys asked for, and leaves each token's markup as it was.
     */
    public function test_rekey(): void {
        $text = '<p>A</p><div data-embedpoll="0123456789abcdef" class="embedpoll-embed">&nbsp;</div>' .
            "<div class='embedpoll-embed' data-embedpoll='fedcba9876543210'></div>";

        $this->assertSame(
            '<p>A</p><div data-embedpoll="aaaaaaaaaaaaaaaa" class="embedpoll-embed">&nbsp;</div>' .
                "<div class='embedpoll-embed' data-embedpoll='fedcba9876543210'></div>",
            token::rekey($text, ['0123456789abcdef' => 'aaaaaaaaaaaaaaaa'])
        );
        $this->assertSame($text, token::rekey($text, []));
    }
}
