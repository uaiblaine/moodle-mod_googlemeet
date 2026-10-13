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

namespace mod_googlemeet\local;

use core_text;
use stdClass;

/**
 * Recording search and topic helpers: query and topic filters, the search text loader, the match
 * snippet, and the topic list and card preview.
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class recording_search {
    /**
     * URL parameters that keep the list's search and topic filter when a link leaves the list.
     *
     * Used by the links into a recording hub and by the hub's way back, so the teacher returns to the
     * same filtered list. Empty filters add nothing.
     *
     * @param string $query Free-text search (the `rq` parameter).
     * @param string $topic Topic filter (the `topic` parameter).
     * @return array Map of parameter name => value.
     */
    public static function list_filter_params(string $query, string $topic): array {
        $params = [];
        if (trim($query) !== '') {
            $params['rq'] = $query;
        }
        if (trim($topic) !== '') {
            $params['topic'] = $topic;
        }
        return $params;
    }

    /**
     * Filter recordings by a free-text query against the title, AI topics/summary and (teachers) notes/transcript.
     *
     * Non-title matches set matchsource/matchsnippet so the list can say where the term was found.
     *
     * @param array $recordings Recording stdClass list (post AI-enrichment).
     * @param string $query Raw search query.
     * @param bool $includeprivatecontent Whether to search notes/transcript fields.
     * @return array Filtered recordings, re-indexed.
     * @throws \coding_exception
     */
    public static function filter_recordings_by_query(array $recordings, string $query, bool $includeprivatecontent = true): array {
        $needle = recording_format::fold(trim($query));
        if ($needle === '') {
            return $recordings;
        }

        $filtered = [];
        foreach ($recordings as $r) {
            $r->matchsource = '';
            $r->matchsnippet = '';

            // Title: the stored name and the generated display title. A title match needs no hint.
            foreach ([(string)($r->name ?? ''), (string)($r->displayname ?? '')] as $h) {
                if ($h !== '' && core_text::strpos(recording_format::fold($h), $needle) !== false) {
                    $filtered[] = $r;
                    continue 2;
                }
            }

            // AI summary and topics: say where it matched, the list only shows a cropped preview.
            if (!empty($r->hasai)) {
                foreach (($r->aitopics ?? []) as $t) {
                    if (core_text::strpos(recording_format::fold((string)$t), $needle) !== false) {
                        $r->matchsource = get_string('search_match_topics', 'googlemeet');
                        $r->matchsnippet = (string)$t;
                        $filtered[] = $r;
                        continue 2;
                    }
                }
                $summary = (string)($r->aisummary ?? '');
                if (core_text::strpos(recording_format::fold($summary), $needle) !== false) {
                    $r->matchsource = get_string('search_match_summary', 'googlemeet');
                    $r->matchsnippet = self::search_match_snippet($summary, $needle);
                    $filtered[] = $r;
                    continue;
                }
            }

            if ($includeprivatecontent) {
                // The clean_text() call stored entities like &amp; in notestext; decode them so the
                // snippet reads naturally and searches for "&"-style terms can match.
                $notes = html_entity_decode(strip_tags((string)($r->notestext ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (core_text::strpos(recording_format::fold($notes), $needle) !== false) {
                    $r->matchsource = get_string('search_match_notes', 'googlemeet');
                    $r->matchsnippet = self::search_match_snippet($notes, $needle);
                    $filtered[] = $r;
                    continue;
                }

                $transcript = (string)($r->transcripttext ?? '');
                if (core_text::strpos(recording_format::fold($transcript), $needle) !== false) {
                    $r->matchsource = get_string('search_match_transcript', 'googlemeet');
                    $r->matchsnippet = self::search_match_snippet($transcript, $needle);
                    $filtered[] = $r;
                }
            }
        }

        return array_values($filtered);
    }

    /**
     * Filter recordings to those tagged with an exact AI topic.
     *
     * @param array $recordings Recording stdClass list.
     * @param string $topic Exact topic text.
     * @return array Filtered recordings, re-indexed.
     */
    public static function filter_recordings_by_topic(array $recordings, string $topic): array {
        $foldedtopic = recording_format::fold(trim($topic));
        if ($foldedtopic === '') {
            return $recordings;
        }
        return array_values(array_filter($recordings, static function ($r) use ($foldedtopic) {
            foreach (($r->aitopics ?? []) as $t) {
                if (recording_format::fold(trim((string)$t)) === $foldedtopic) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Load searchable large text fields for an already-listed recording set.
     *
     * The base recordings list deliberately omits these fields. Search views call this only
     * after applying the normal googlemeetid/visible/deleted constraints and before PHP filtering.
     *
     * @param array $recordings Recording stdClass list.
     * @param bool $includeprivatecontent Whether to attach notes/transcript text.
     * @return array The same recording list with notestext/transcripttext attached when available.
     * @throws \dml_exception
     */
    public static function load_recording_search_content(array $recordings, bool $includeprivatecontent = true): array {
        global $DB;

        if (!$includeprivatecontent) {
            return $recordings;
        }

        $ids = [];
        foreach ($recordings as $recording) {
            if (!empty($recording->id)) {
                $ids[] = (int)$recording->id;
            }
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) {
            return $recordings;
        }

        $searchrecords = $DB->get_records_list(
            'googlemeet_recordings',
            'id',
            $ids,
            '',
            'id, notestext, transcripttext'
        );

        foreach ($recordings as $recording) {
            $id = (int)($recording->id ?? 0);
            if ($id > 0 && isset($searchrecords[$id])) {
                $recording->notestext = $searchrecords[$id]->notestext ?? '';
                $recording->transcripttext = $searchrecords[$id]->transcripttext ?? '';
            }
        }

        return $recordings;
    }

    /**
     * Build a short plain-text snippet around the first folded search match.
     *
     * @param string $text Original, non-folded plain text.
     * @param string $foldedneedle Already-folded search needle.
     * @param int $length Target snippet length in characters.
     * @return string Snippet with ellipses when cropped.
     */
    public static function search_match_snippet(string $text, string $foldedneedle, int $length = 120): string {
        if ($text === '' || $foldedneedle === '') {
            return '';
        }

        $foldedtext = recording_format::fold($text);
        $pos = core_text::strpos($foldedtext, $foldedneedle);
        if ($pos === false) {
            return '';
        }

        $textlength = core_text::strlen($text);
        if ($textlength <= $length) {
            return trim((string)preg_replace('/\s+/u', ' ', $text));
        }

        $needlelength = core_text::strlen($foldedneedle);
        $matchlength = min($needlelength, $length);
        $before = intdiv($length - $matchlength, 2);
        $start = max(0, $pos - $before);
        $end = min($textlength, $start + $length);
        $matchend = min($textlength, $pos + $needlelength);
        if ($end < $matchend) {
            $end = $matchend;
            $start = max(0, $end - $length);
        }

        $snippet = core_text::substr($text, $start, $end - $start);
        $snippet = trim((string)preg_replace('/\s+/u', ' ', $snippet));
        if ($start > 0) {
            $snippet = '…' . $snippet;
        }
        if ($end < $textlength) {
            $snippet .= '…';
        }

        return $snippet;
    }

    /**
     * Distinct, naturally-sorted list of AI topics across recordings.
     *
     * @param array $recordings Recording stdClass list.
     * @return string[]
     */
    public static function collect_topics(array $recordings): array {
        $seen = [];
        foreach ($recordings as $r) {
            foreach (($r->aitopics ?? []) as $t) {
                $t = (string)$t;
                if ($t !== '') {
                    $seen[$t] = true;
                }
            }
        }
        $topics = array_keys($seen);
        sort($topics, SORT_NATURAL | SORT_FLAG_CASE);
        return $topics;
    }

    /**
     * Split a topic list into a small visible set plus an overflow count for card previews.
     *
     * @param array $topics Full topic list.
     * @param int $max Maximum visible topics.
     * @return array ['visible' => string[], 'overflow' => int]
     */
    public static function topic_preview(array $topics, int $max = 3): array {
        $visible = array_slice(array_values($topics), 0, $max);
        return ['visible' => $visible, 'overflow' => max(0, count($topics) - count($visible))];
    }
}
