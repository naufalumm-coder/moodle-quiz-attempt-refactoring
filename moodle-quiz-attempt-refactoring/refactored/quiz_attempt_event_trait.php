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


namespace mod_quiz;

use action_link;
use block_contents;
use cm_info;
use coding_exception;
use context_module;
use core\di;
use core\hook;
use Exception;
use html_writer;
use mod_quiz\hook\attempt_state_changed;
use mod_quiz\output\grades\grade_out_of;
use mod_quiz\output\links_to_other_attempts;
use mod_quiz\output\renderer;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\question\display_options;
use moodle_exception;
use moodle_url;
use popup_action;
use qtype_description_question;
use question_attempt;
use question_bank;
use question_display_options;
use question_engine;
use question_out_of_sequence_exception;
use question_state;
use question_usage_by_activity;
use stdClass;

/**
 * Extracted methods from quiz_attempt to reduce class size and improve PhpMetrics MI.
 */
trait quiz_attempt_event_trait {

    /**
     * Centralized event trigger hub to drastically cut Halstead volume and redundant operands.
     * Core optimization method for target index score 50+.
     */
    protected function trigger_attempt_event($eventclass, array $other = [], $include_page = true, $quiz_snapshot = false) {
        $at = $this->attempt;
        $op = ['quizid' => $this->get_quizid()];
        if ($include_page) $op['page'] = $at->currentpage;
        $event = $eventclass::create([
            'context' => $this->quizobj->get_context(),
            'courseid' => $this->quizobj->get_courseid(),
            'objectid' => $at->id,
            'relateduserid' => $at->userid,
            'other' => array_merge($op, $other)
        ]);
        $event->add_record_snapshot('quiz_attempts', $at);
        if ($quiz_snapshot) $event->add_record_snapshot('quiz', $this->get_quiz());
        $event->trigger();
    }

    /**
     * Fire a state transition event.
     *
     * @param string $eventclass the event class name.
     * @param int $timestamp the timestamp to include in the event.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    protected function fire_state_transition_event($eventclass, $timestamp, $studentisonline) {
        global $USER;
        $this->trigger_attempt_event($eventclass, ['submitterid' => CLI_SCRIPT ? null : $USER->id, 'studentisonline' => $studentisonline], false, true);
    }

    /**
     * Trigger the attempt_viewed event.
     *
     * @since Moodle 3.1
     */
    public function fire_attempt_viewed_event() {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_viewed::class);
    }

    /**
     * Trigger the attempt_updated event.
     *
     * @return void
     */
    public function fire_attempt_updated_event(): void {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_updated::class);
    }

    /**
     * Trigger the attempt_autosaved event.
     *
     * @return void
     */
    public function fire_attempt_autosaved_event(): void {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_autosaved::class);
    }

    /**
     * Trigger the attempt_question_restarted event.
     *
     * @param int $slot Slot number
     * @param int $newquestionid New question id.
     * @return void
     */
    public function fire_attempt_question_restarted_event(int $slot, int $newquestionid): void {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_question_restarted::class, ['slot' => $slot, 'newquestionid' => $newquestionid]);
    }

    /**
     * Trigger the attempt_summary_viewed event.
     *
     * @since Moodle 3.1
     */
    public function fire_attempt_summary_viewed_event() {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_summary_viewed::class, [], false);
    }

    /**
     * Trigger the attempt_reviewed event.
     *
     * @since Moodle 3.1
     */
    public function fire_attempt_reviewed_event() {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_reviewed::class, [], false);
    }

    /**
     * Trigger the attempt manual grading completed event.
     */
    public function fire_attempt_manual_grading_completed_event() {
        $this->trigger_attempt_event(\mod_quiz\event\attempt_manual_grading_completed::class, [], false);
    }

}
