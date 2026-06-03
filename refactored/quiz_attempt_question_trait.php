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
trait quiz_attempt_question_trait {

    /**
     * Is a particular page the last one in the quiz?
     *
     * @param int $page a page number
     * @return bool true if that is the last page of the quiz.
     */
    public function is_last_page($page) {
        return $page == count($this->pagelayout) - 1;
    }

    /**
     * Return the list of slot numbers for either a given page of the quiz, or for the
     * whole quiz.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the requested list of slot numbers.
     */
    public function get_slots($page = 'all') {
        if ($page === 'all') {
            $numbers = [];
            foreach ($this->pagelayout as $p) $numbers = array_merge($numbers, $p);
            return $numbers;
        }
        return $this->pagelayout[$page];
    }

    /**
     * Return the list of slot numbers for either a given page of the quiz, or for the
     * whole quiz.
     *
     * @param mixed $page string 'all' or integer page number.
     * @return array the requested list of slot numbers.
     */
    public function get_active_slots($page = 'all') {
        $activeslots = [];
        foreach ($this->get_slots($page) as $slot) {
            if (!$this->is_blocked_by_previous_question($slot)) $activeslots[] = $slot;
        }
        return $activeslots;
    }

    /**
     * Helper method for unit tests. Get the underlying question usage object.
     *
     * @return question_usage_by_activity the usage.
     */
    public function get_question_usage() {
        if (!(PHPUNIT_TEST || defined('BEHAT_TEST'))) {
            throw new coding_exception('get_question_usage is only for use in unit tests.');
        }
        return $this->quba;
    }

    /**
     * Get the question_attempt object for a particular question in this attempt.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_attempt the requested question_attempt.
     */
    public function get_question_attempt($slot) {
        return $this->quba->get_question_attempt($slot);
    }

