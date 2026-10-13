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

/**
 * Extracts auto-generated subtitles from Google Drive video recordings.
 *
 * Uses yt-dlp to discover the subtitle URL (timedtext endpoint with authpayload),
 * then downloads the subtitle XML with the required Referer header and parses it
 * into a timestamped transcript.
 *
 * This avoids downloading the full video file, which can be several GB.
 *
 * @package     mod_googlemeet
 * @copyright   2026 PreparaOposiciones
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class subtitle_extractor {
    /** @var int Seconds a yt-dlp run may take before it is killed (a subtitle-only run takes a few seconds). */
    protected const YTDLP_TIMEOUT = 120;

    /** @var string|null Path to the yt-dlp binary */
    private ?string $ytdlppath = null;

    /** @var string Subtitle language to extract */
    private string $language;

    /** @var string|null Cached PATH lookup result for this request. */
    private static ?string $cachedwhich = null;

    /** @var bool Whether PATH lookup has already run in this request. */
    private static bool $whichlookedup = false;

    /**
     * Constructor.
     *
     * @param string $language The subtitle language code (default: 'es')
     */
    public function __construct(string $language = 'es') {
        // A language tag only (es, pt-BR, en_US): the value is passed to yt-dlp as an argument, and
        // anything else (a leading '-' would read as an option) falls back to the default.
        $this->language = preg_match('/^[A-Za-z]{2,3}(?:[_-][A-Za-z0-9]{2,8})*$/', $language) ? $language : 'es';
        $this->ytdlppath = $this->find_ytdlp();
    }

    /**
     * Check if subtitle extraction is available.
     *
     * @return bool True if yt-dlp is installed and executable
     */
    public function is_available(): bool {
        return !empty($this->ytdlppath);
    }

    /**
     * Extract subtitles from a Google Drive video URL.
     *
     * @param string $driveurl The Google Drive video URL (webviewlink)
     * @return string|null The parsed transcript with timestamps, or null on failure
     */
    public function extract(string $driveurl): ?string {
        if (!$this->is_available()) {
            mtrace('subtitle_extractor: yt-dlp not found; skipping subtitle extraction. '
                . 'Set its path in the googlemeet setting "ytdlppath" or install yt-dlp in PATH.');
            debugging('subtitle_extractor: yt-dlp not available (googlemeet/ytdlppath not executable and not found in PATH); ' .
                'skipping the auto-subtitle tier', DEBUG_DEVELOPER);
            return null;
        }

        if (empty($driveurl)) {
            return null;
        }

        // Step 1: Get the timedtext URL from yt-dlp.
        $timedtexturl = $this->get_timedtext_url($driveurl);
        if (!$timedtexturl) {
            debugging('subtitle_extractor: Could not extract timedtext URL', DEBUG_DEVELOPER);
            return null;
        }

        // Step 2: Download the subtitle XML.
        $baseurl = preg_replace('/&type=track.*$/', '', $timedtexturl);

        // Try ASR (auto-generated) track first.
        $lang = rawurlencode($this->language);
        $suburl = $baseurl . '&type=track&lang=' . $lang . '&kind=asr&fmt=1';
        $xml = $this->download_subtitle($suburl);

        if (empty($xml)) {
            // Fallback: try named track "1".
            $suburl = $baseurl . '&type=track&lang=' . $lang . '&name=1&fmt=1';
            $xml = $this->download_subtitle($suburl);
        }

        if (empty($xml)) {
            debugging('subtitle_extractor: Could not download subtitle content', DEBUG_DEVELOPER);
            return null;
        }

        // Step 3: Parse XML into transcript text.
        $transcript = $this->parse_xml($xml);

        if (strlen($transcript) < 100) {
            debugging('subtitle_extractor: Transcript too short (' . strlen($transcript) . ' chars)', DEBUG_DEVELOPER);
            return null;
        }

        return $transcript;
    }

    /**
     * Find the yt-dlp binary.
     *
     * @return string|null Path to yt-dlp or null if not found
     */
    private function find_ytdlp(): ?string {
        $configured = trim((string)get_config('googlemeet', 'ytdlppath'));
        if ($configured !== '') {
            if (is_executable($configured)) {
                return $configured;
            }
            debugging('subtitle_extractor: configured yt-dlp path is not executable: ' . $configured, DEBUG_DEVELOPER);
        }

        $which = self::which_ytdlp();
        if (!empty($which) && is_executable($which)) {
            return $which;
        }

        // No world-writable fallback location (e.g. /tmp) on purpose: a binary planted there
        // would be executed by cron. Without yt-dlp the subtitle tier is skipped (see extract()).
        return null;
    }

    /**
     * Locate yt-dlp in PATH once per request.
     *
     * @return string|null Executable path, or null when not found.
     */
    private static function which_ytdlp(): ?string {
        if (!self::$whichlookedup) {
            self::$whichlookedup = true;
            $which = trim(shell_exec('which yt-dlp 2>/dev/null') ?: '');
            self::$cachedwhich = ($which !== '' && is_executable($which)) ? $which : null;
        }

        return self::$cachedwhich;
    }

    /**
     * Use yt-dlp to extract the timedtext URL from a Google Drive video page.
     *
     * The yt-dlp verbose log of a subtitle-only run contains the timedtext URL, with the
     * authpayload parameter needed for authentication.
     *
     * yt-dlp only ever sees an https drive.google.com URL ({@see self::is_drive_url()}), after "--" so
     * it is never read as an option, and it is killed after {@see self::YTDLP_TIMEOUT} seconds.
     *
     * @param string $driveurl The Google Drive video URL
     * @return string|null The timedtext URL or null on failure
     */
    public function get_timedtext_url(string $driveurl): ?string {
        if (empty($this->ytdlppath)) {
            mtrace('subtitle_extractor: yt-dlp not found; skipping subtitle extraction. '
                . 'Set its path in the googlemeet setting "ytdlppath" or install yt-dlp in PATH.');
            return null;
        }

        if (!self::is_drive_url($driveurl)) {
            debugging('subtitle_extractor: not an https drive.google.com URL; yt-dlp not run', DEBUG_DEVELOPER);
            return null;
        }

        $output = $this->run_with_timeout([
            $this->ytdlppath,
            '--ignore-config',
            '--no-playlist',
            '-v',
            '--write-sub',
            '--sub-lang',
            $this->language,
            '--skip-download',
            '--sub-format',
            'srv3',
            '-o',
            '/dev/null',
            '--',
            $driveurl,
        ], static::YTDLP_TIMEOUT);
        if ($output === null) {
            return null;
        }

        if (preg_match('/Invoking http downloader on "(https:\/\/drive\.google\.com\/timedtext\?[^"]+)"/', $output, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Whether a URL is an https link on drive.google.com, the only kind yt-dlp is started with.
     *
     * @param string $url Candidate URL.
     * @return bool
     */
    public static function is_drive_url(string $url): bool {
        if ($url === '' || preg_match('~[\s\\\\]~', $url)) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && strtolower($parts['host'] ?? '') === 'drive.google.com'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['port']);
    }

    /**
     * Run a command without a shell and return its combined output, killing it at a deadline.
     *
     * Core has no helper with a time limit, and shell_exec() waits for ever on a hung process. The
     * command is an argument list (proc_open() then runs it without a shell), stderr is merged into
     * stdout, and the output is read without blocking until the process ends or the deadline passes.
     *
     * @param string[] $command Program and arguments.
     * @param int $timeout Seconds before the process is killed.
     * @return string|null The output, or null when the process could not start or was killed.
     */
    private function run_with_timeout(array $command, int $timeout): ?string {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
        ];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            debugging('subtitle_extractor: yt-dlp could not be started', DEBUG_DEVELOPER);
            return null;
        }
        stream_set_blocking($pipes[1], false);

        $output = '';
        $timedout = false;
        $deadline = microtime(true) + $timeout;
        while (!feof($pipes[1])) {
            $left = $deadline - microtime(true);
            if ($left <= 0) {
                $timedout = true;
                break;
            }
            $read = [$pipes[1]];
            $write = null;
            $except = null;
            $seconds = (int)floor($left);
            $ready = stream_select($read, $write, $except, $seconds, (int)(($left - $seconds) * 1000000));
            if ($ready === false) {
                break;
            }
            if ($ready > 0) {
                $output .= (string)fread($pipes[1], 8192);
            }
        }
        fclose($pipes[1]);

        // A child that closed its output may still be running: wait for it within the same deadline,
        // so proc_close() below never blocks the task.
        while (!$timedout && proc_get_status($process)['running']) {
            if (microtime(true) >= $deadline) {
                $timedout = true;
                break;
            }
            usleep(100000);
        }
        if ($timedout) {
            // SIGKILL (9): the pcntl SIG* constants are not always compiled in. Only the direct child is
            // killed; ytdlppath is an admin-set binary, not a wrapper expected to fork.
            proc_terminate($process, 9);
        }
        proc_close($process);

        if ($timedout) {
            debugging("subtitle_extractor: yt-dlp killed after {$timeout} seconds", DEBUG_DEVELOPER);
            return null;
        }
        return $output;
    }

    /**
     * Download subtitle XML from the timedtext URL.
     *
     * The Referer header is required: without it, Google returns empty responses.
     *
     * @param string $url The timedtext URL with track parameters
     * @return string The subtitle XML content, or empty string on failure
     */
    public function download_subtitle(string $url): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl();
        $curl->setHeader(['Referer: https://youtube.googleapis.com/']);

        $options = [
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_CONNECTTIMEOUT' => 10,
            'CURLOPT_FOLLOWLOCATION' => true,
        ];

        return $curl->get($url, [], $options) ?: '';
    }

    /**
     * Parse subtitle XML (fmt=1 format) into readable transcript with timestamps.
     *
     * Input format: <transcript><text start="148.92" dur="3">Hola</text>...</transcript>
     * Output format: "2:28\nHola\nbuen texto\n3:01\nmás texto..."
     *
     * A timestamp line (the cue's own start time) precedes the first cue of each new minute.
     *
     * @param string $xml The subtitle XML content
     * @return string The parsed transcript with timestamps
     */
    public function parse_xml(string $xml): string {
        libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_clear_errors();

        if (!$doc) {
            return '';
        }

        $lines = [];
        $lastminute = -1;

        foreach ($doc->text as $node) {
            $start = (float)$node['start'];
            $text = trim((string)$node);

            if (empty($text)) {
                continue;
            }

            // Convert seconds to M:SS or H:MM:SS, on the whole second: % on a float with a fraction is
            // deprecated since PHP 8.1.
            $whole = (int) floor($start);
            $hours = intdiv($whole, 3600);
            $minutes = intdiv($whole % 3600, 60);
            $seconds = $whole % 60;
            $currentminute = intdiv($whole, 60);

            // Add timestamp marker at each new minute.
            if ($currentminute > $lastminute) {
                if ($hours > 0) {
                    $timestamp = sprintf("%d:%02d:%02d", $hours, $minutes, $seconds);
                } else {
                    $timestamp = sprintf("%d:%02d", $minutes, $seconds);
                }
                $lines[] = $timestamp;
                $lastminute = $currentminute;
            }

            // Clean up HTML entities.
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines[] = $text;
        }

        return implode("\n", $lines);
    }
}
