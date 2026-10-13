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
 * Extract Google Drive auto-generated subtitles for recordings and analyse them with Gemini.
 *
 * Usage: php mod/googlemeet/cli/process_transcripts.php --googlemeetid=1 [--recordingid=4] [--dry-run]
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_googlemeet\ai_service;

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params([
    'googlemeetid' => 0,
    'recordingid'  => 0,
    'dry-run'      => false,
    'help'         => false,
    'skip-gemini'  => false,
    'language'     => '',
], [
    'g' => 'googlemeetid',
    'r' => 'recordingid',
    'd' => 'dry-run',
    'h' => 'help',
    's' => 'skip-gemini',
    'l' => 'language',
]);

if ($options['help']) {
    cli_writeln("
Extract subtitles from Google Drive recordings and process with Gemini AI.

Options:
  -g, --googlemeetid=ID   Google Meet activity ID (required)
  -r, --recordingid=ID    Process only this recording (optional)
  -d, --dry-run           Show what would be done without making changes
  -s, --skip-gemini       Only extract subtitles, skip AI analysis
  -l, --language=LANG     Subtitle language code (overrides the site setting)
  -h, --help              Show this help

Example:
  php mod/googlemeet/cli/process_transcripts.php --googlemeetid=1
  php mod/googlemeet/cli/process_transcripts.php --googlemeetid=1 --recordingid=4 --dry-run
");
    exit(0);
}

if (empty($options['googlemeetid'])) {
    cli_error('--googlemeetid is required');
}

$dryrun = $options['dry-run'];
$skipgemini = $options['skip-gemini'];

// Resolve subtitle language: explicit CLI param > site setting > 'es'.
$language = !empty($options['language'])
    ? $options['language']
    : (get_config('googlemeet', 'subtitlelanguage') ?: 'es');

// The extractor resolves yt-dlp (configured path, then PATH; never /tmp, which is world-writable).
$extractor = new \mod_googlemeet\subtitle_extractor($language);
if (!$extractor->is_available()) {
    cli_error("yt-dlp not found. Set the googlemeet/ytdlppath admin setting or install it "
        . "to a persistent path (NOT /tmp): "
        . "curl -sL https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp "
        . "-o /usr/local/bin/yt-dlp && chmod +x /usr/local/bin/yt-dlp");
}

$aiservice = new ai_service();

// Get recordings to process.
$params = ['googlemeetid' => $options['googlemeetid']];
$where = 'r.googlemeetid = :googlemeetid AND r.deleted = 0';

if (!empty($options['recordingid'])) {
    $where .= ' AND r.id = :recordingid';
    $params['recordingid'] = $options['recordingid'];
}

$sql = "SELECT r.id, r.recordingid, r.name, r.webviewlink, r.duration, r.transcripttext,
               a.id as analysisid, a.status as ai_status, a.retrycount, a.nextretry
        FROM {googlemeet_recordings} r
        LEFT JOIN {googlemeet_ai_analysis} a ON a.recordingid = r.id
        WHERE {$where}
        ORDER BY r.createdtime ASC";

$recordings = $DB->get_records_sql($sql, $params);

if (empty($recordings)) {
    cli_writeln("No recordings found for googlemeetid={$options['googlemeetid']}");
    exit(0);
}

$total = count($recordings);
$processed = 0;
$skipped = 0;
$errors = 0;

cli_writeln("Found {$total} recordings. Starting processing...\n");

foreach ($recordings as $recording) {
    $num = $processed + $skipped + $errors + 1;
    cli_writeln("=== [{$num}/{$total}] Recording #{$recording->id}: {$recording->name} ===");

    // Skip if already has completed AI analysis.
    if ($recording->ai_status === 'completed') {
        cli_writeln("  SKIP: Already has completed AI analysis.");
        $skipped++;
        continue;
    }

    // Skip if permanent failure or still within cooldown window.
    $rc = (int)($recording->retrycount ?? 0);
    $nr = (int)($recording->nextretry ?? 0);
    $now = time();

    // A retrycount of ai_service::MAX_TRANSIENT_RETRIES or more (PERMANENT_RETRYCOUNT included) ends retries.
    if ($rc >= ai_service::MAX_TRANSIENT_RETRIES) {
        cli_writeln("  SKIP: Marked as permanent failure (retrycount={$rc}). Manual intervention required.");
        $skipped++;
        continue;
    }
    if ($nr > $now) {
        $minutes = ceil(($nr - $now) / 60);
        $when = userdate($nr, '%Y-%m-%d %H:%M');
        cli_writeln("  SKIP: In cooldown until {$when} ({$minutes} min remaining, retrycount={$rc}).");
        $skipped++;
        continue;
    }

    // Steps 1 and 2: subtitle URL through yt-dlp, then the subtitle track, parsed to text.
    $driveurl = $recording->webviewlink;
    if (empty($driveurl)) {
        cli_writeln("  ERROR: No webviewlink for this recording.");
        $errors++;
        continue;
    }

    cli_writeln("  Steps 1-2: Extracting subtitles from Google Drive...");
    $transcript = $extractor->extract($driveurl);
    if ($transcript === null) {
        cli_writeln("  ERROR: No usable subtitles (no subtitle track, download failed or under 100 characters).");
        $errors++;
        continue;
    }
    $charcount = strlen($transcript);
    cli_writeln("  Downloaded {$charcount} characters of transcript.");

    if ($dryrun) {
        cli_writeln("  DRY-RUN: Would save transcript and run AI analysis.");
        cli_writeln("  Preview: " . substr($transcript, 0, 200) . "...");
        $processed++;
        continue;
    }

    // Step 3: Save transcript to googlemeet_ai_analysis table.
    cli_writeln("  Step 3: Saving transcript to database...");

    $now = time();
    if ($recording->analysisid) {
        // Update existing analysis record.
        $DB->update_record('googlemeet_ai_analysis', (object)[
            'id' => $recording->analysisid,
            'transcript' => $transcript,
            'status' => 'pending',
            'timemodified' => $now,
        ]);
        $analysisid = $recording->analysisid;
    } else {
        // Create new analysis record.
        $analysisid = $DB->insert_record('googlemeet_ai_analysis', (object)[
            'recordingid' => $recording->id,
            'transcript' => $transcript,
            'status' => 'pending',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
    }

    // Also save transcript to the recordings table for the transcript fast path of process_video_analysis.
    $DB->update_record('googlemeet_recordings', (object)[
        'id' => $recording->id,
        'transcripttext' => $transcript,
    ]);

    cli_writeln("  Transcript saved (analysis #{$analysisid}).");

    // Step 4: Run Gemini analysis.
    if ($skipgemini) {
        cli_writeln("  SKIP-GEMINI: Transcript saved, AI analysis skipped.");
        $processed++;
        continue;
    }

    cli_writeln("  Step 4: Running Gemini AI analysis...");

    try {
        $client = new \mod_googlemeet\gemini_client();
        if (!$client->is_configured()) {
            cli_writeln("  WARNING: Gemini AI not configured. Transcript saved but no analysis.");
            $processed++;
            continue;
        }

        $result = $client->analyze_transcript($transcript, $recording->name, $recording->duration);

        // Save the analysis results; new AI text waits for a teacher review before students see it.
        $DB->update_record('googlemeet_ai_analysis', (object)([
            'id' => $analysisid,
            'summary' => $result->summary,
            'keypoints' => json_encode($result->keypoints),
            'topics' => json_encode($result->topics),
            'language' => $result->language,
            'status' => 'completed',
            'aimodel' => $client->get_last_used_model() ?? $client->get_model(),
            'error' => null,
            'retrycount' => 0,
            'nextretry' => 0,
            'timemodified' => time(),
        ] + \mod_googlemeet\local\ai_review::unreviewed_fields()));

        $summarylen = strlen($result->summary);
        $keypointscount = count($result->keypoints);
        $topicscount = count($result->topics);
        cli_writeln("  AI analysis complete: summary={$summarylen} chars, {$keypointscount} key points, {$topicscount} topics.");
    } catch (\mod_googlemeet\gemini_transient_exception $e) {
        // Rate limit or overload: the cron retry policy applies (ai_service::TRANSIENT_BACKOFF).
        $aiservice->record_transient_failure((int)$analysisid, $rc, $e->getMessage());
        $retry = $DB->get_record('googlemeet_ai_analysis', ['id' => $analysisid], 'retrycount, nextretry');
        cli_writeln("  TRANSIENT ERROR (retrycount={$retry->retrycount}): " . $e->getMessage());
        $retrynote = empty($retry->nextretry)
            ? 'Max retries reached, giving up.'
            : 'Will retry at ' . userdate((int)$retry->nextretry, '%Y-%m-%d %H:%M') . '.';
        cli_writeln('  ' . $retrynote);
        // Not a fatal error: the transcript is already saved.
    } catch (\Throwable $e) {
        // Anything else is permanent: mark it, never retry. The transcript is already saved.
        $message = $e instanceof \mod_googlemeet\gemini_safety_exception ? $e->get_user_message() : $e->getMessage();
        $aiservice->record_permanent_failure((int)$analysisid, $message);
        cli_writeln("  PERMANENT ERROR (no retry): " . $message);
    }

    $processed++;
    cli_writeln("  DONE.\n");

    // Brief pause between Gemini API calls to avoid rate limiting.
    if (!$skipgemini && $num < $total) {
        sleep(2);
    }
}

cli_writeln("\n========================================");
cli_writeln("Results: {$processed} processed, {$skipped} skipped, {$errors} errors (of {$total} total)");
cli_writeln("========================================");
