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

use stdClass;

/**
 * The Moodle user behind an activity's organiser Google account.
 *
 * googlemeet.creatoruserid is the recorded fact: the user whose Google link created the room, or
 * the single active owner of the organiser e-mail when it was typed or migrated. Background tasks
 * impersonate that user to use their Google refresh token, and the attendance pages treat them as
 * the organiser. creatoremail stays the Google account the token must belong to
 * ({@see sync_manager::require_creator_account()}), but it never picks the user: profile e-mails
 * are editable and may be shared (allowaccountssameemail).
 *
 * @package     mod_googlemeet
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class creator {
    /**
     * The active Moodle user who acts for the activity, or null when there is none.
     *
     * A recorded creatoruserid is final: when that user is deleted or suspended the result is null,
     * never another user found by e-mail. Only while no id is recorded (0) is the organiser e-mail
     * matched, under {@see self::find_by_email()}'s single-match rule. That answer is never stored:
     * resolve() runs on read paths (attendance pages) and an e-mail can change hands, so only the
     * editor saving the activity (googlemeet_add_instance(), googlemeet_update_instance()) and a
     * restore by an allowed user record an id.
     *
     * @param stdClass $googlemeet Activity record or task data (id, creatoremail, creatoruserid).
     * @return stdClass|null Full user record.
     */
    public static function resolve(stdClass $googlemeet): ?stdClass {
        global $DB;

        $userid = (int)($googlemeet->creatoruserid ?? 0);
        if ($userid > 0) {
            return $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]) ?: null;
        }

        return self::find_by_email((string)($googlemeet->creatoremail ?? ''));
    }

    /**
     * Whether the current user may name $email as the organiser of a new activity.
     *
     * A new activity names the user whose Google token background tasks will use, so an editor may
     * only name their own account: the Google account they have linked right now or their Moodle
     * e-mail. Whoever holds moodle/site:config (the override of the update rule in
     * mod_googlemeet_mod_form::can_change_creatoremail()) may name anyone. An empty e-mail names
     * nobody and is always allowed.
     *
     * @param string $email Organiser e-mail being saved.
     * @return bool
     */
    public static function may_name_email(string $email): bool {
        if (trim($email) === '' || self::may_name_any_email()) {
            return true;
        }
        return self::is_editors_own_email($email, self::linked_email());
    }

    /**
     * Whether $userid, who is not the current session user, may name $email as an organiser.
     *
     * The rule of {@see self::may_name_email()} for a restore running as $userid: moodle/site:config
     * or their own Moodle e-mail. The linked Google account lives in a web session and is not known here.
     *
     * @param int $userid User acting (the restoring user).
     * @param string $email Organiser e-mail.
     * @return bool
     */
    public static function may_user_name_email(int $userid, string $email): bool {
        global $DB;

        if (trim($email) === '') {
            return true;
        }
        if (has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            return true;
        }
        $own = (string)$DB->get_field('user', 'email', ['id' => $userid, 'deleted' => 0]);
        return $own !== '' && self::same_email($email, $own);
    }

    /**
     * Whether the current user may change an existing activity's organiser e-mail to $email.
     *
     * Server-side twin of mod_googlemeet_mod_form::can_change_creatoremail(): a new e-mail names a
     * new creator, so the rule of a new activity applies ({@see self::may_name_email()}): the editor's
     * linked Google account or own Moodle e-mail, or any e-mail with moodle/site:config.
     *
     * @param string $email New organiser e-mail.
     * @return bool
     */
    public static function may_change_to_email(string $email): bool {
        if (trim($email) === '') {
            return false;
        }
        return self::may_name_email($email);
    }

    /**
     * Whether the current user may name any organiser e-mail (site administrators).
     *
     * @return bool
     */
    public static function may_name_any_email(): bool {
        return has_capability('moodle/site:config', \context_system::instance());
    }

    /**
     * Whether $email is the current user's linked Google account or their own Moodle e-mail.
     *
     * @param string $email Organiser e-mail.
     * @param string $linkedemail Google account the current user has linked, '' when none.
     * @return bool
     */
    public static function is_editors_own_email(string $email, string $linkedemail): bool {
        global $USER;

        if (trim($email) === '') {
            return false;
        }
        return ($linkedemail !== '' && self::same_email($email, $linkedemail))
            || self::same_email($email, (string)($USER->email ?? ''));
    }

    /**
     * The only active user with this e-mail, ignoring case and surrounding spaces.
     *
     * Deleted and suspended users do not count. Two or more matches give null: the e-mail does not
     * say which of them owns the Google account.
     *
     * @param string $email Organiser e-mail.
     * @return stdClass|null Full user record.
     */
    public static function find_by_email(string $email): ?stdClass {
        global $DB;

        $email = trim($email);
        if ($email === '') {
            return null;
        }
        $users = $DB->get_records_select(
            'user',
            $DB->sql_equal('email', ':email', false) . ' AND deleted = 0 AND suspended = 0',
            ['email' => $email],
            'id',
            '*',
            0,
            2
        );
        return count($users) === 1 ? reset($users) : null;
    }

    /**
     * Creator id to store for an organiser e-mail chosen by $editorid.
     *
     * The editor is the creator when the e-mail is the Google account they have linked right now
     * (the Calendar event is created with their token) or their own Moodle e-mail; otherwise the
     * single active owner of the e-mail, or 0 to resolve later. Callers have already checked that
     * $editorid may name the e-mail.
     *
     * @param string $email Organiser e-mail being saved.
     * @param string $linkedemail Google account the editor has linked, '' when none.
     * @param int $editorid User saving the activity.
     * @return int User id, 0 when nobody can be named yet.
     */
    public static function userid_for_email(string $email, string $linkedemail, int $editorid): int {
        global $DB;

        if ($editorid > 0 && $linkedemail !== '' && self::same_email($email, $linkedemail)) {
            return $editorid;
        }
        if ($editorid > 0) {
            $own = (string)$DB->get_field('user', 'email', ['id' => $editorid, 'deleted' => 0]);
            if ($own !== '' && self::same_email($email, $own)) {
                return $editorid;
            }
        }
        $user = self::find_by_email($email);
        return $user ? (int)$user->id : 0;
    }

    /**
     * The Google account the current user has linked, '' when none or when Google cannot say.
     *
     * Served from the session userinfo cache, normally without an HTTP request. A failure only
     * means the editor is not shown to own the e-mail; userid_for_email() then falls back to the
     * e-mail owner, so it is not reported.
     *
     * @return string
     */
    public static function linked_email(): string {
        try {
            $client = new \mod_googlemeet\client();
            if ($client->enabled && $client->check_login()) {
                return (string)$client->get_email();
            }
        } catch (\Throwable $e) {
            return '';
        }
        return '';
    }

    /**
     * Whether $user is the activity's creator.
     *
     * Compares user ids. While no id is recorded, the organiser e-mail decides through resolve(),
     * so a user who merely shares that e-mail with someone else is not the creator.
     *
     * @param stdClass $googlemeet Activity record.
     * @param stdClass $user User (needs id).
     * @return bool
     */
    public static function is_creator(stdClass $googlemeet, stdClass $user): bool {
        if (empty($user->id)) {
            return false;
        }
        $recorded = (int)($googlemeet->creatoruserid ?? 0);
        if ($recorded > 0) {
            return $recorded === (int)$user->id;
        }
        $creator = self::resolve($googlemeet);
        return $creator !== null && (int)$creator->id === (int)$user->id;
    }

    /**
     * Whether two e-mail addresses are the same, ignoring case and surrounding spaces.
     *
     * @param string $a First address.
     * @param string $b Second address.
     * @return bool
     */
    public static function same_email(string $a, string $b): bool {
        return \core_text::strtolower(trim($a)) === \core_text::strtolower(trim($b));
    }
}
