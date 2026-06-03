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
trait quiz_attempt_getters_trait {

    /**
     * Get the raw quiz settings object.
     *
     * @return stdClass
     */
    public function get_quiz() {
        return $this->quizobj->get_quiz();
    }

    /**
     * Get the {@see seb_quiz_settings} object for this quiz.
     *
     * @return quiz_settings
     */
    public function get_quizobj() {
        return $this->quizobj;
    }

    /**
     * Git the id of the course this quiz belongs to.
     *
     * @return int the course id.
     */
    public function get_courseid() {
        return $this->quizobj->get_courseid();
    }

    /**
     * Get the course settings object.
     *
     * @return stdClass the course settings object.
     */
    public function get_course() {
        return $this->quizobj->get_course();
    }

    /**
     * Get the quiz id.
     *
     * @return int the quiz id.
     */
    public function get_quizid() {
        return $this->quizobj->get_quizid();
    }

    /**
     * Get the name of this quiz.
     *
     * @return string Quiz name, directly from the database (format_string must be called before output).
     */
    public function get_quiz_name() {
        return $this->quizobj->get_quiz_name();
    }

    /**
     * Get the quiz navigation method.
     *
     * @return int QUIZ_NAVMETHOD_FREE or QUIZ_NAVMETHOD_SEQ.
     */
    public function get_navigation_method() {
        return $this->quizobj->get_navigation_method();
    }

    /**
     * Get the course_module for this quiz.
     *
     * @return cm_info the course_module object.
     */
    public function get_cm() {
        return $this->quizobj->get_cm();
    }

    /**
     * Get the course-module id.
     *
     * @return int the course_module id.
     */
    public function get_cmid() {
        return $this->quizobj->get_cmid();
    }

    /**
     * Get the quiz context.
     *
     * @return context_module the context of the quiz this attempt belongs to.
     */
    public function get_context(): context_module {
        return $this->quizobj->get_context();
    }

    /**
     * Is the current user is someone who previews the quiz, rather than attempting it?
     *
     * @return bool true user is a preview user. False, if they can do real attempts.
     */
    public function is_preview_user() {
        return $this->quizobj->is_preview_user();
    }

    /**
     * Get the number of attempts the user is allowed at this quiz.
     *
     * @return int the number of attempts allowed at this quiz (0 = infinite).
     */
    public function get_num_attempts_allowed() {
        return $this->quizobj->get_num_attempts_allowed();
    }

    /**
     * Get the number of quizzes in the quiz attempt.
     *
     * @return int number pages.
     */
    public function get_num_pages() {
        return count($this->pagelayout);
    }

    /**
     * Get the access_manager for this quiz attempt.
     *
     * @param int $timenow the current time as a unix timestamp.
     * @return access_manager and instance of the access_manager class
     * for this quiz at this time.
     */
    public function get_access_manager($timenow) {
        return $this->quizobj->get_access_manager($timenow);
    }

    /**
     * Get the id of this attempt.
     *
     * @return int the attempt id.
     */
    public function get_attemptid() {
        return $this->attempt->id;
    }

    /**
     * Get the question-usage id corresponding to this quiz attempt.
     *
     * @return int the attempt unique id.
     */
    public function get_uniqueid() {
        return $this->attempt->uniqueid;
    }

    /**
     * Get the raw quiz attempt object.
     *
     * @return stdClass the row from the quiz_attempts table.
     */
    public function get_attempt() {
        return $this->attempt;
    }

    /**
     * Get the attempt number.
     *
     * @return int the number of this attempt (is it this user's first, second, ... attempt).
     */
    public function get_attempt_number() {
        return $this->attempt->attempt;
    }

    /**
     * Get the state of this attempt.
     *
     * @return string {@see IN_PROGRESS}, {@see FINISHED}, {@see OVERDUE} or {@see ABANDONED}.
     */
    public function get_state() {
        return $this->attempt->state;
    }

    /**
     * Get the id of the user this attempt belongs to.
     * @return int user id.
     */
    public function get_userid() {
        return $this->attempt->userid;
    }

    /**
     * Get the current page of the attempt
     * @return int page number.
     */
    public function get_currentpage() {
        return $this->attempt->currentpage;
    }

    /**
     * Compute the grade and maximum grade for each grade item, for this attempt.
     *
     * @return grade_out_of[] the grade for each item where the total grade is not zero.
     * ->name will be set to the grade item name. Must be output through {@see format_string()}.
     */
    public function get_grade_item_totals(): array {
        if ($this->gradeitemmarks !== null) return $this->gradeitemmarks;
        if ($this->quba !== null) return $this->gradecalculator->compute_grade_item_totals($this->quba);
        throw new coding_exception('To call get_grade_item_totals, you must either have ->quba set or computed totals.');
    }

    /**
     * Set the total grade for each grade_item for this quiz.
     *
     * You only need to do this if the instance of this class was created with $loadquestions false.
     * Typically, you will have got the grades from {@see grade_calculator::compute_grade_item_totals_for_attempts()}.
     *
     * @param grade_out_of[] $grades same form as {@see grade_calculator::compute_grade_item_totals()} would return.
     */
    public function set_grade_item_totals(array $grades): void {
        $this->gradeitemmarks = $grades;
    }

    /**
     * Get the total number of marks that the user had scored on all the questions.
     *
     * @return float
     */
    public function get_sum_marks() {
        return $this->attempt->sumgrades;
    }

    /**
     * Has this attempt been finished?
     *
     * States {@see FINISHED} and {@see ABANDONED} are both considered finished in this state.
     * Other states are not.
     *
     * @return bool
     */
    public function is_finished() {
        return in_array($this->attempt->state, [self::FINISHED, self::ABANDONED]);
    }

    /**
     * Is this attempt a preview?
     *
     * @return bool true if it is.
     */
    public function is_preview() {
        return $this->attempt->preview;
    }

    /**
     * Does this attempt belong to the current user?
     *
     * @return bool true => own attempt/preview. false => reviewing someone else's.
     */
    public function is_own_attempt() {
        global $USER;
        return $this->attempt->userid == $USER->id;
    }

    /**
     * Is this attempt is a preview belonging to the current user.
     *
     * @return bool true if it is.
     */
    public function is_own_preview() {
        return $this->is_own_attempt() && $this->is_preview_user() && $this->attempt->preview;
    }

    /**
     * Get where we are time-wise in relation to this attempt and the quiz settings.
     *
     * @return int one of {@see display_options::DURING}, {@see display_options::IMMEDIATELY_AFTER},
     * {@see display_options::LATER_WHILE_OPEN} or {@see display_options::AFTER_CLOSE}.
     */
    public function get_attempt_state() {
        return quiz_attempt_state($this->get_quiz(), $this->attempt);
    }

}