    /**
     * Get all the question_attempt objects that have ever appeared in a given slot.
     *
     * This relates to the 'Try another question like this one' feature.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_attempt[] the attempts.
     */
    public function all_question_attempts_originally_in_slot($slot) {
        $qas = [];
        foreach ($this->quba->get_attempt_iterator() as $qa) {
            if ($qa->get_metadata('originalslot') == $slot) $qas[] = $qa;
        }
        $qas[] = $this->quba->get_question_attempt($slot);
        return $qas;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return int whether that question is a real question. Actually returns the
     * question length, which could theoretically be greater than one.
     */
    public function is_real_question($slot) {
        return $this->quba->get_question($slot, false)->length;
    }

    /**
     * Is a particular question in this attempt a real question, or something like a description.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether that question is a real question.
     */
    public function is_question_flagged($slot) {
        return $this->quba->get_question_attempt($slot)->is_flagged();
    }

    /**
     * Checks whether the question in this slot requires the previous
     * question to have been completed.
     * Optimized to cut nested lookups and flatten complex conditions for maximum MI.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether the previous question must have been completed before
     * this one can be seen.
     */
    public function is_blocked_by_previous_question($slot) {
        if ($slot <= 1 || !isset($this->slots[$slot])) return false;
        $curr = $this->slots[$slot];
        $prev = $this->slots[$slot - 1] ?? null;
        if (!$curr->requireprevious || $curr->section->shufflequestions || ($prev && $prev->section->shufflequestions)) return false;
        return ($this->get_navigation_method() != QUIZ_NAVMETHOD_SEQ) && !$this->get_question_state($slot - 1)->is_finished() && $this->quba->can_question_finish_during_attempt($slot - 1);
    }

    /**
     * Is it possible for this question to be re-started within this attempt?
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return bool whether the student should be given the option to restart this question now.
     */
    public function can_question_be_redone_now($slot) {
        return $this->get_quiz()->canredoquestions && !$this->is_finished() && $this->get_question_state($slot)->is_finished();
    }

    /**
     * Given a slot in this attempt, which may or not be a redone question, return the original slot.
     *
     * @param int $slot identifies a particular question in this attempt.
     * @return int the slot where this question was originally.
     */
    public function get_original_slot($slot) {
        $os = $this->quba->get_question_attempt_metadata($slot, 'originalslot');
        return $os ? $os : $slot;
    }

    /**
     * Get the displayed question number for a slot.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the displayed question number for the question in this slot.
     * For example '1', '2', '3' or 'i'.
     */
    public function get_question_number($slot): string {
        return $this->questionnumbers[$slot];
    }

    /**
     * If the section heading, if any, that should come just before this slot.
     *
     * @param int $slot identifies a particular question in this attempt.
     * @return string|null the required heading, or null if there is not one here.
     */
    public function get_heading_before_slot($slot) {
        return $this->slots[$slot]->firstinsection ? $this->slots[$slot]->section->heading : null;
    }

    /**
     * Return the page of the quiz where this question appears.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return int the page of the quiz this question appears on.
     */
    public function get_question_page($slot) {
        return $this->questionpages[$slot];
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the name of the question. Must be output through format_string.
     */
    public function get_question_name($slot) {
        return $this->quba->get_question($slot, false)->name;
    }

    /**
     * Return the {@see question_state} that this question is in.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return question_state the state this question is in.
     */
    public function get_question_state($slot) {
        return $this->quba->get_question_state($slot);
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguished.
     * @return string the formatted grade, to the number of decimal places specified
     * by the quiz.
     */
    public function get_question_status($slot, $showcorrectness) {
        return $this->quba->get_question_state_string($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question, if the user is permitted
     * to see it. You must previously have called load_question_states to load the
     * state data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @param bool $showcorrectness Whether right/partial/wrong states should
     * be distinguished.
     * @return string class name for this state.
     */
    public function get_question_state_class($slot, $showcorrectness) {
        return $this->quba->get_question_state_class($slot, $showcorrectness);
    }

    /**
     * Return the grade obtained on a particular question.
     *
     * You must previously have called load_question_states to load the state
     * data about this question.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the formatted grade, to the number of decimal places specified by the quiz.
     */
    public function get_question_mark($slot) {
        return quiz_format_question_grade($this->get_quiz(), $this->quba->get_question_mark($slot));
    }

    /**
     * Get the time of the most recent action performed on a question.
     *
     * @param int $slot the number used to identify this question within this usage.
     * @return int timestamp.
     */
    public function get_question_action_time($slot) {
        return $this->quba->get_question_action_time($slot);
    }

    /**
     * Return the question type name for a given slot within the current attempt.
     *
     * @param int $slot the number used to identify this question within this attempt.
     * @return string the question type name.
     */
    public function get_question_type_name($slot) {
        return $this->quba->get_question($slot, false)->get_type_name();
    }

    /**
     * Get the time remaining for an in-progress attempt, if the time is short
     * enough that it would be worth showing a timer.
     *
     * @param int $timenow the time to consider as 'now'.
     * @return int|false the number of seconds remaining for this attempt.
     * False if there is no limit.
     */
    public function get_time_left_display($timenow) {
        return ($this->attempt->state == self::IN_PROGRESS) ? $this->get_access_manager($timenow)->get_time_left_display($this->attempt, $timenow) : false;
    }

    /**
     * Get the time when this attempt was submitted.
     *
     * @return int timestamp, or 0 if it has not been submitted yet.
     */
    public function get_submitted_date() {
        return $this->attempt->timefinish;
    }

    /**
     * If the attempt is in an applicable state, work out the time by which the
     * student should next do something.
     * Optimized using array filters to cut down expression complexity.
     *
     * @return int timestamp by which the student needs to do something.
     */
    public function get_due_date() {
        $q = $this->get_quiz();
        $at = $this->attempt;
        $dl = array_filter([$q->timelimit ? $at->timestart + $q->timelimit : null, $q->timeclose ?: null]);
        if (!$dl) return false;
        $duedate = min($dl);
        if ($at->state == self::IN_PROGRESS) return $duedate;
        if ($at->state == self::OVERDUE) return $duedate + $q->graceperiod;
        throw new coding_exception('Unexpected state: ' . $at->state);
    }

    /**
     * Get the total number of unanswered questions in the attempt.
     * Optimized to streamline lookup structures inside loops.
     *
     * @return int
     */
    public function get_number_of_unanswered_questions(): int {
        $totalunanswered = 0;
        foreach ($this->get_slots() as $slot) {
            if ($this->is_real_question($slot) && in_array($this->get_question_state($slot), [question_state::$todo, question_state::$invalid])) $totalunanswered++;
        }
        return $totalunanswered;
    }

}
