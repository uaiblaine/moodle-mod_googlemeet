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

namespace mod_googlemeet;

use stdClass;
use moodle_exception;

/**
 * AI Service for mod_googlemeet.
 *
 * Handles the business logic for AI-powered video analysis,
 * including creating, updating, and retrieving analysis records.
 *
 * A googlemeet_ai_analysis row moves pending -> processing -> completed or failed. A transient
 * failure stays 'failed' with retrycount 1 to MAX_TRANSIENT_RETRIES - 1 and a nextretry, and is
 * claimed again once that is due; retrycount MAX_TRANSIENT_RETRIES or PERMANENT_RETRYCOUNT ends
 * the automatic retries.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ai_service {
    /** @var int Max transient retries before giving up (cli/process_transcripts.php applies it too). */
    const MAX_TRANSIENT_RETRIES = 4;

    /** @var int retrycount sentinel meaning "permanent failure, never retry". */
    const PERMANENT_RETRYCOUNT = 99;

    /**
     * @var int[] Back-off schedule in seconds, indexed by (retrycount - 1): 2h, 4h, 8h. One entry per retry
     *      that is still scheduled, so MAX_TRANSIENT_RETRIES - 1 entries: the last failure ends the retries.
     */
    const TRANSIENT_BACKOFF = [7200, 14400, 28800];

    /** @var int Fewest practice questions one generation request may ask for. */
    const MIN_QUESTIONS = 1;

    /** @var int Most practice questions one generation request may ask for. */
    const MAX_QUESTIONS = 20;

    /** @var int Default minutes after which a 'processing' analysis counts as stuck. */
    const DEFAULT_STUCK_MINUTES = 60;

    /** @var int Lower bound for googlemeet/aistuckminutes, so a healthy tier-3 (video download) run is not marked stuck. */
    const MIN_STUCK_MINUTES = 45;

    /** @var gemini_client The Gemini API client */
    private $client;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->client = new gemini_client();
    }

    /**
     * Check if AI features are available.
     *
     * @return bool
     */
    public function is_available(): bool {
        return $this->client->is_configured();
    }

    /**
     * Get the analysis for a recording.
     *
     * @param int $recordingid The recording ID
     * @return stdClass|null The analysis record or null if not found
     */
    public function get_analysis(int $recordingid): ?stdClass {
        global $DB;

        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);

        if ($analysis) {
            // Decode JSON fields.
            $analysis->keypoints = json_decode($analysis->keypoints) ?: [];
            $analysis->topics = json_decode($analysis->topics) ?: [];
            $analysis->chapters = json_decode($analysis->chapters ?? '') ?: [];
        }

        return $analysis ?: null;
    }

    /**
     * Start AI analysis for a recording (queues background task).
     *
     * @param int $recordingid The recording ID
     * @param bool $regenerate Whether to regenerate if analysis exists
     * @param bool $forcedownload Whether to allow falling back to full-video download (tier 3)
     * @param bool $alreadyclaimed True when the caller (process_pending()) has already flipped this
     *                             row to 'processing' through claim_pending(): the status checks are
     *                             skipped and the adhoc task is only (re)queued for the claimed row.
     * @return stdClass The completed analysis when one exists and $regenerate is false; otherwise the
     *                  row in 'processing' (keypoints and topics empty)
     * @throws moodle_exception If the recording is missing or deleted, or queueing fails
     */
    public function generate_analysis(
        int $recordingid,
        bool $regenerate = false,
        bool $forcedownload = false,
        bool $alreadyclaimed = false
    ): stdClass {
        global $DB;

        // Get the recording.
        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$recording) {
            throw new \moodle_exception('recordingnotfound', 'googlemeet');
        }

        // Check if analysis already exists.
        $existing = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);

        // If exists and completed and not regenerating, return it.
        if ($existing && !$regenerate && $existing->status === 'completed') {
            $existing->keypoints = json_decode($existing->keypoints) ?: [];
            $existing->topics = json_decode($existing->topics) ?: [];
            $existing->chapters = json_decode($existing->chapters ?? '') ?: [];
            return $existing;
        }

        // A row already 'processing' belongs to another cron run or request: return it without
        // queueing a second adhoc task, even when $regenerate is true. Only its claimer
        // ($alreadyclaimed) may act on it, or anyone once it is stuck (its worker died).
        if ($existing && $existing->status === 'processing' && !$alreadyclaimed && !self::is_stuck($existing)) {
            $existing->keypoints = [];
            $existing->topics = [];
            return $existing;
        }

        // Create or update the analysis record with processing status.
        $analysis = new stdClass();
        $analysis->recordingid = $recordingid;
        $analysis->status = 'processing';
        $analysis->error = null;
        $analysis->timemodified = time();
        // A fresh (re)generation request resets the transient-retry budget; an automatic claimed
        // retry keeps counting.
        if (!$alreadyclaimed) {
            $analysis->retrycount = 0;
            $analysis->nextretry = 0;
        }

        if ($existing) {
            $analysis->id = $existing->id;
            // When already claimed, the row is already 'processing'; keep it that way
            // rather than rewriting it, but still proceed to (re)enqueue below.
            if (!$alreadyclaimed) {
                $DB->update_record('googlemeet_ai_analysis', $analysis);
            } else {
                $analysis->timemodified = $existing->timemodified;
            }
        } else {
            $analysis->timecreated = time();
            $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);
        }

        // Queue the adhoc task to process in background.
        $task = new \mod_googlemeet\task\process_video_analysis();
        $task->set_custom_data([
            'recordingid' => $recordingid,
            'analysisid' => $analysis->id,
            'forcedownload' => $forcedownload,
        ]);
        \core\task\manager::queue_adhoc_task($task, true); // The true argument checks for duplicates.

        // Return the analysis with processing status.
        $analysis->keypoints = [];
        $analysis->topics = [];
        $analysis->chapters = [];
        $analysis->summary = '';
        $analysis->transcript = '';

        return $analysis;
    }

    /**
     * Bring a requested question count into the range one generation request accepts.
     *
     * The queued task, the Gemini prompt and the audit event all report this value, so they share it.
     *
     * @param int $count Number of questions requested.
     * @return int The count within MIN_QUESTIONS and MAX_QUESTIONS.
     */
    public static function clamp_question_count(int $count): int {
        return max(self::MIN_QUESTIONS, min(self::MAX_QUESTIONS, $count));
    }

    /**
     * Queue AI question generation for a recording.
     *
     * @param int $recordingid Recording id.
     * @param int $cmid Course module id.
     * @param int $count Number of questions requested.
     * @param int $userid User who requested generation.
     * @return void
     */
    public function queue_question_generation(int $recordingid, int $cmid, int $count, int $userid): void {
        $task = new \mod_googlemeet\task\generate_questions();
        $task->set_custom_data([
            'recordingid' => $recordingid,
            'cmid' => $cmid,
            'count' => self::clamp_question_count($count),
            'userid' => $userid,
        ]);
        $task->set_userid($userid);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Generate questions from an existing recording transcript and insert them as draft bank questions.
     *
     * @param int $recordingid Recording id.
     * @param int $count Number of questions requested.
     * @return int Number of questions created.
     * @throws moodle_exception
     */
    public function generate_questions_for_recording(int $recordingid, int $count = 10): int {
        global $DB;

        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$recording) {
            throw new \moodle_exception('recordingnotfound', 'googlemeet');
        }
        $googlemeet = $DB->get_record('googlemeet', ['id' => $recording->googlemeetid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('googlemeet', $googlemeet->id, $googlemeet->course, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $analysis = $this->get_analysis($recordingid);
        $transcript = '';
        if ($analysis && !empty($analysis->transcript) && $analysis->status === 'completed') {
            $transcript = $analysis->transcript;
        } else if (!empty($recording->transcripttext)) {
            $transcript = $recording->transcripttext;
        }

        if (trim($transcript) === '') {
            throw new moodle_exception('question_no_transcript_error', 'googlemeet');
        }

        $lang = ($analysis && !empty($analysis->language)) ? $analysis->language : current_language();
        $questions = $this->client->generate_questions($transcript, max(1, min(20, $count)), $lang);
        $questionservice = new question_service();
        $created = 0;

        foreach ($questions as $questiondata) {
            $questionservice->create_draft_multichoice($googlemeet, $cm, $context, $recordingid, $questiondata);
            $created++;
        }

        return $created;
    }

    /**
     * Generate timestamped chapters from an existing recording transcript.
     *
     * @param int $recordingid Recording id.
     * @return array Generated chapters.
     * @throws moodle_exception
     */
    public function generate_chapters_for_recording(int $recordingid): array {
        global $DB;

        $recording = $DB->get_record('googlemeet_recordings', ['id' => $recordingid, 'deleted' => 0], '*', IGNORE_MISSING);
        if (!$recording) {
            throw new \moodle_exception('recordingnotfound', 'googlemeet');
        }
        $analysis = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid], '*', IGNORE_MISSING);
        if (!$analysis) {
            throw new \moodle_exception('invalidrecord', 'error', '', 'googlemeet_ai_analysis');
        }
        if ($analysis->status !== 'completed') {
            throw new moodle_exception('ai_invalid_analysis', 'googlemeet', '', 'Analysis is not completed');
        }

        $transcript = '';
        if (!empty($recording->transcripttext)) {
            $transcript = $recording->transcripttext;
        } else if (!empty($analysis->transcript)) {
            $transcript = $analysis->transcript;
        }

        if (trim($transcript) === '') {
            throw new moodle_exception('chapter_no_transcript_error', 'googlemeet');
        }

        $lang = !empty($analysis->language) ? $analysis->language : current_language();
        $chapters = $this->client->generate_chapters($transcript, $lang, $recording->name, $recording->duration);

        // Chapter-only (re)generation keeps the review state of the analysis. Resetting it here
        // would hide every published summary again when cli/backfill_chapters.php runs over a course.
        $DB->update_record('googlemeet_ai_analysis', (object) [
            'id' => $analysis->id,
            'chapters' => json_encode($chapters),
            'aimodel' => $this->client->get_last_used_model() ?? $this->client->get_model(),
            'timemodified' => time(),
        ]);

        return $chapters;
    }

    /**
     * Atomically claim a due analysis for processing.
     *
     * Flips one row that is 'pending', or 'failed' with a due transient retry (the conditions of
     * get_due_analyses()), to 'processing' with an UPDATE guarded on the status just read, so only
     * one cron run can own it: when another run claimed it first, the UPDATE matches no row.
     *
     * @param int $analysisid The analysis record ID to claim
     * @return bool True if this call won the claim, false if the row was not claimable or already taken
     */
    public function claim_pending(int $analysisid): bool {
        global $DB;

        $now = time();

        // Check claimability first: a second claim of a row already 'processing' must return false
        // even within the same second, which the timestamp ownership check below cannot tell apart.
        $row = $DB->get_record(
            'googlemeet_ai_analysis',
            ['id' => $analysisid],
            'id, status, retrycount, nextretry'
        );
        if (!$row) {
            return false;
        }
        $claimable = $row->status === 'pending'
            || ($row->status === 'failed'
                && (int) $row->retrycount > 0
                && (int) $row->retrycount < self::MAX_TRANSIENT_RETRIES
                && (int) $row->nextretry > 0
                && (int) $row->nextretry <= $now);
        if (!$claimable) {
            return false;
        }

        // Conditional update guarded on the exact status we just observed: a concurrent claimer
        // that already flipped the row matches zero rows here. $DB->execute() does not report
        // affected rows portably, so we re-read and confirm ownership below.
        $sql = "UPDATE {googlemeet_ai_analysis}
                   SET status = 'processing', timemodified = :now
                 WHERE id = :id AND status = :expected";
        $DB->execute($sql, ['now' => $now, 'id' => $analysisid, 'expected' => $row->status]);

        // Confirm we own it. The sole caller (process_pending) runs under the
        // 'mod_googlemeet_ai_analysis' cron lock, so within the locked path the status-guarded
        // UPDATE above makes this exact; the claimable pre-check handles the unlocked re-claim case.
        $check = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], 'id, status, timemodified');
        return $check && $check->status === 'processing' && (int) $check->timemodified === $now;
    }

    /**
     * Record a transient (retryable) failure with bounded back-off.
     *
     * Used by the background AI pipeline so that rate limits / overloads are retried instead of
     * becoming permanent failures; cli/process_transcripts.php records its failures here too. After
     * {@see self::MAX_TRANSIENT_RETRIES} attempts the row is left 'failed' with no further
     * nextretry (so the due-selection no longer picks it up).
     *
     * @param int $analysisid The analysis row id.
     * @param int $previousretrycount The retrycount BEFORE this attempt.
     * @param string $message The error message to store.
     * @return void
     */
    public function record_transient_failure(int $analysisid, int $previousretrycount, string $message): void {
        global $DB;

        $now = time();
        $newrc = $previousretrycount + 1;

        if ($newrc >= self::MAX_TRANSIENT_RETRIES) {
            // Retries exhausted. Cap retrycount and clear nextretry so it is not retried again.
            $newrc = self::MAX_TRANSIENT_RETRIES;
            $nextretry = 0;
        } else {
            $backoff = self::TRANSIENT_BACKOFF[$newrc - 1];
            $nextretry = $now + $backoff;
        }

        $DB->update_record('googlemeet_ai_analysis', (object) [
            'id' => $analysisid,
            'status' => 'failed',
            'error' => $message,
            'retrycount' => $newrc,
            'nextretry' => $nextretry,
            'timemodified' => $now,
        ]);
    }

    /**
     * Record a permanent (non-retryable) failure.
     *
     * @param int $analysisid The analysis row id.
     * @param string $message The error message to store.
     * @return void
     */
    public function record_permanent_failure(int $analysisid, string $message): void {
        global $DB;

        $DB->update_record('googlemeet_ai_analysis', (object) [
            'id' => $analysisid,
            'status' => 'failed',
            'error' => $message,
            'retrycount' => self::PERMANENT_RETRYCOUNT,
            'nextretry' => 0,
            'timemodified' => time(),
        ]);
    }

    /**
     * Get analyses that are due for processing: fresh 'pending' rows plus transient-failure
     * rows whose back-off has elapsed.
     *
     * @param int $limit Maximum number to return.
     * @return array Array of analysis records.
     */
    public function get_due_analyses(int $limit = 10): array {
        global $DB;

        $now = time();
        $sql = "SELECT aa.*
                  FROM {googlemeet_ai_analysis} aa
                  JOIN {googlemeet_recordings} r ON r.id = aa.recordingid
                 WHERE r.deleted = 0
                   AND (aa.status = :pending
                    OR (aa.status = :failed
                        AND aa.retrycount > 0
                        AND aa.retrycount < :maxretries
                        AND aa.nextretry > 0
                        AND aa.nextretry <= :now))
              ORDER BY aa.timecreated ASC";
        $params = [
            'pending' => 'pending',
            'failed' => 'failed',
            'maxretries' => self::MAX_TRANSIENT_RETRIES,
            'now' => $now,
        ];

        return $DB->get_records_sql($sql, $params, 0, $limit);
    }

    /**
     * Time after which a 'processing' analysis is considered stuck.
     *
     * Read from googlemeet/aistuckminutes (DEFAULT_STUCK_MINUTES when unset), never below MIN_STUCK_MINUTES.
     *
     * @return int Seconds.
     */
    public static function get_stuck_threshold(): int {
        $minutes = (int)get_config('googlemeet', 'aistuckminutes');
        if ($minutes <= 0) {
            $minutes = self::DEFAULT_STUCK_MINUTES;
        }
        return max(self::MIN_STUCK_MINUTES, $minutes) * MINSECS;
    }

    /**
     * Whether an analysis has been in 'processing' for longer than the stuck threshold.
     *
     * @param stdClass|null|false $analysis Analysis row (status, timemodified).
     * @param int|null $now Reference time.
     * @return bool
     */
    public static function is_stuck($analysis, ?int $now = null): bool {
        if (!$analysis || ($analysis->status ?? '') !== 'processing') {
            return false;
        }
        $now = $now ?? time();
        return (int)($analysis->timemodified ?? 0) < $now - self::get_stuck_threshold();
    }

    /**
     * Scheduled cleanup: mark analyses stuck in 'processing' as failed.
     *
     * Uses the transient-failure bookkeeping, so a stuck row is retried automatically with
     * back-off while the retry budget lasts, and then stays failed (no endless loop when the
     * worker keeps dying on the same recording). The teacher sees the failure and can retry.
     *
     * @param int|null $maxage Seconds in 'processing' before a row is stale (default: the stuck threshold).
     * @return int Number of rows marked failed.
     */
    public function fail_stale_processing(?int $maxage = null): int {
        global $DB;

        $maxage = $maxage ?? self::get_stuck_threshold();
        $stale = $DB->get_records_select(
            'googlemeet_ai_analysis',
            "status = 'processing' AND timemodified < :threshold",
            ['threshold' => time() - $maxage],
            '',
            'id, retrycount'
        );
        $minutes = (int)round($maxage / MINSECS);
        foreach ($stale as $row) {
            $retrycount = (int)$row->retrycount;
            $message = get_string('ai_error_stuck', 'googlemeet', $minutes);
            if ($retrycount >= self::MAX_TRANSIENT_RETRIES) {
                $this->record_permanent_failure((int)$row->id, $message);
            } else {
                $this->record_transient_failure((int)$row->id, $retrycount, $message);
            }
        }
        return count($stale);
    }

    /**
     * Queue a recording for analysis.
     *
     * @param int $recordingid The recording ID
     * @return stdClass The existing analysis record, or the new one in 'pending'
     * @throws moodle_exception If the recording is missing or deleted
     */
    public function queue_for_analysis(int $recordingid): stdClass {
        global $DB;

        if (!$DB->record_exists('googlemeet_recordings', ['id' => $recordingid, 'deleted' => 0])) {
            throw new \moodle_exception('recordingnotfound', 'googlemeet');
        }

        // Check if already exists.
        $existing = $DB->get_record('googlemeet_ai_analysis', ['recordingid' => $recordingid]);
        if ($existing) {
            return $existing;
        }

        $analysis = new stdClass();
        $analysis->recordingid = $recordingid;
        $analysis->status = 'pending';
        $analysis->timecreated = time();
        $analysis->timemodified = time();

        $analysis->id = $DB->insert_record('googlemeet_ai_analysis', $analysis);

        return $analysis;
    }

    /**
     * Queue the adhoc analysis task for a batch of due analyses (see get_due_analyses()).
     *
     * @param int $limit Maximum number to queue
     * @return int Number of analyses queued
     */
    public function process_pending(int $limit = 5): int {
        global $DB;

        $pending = $this->get_due_analyses($limit);
        $processed = 0;

        foreach ($pending as $analysis) {
            // Claim the row first: when another run already took it, claim_pending() returns
            // false and the recording is not queued twice.
            if (!$this->claim_pending($analysis->id)) {
                continue;
            }

            try {
                // Background runner must never escalate to tier 3 (video download) automatically.
                // $alreadyclaimed=true: the row is already ours in 'processing', so just enqueue.
                $this->generate_analysis($analysis->recordingid, true, false, true);
                $processed++;
            } catch (\Exception $e) {
                // Queueing failed, which is not a transient API condition: record a permanent failure
                // so the claimed row does not stay 'processing'. Flipping only the status would leave
                // a due transient retry with its retrycount and nextretry, reselected on every run.
                $this->record_permanent_failure($analysis->id, $e->getMessage());
                continue;
            }
        }

        return $processed;
    }
}
