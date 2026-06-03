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
trait quiz_attempt_core_trait {

    /**
     * Used by {create()} and {create_from_usage_id()}.
     *
     * @param array $conditions passed to $DB->get_record('quiz_attempts', $conditions).
     * @return quiz_attempt the desired instance of this class.
     */
    protected static function create_helper($conditions) {
        global $DB;

        $attempt = $DB->get_record('quiz_attempts', $conditions, '*', MUST_EXIST);
        $quiz = access_manager::load_quiz_and_settings($attempt->quiz);
        $course = get_course($quiz->course);
        $cm = get_coursemodule_from_instance('quiz', $quiz->id, $course->id, false, MUST_EXIST);

        // Update quiz with override information.
        $quiz = quiz_update_effective_access($quiz, $attempt->userid);

        return new quiz_attempt($attempt, $quiz, $cm, $course);
    }

    /**
     * Static function to create a new quiz_attempt object given an attemptid.
     *
     * @param int $attemptid the attempt id.
     * @return quiz_attempt the new quiz_attempt object
     */
    public static function create($attemptid) {
        return self::create_helper(['id' => $attemptid]);
    }

    /**
     * Static function to create a new quiz_attempt object given a usage id.
     *
     * @param int $usageid the attempt usage id.
     * @return quiz_attempt the new quiz_attempt object
     */
    public static function create_from_usage_id($usageid) {
        return self::create_helper(['uniqueid' => $usageid]);
    }

    /**
     * Get a human-readable name for one of the quiz attempt states.
     *
     * @param string $state one of the state constants like IN_PROGRESS.
     * @return string the human-readable state name.
     */
    public static function state_name($state) {
        return quiz_attempt_state_name($state);
    }

    /**
     * This method can be called later if the object was constructed with $loadquestions = false.
     */
    public function load_questions() {
        global $DB;

        if (isset($this->quba)) {
            throw new coding_exception('This quiz attempt has already had the questions loaded.');
        }

        $this->quba = question_engine::load_questions_usage_by_activity($this->attempt->uniqueid);
        $this->slots = $DB->get_records('quiz_slots', ['quizid' => $this->get_quizid()],
                'slot', 'slot, id, requireprevious, displaynumber, quizgradeitemid');
        $this->sections = array_values($DB->get_records('quiz_sections',
                ['quizid' => $this->get_quizid()], 'firstslot'));

        $this->link_sections_and_slots();
        $this->determine_layout();
        $this->number_questions();
    }

    /**
     * Preload all attempt step users to show in Response history.
     */
    public function preload_all_attempt_step_users(): void {
        $this->quba->preload_all_step_users();
    }

}
