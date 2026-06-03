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
trait quiz_attempt_access_trait {

    /**
     * Is the current user allowed to review this attempt. This applies when
     * {@see is_own_attempt()} returns false.
     *
     * @return bool whether the review should be allowed.
     */
    public function is_review_allowed() {
        if (!$this->has_capability('mod/quiz:viewreports')) return false;
        $cm = $this->get_cm();
        if ($this->has_capability('moodle/site:accessallgroups') || groups_get_activity_groupmode($cm) != SEPARATEGROUPS) return true;
        $tg = groups_get_activity_allowed_groups($cm);
        $sg = groups_get_all_groups($cm->course, $this->attempt->userid, $cm->groupingid);
        return $tg && $sg && array_intersect(array_keys($tg), array_keys($sg));
    }

    /**
     * Has the student, in this attempt, engaged with the quiz in a non-trivial way?
     *
     * That is, is there any question worth a non-zero number of marks, where
     * the student has made some response that we have saved?
     *
     * @return bool true if we have saved a response for at least one graded question.
     */
    public function has_response_to_at_least_one_graded_question() {
        foreach ($this->quba->get_attempt_iterator() as $qa) {
            if ($qa->get_max_mark() > 0 && $qa->get_num_steps() > 1) return true;
        }
        return false;
    }

    /**
     * Do any questions in this attempt need to be graded manually?
     *
     * @return bool True if we have at least one question still needs manual grading.
     */
    public function requires_manual_grading(): bool {
        return $this->quba->get_total_mark() === null;
    }

    /**
     * Get extra summary information about this attempt.
     *
     * Some behaviours may be able to provide interesting summary information
     * about the attempt as a whole, and this method provides access to that data.
     * To see how this works, try setting a quiz to one of the CBM behaviours,
     * and then look at the extra information displayed at the top of the quiz
     * review page once you have submitted an attempt.
     *
     * In the return value, the array keys are identifiers of the form
     * qbehaviour_behaviourname_meaningfullkey. For qbehaviour_deferredcbm_highsummary.
     * The values are arrays with two items, title and content. Each of these
     * will be either a string, or a renderable.
     *
     * If this method is called before load_questions() is called, then an empty array is returned.
     *
     * @param question_display_options $options the display options for this quiz attempt at this time.
     * @return array as described above.
     */
    public function get_additional_summary_data(question_display_options $options) {
        return isset($this->quba) ? $this->quba->get_summary_information($options) : [];
    }

    /**
     * Get the overall feedback corresponding to a particular mark.
     *
     * @param number $grade a particular grade.
     * @return string the feedback.
     */
    public function get_overall_feedback($grade) {
        return quiz_feedback_for_grade($grade, $this->get_quiz(), $this->quizobj->get_context());
    }

    /**
     * Wrapper round the has_capability function that automatically passes in the quiz context.
     *
     * @param string $capability the name of the capability to check. For example mod/forum:view.
     * @param int|null $userid A user id. If null checks the permissions of the current user.
     * @param bool $doanything If false, ignore effect of admin role assignment.
     * @return boolean true if the user has this capability, otherwise false.
     */
    public function has_capability($capability, $userid = null, $doanything = true) {
        return $this->quizobj->has_capability($capability, $userid, $doanything);
    }

    /**
     * Wrapper round the require_capability function that automatically passes in the quiz context.
     *
     * @param string $capability the name of the capability to check. For example mod/forum:view.
     * @param int|null $userid A user id. If null checks the permissions of the current user.
     * @param bool $doanything If false, ignore effect of admin role assignment.
     */
    public function require_capability($capability, $userid = null, $doanything = true) {
        $this->quizobj->require_capability($capability, $userid, $doanything);
    }

    /**
     * Check the appropriate capability to see whether this user may review their own attempt.
     * If not, prints an error.
     */
    public function check_review_capability() {
        $cap = ($this->get_attempt_state() == display_options::IMMEDIATELY_AFTER) ? 'mod/quiz:attempt' : 'mod/quiz:reviewmyattempts';
        if ($this->has_capability($cap) || $this->has_capability('mod/quiz:viewreports') || $this->has_capability('mod/quiz:preview')) return;
        $this->require_capability($cap);
    }

    /**
     * Checks whether a user may navigate to a particular slot.
     *
     * @param int $slot the target slot (currently does not affect the answer).
     * @return bool true if the navigation should be allowed.
     */
    public function can_navigate_to($slot) {
        return ($this->attempt->state != self::OVERDUE) && ($this->get_navigation_method() == QUIZ_NAVMETHOD_FREE);
    }

    /**
     * Wrapper that the correct display_options for this quiz at the
     * moment.
     *
     * @param bool $reviewing true for options when reviewing, false for when attempting.
     * @return question_display_options the render options for this user on this attempt.
     */
    public function get_display_options($reviewing) {
        if ($reviewing) {
            if (is_null($this->reviewoptions)) {
                $this->reviewoptions = quiz_get_review_options($this->get_quiz(), $this->attempt, $this->quizobj->get_context());
                if ($this->is_own_preview()) $this->reviewoptions->attempt = true;
            }
            return $this->reviewoptions;
        }
        $options = display_options::make_from_quiz($this->get_quiz(), display_options::DURING);
        $options->flags = quiz_get_flag_option($this->attempt, $this->quizobj->get_context());
        return $options;
    }

    /**
     * Wrapper that the correct display_options for this quiz at the
     * moment.
     *
     * @param bool $reviewing true for review page, else attempt page.
     * @param int $slot which question is being displayed.
     * @param moodle_url $thispageurl to return to after the editing form is
     * submitted or cancelled. If null, no edit link will be generated.
     *
     * @return question_display_options the render options for this user on this
     * attempt, with extra info to generate an edit link, if applicable.
     */
    public function get_display_options_with_edit_link($reviewing, $slot, $thispageurl) {
        $options = clone($this->get_display_options($reviewing));
        if (!$thispageurl || !($reviewing || $this->is_preview())) return $options;
        $q = $this->quba->get_question($slot, false);
        if (!question_has_capability_on($q, 'edit', $q->category)) return $options;
        $options->editquestionparams = ['cmid' => $this->get_cmid(), 'returnurl' => $thispageurl];
        return $options;
    }

    /**
     * Check whether access should be allowed to a particular file.
     * Optimized logical scopes into an aggregated guard statement.
     *
     * @param int $slot the slot of a question in this quiz attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param int $contextid the file context id from the request.
     * @param string $component the file component from the request.
     * @param string $filearea the file area from the request.
     * @param array $args extra part components from the request.
     * @param bool $forcedownload whether to force download.
     * @return bool true if the file can be accessed.
     */
    public function check_file_access($slot, $reviewing, $contextid, $component, $filearea, $args, $forcedownload) {
        $options = $this->get_display_options($reviewing);
        if ($reviewing && (($this->is_own_attempt() && !$options->attempt) || (!$this->is_own_attempt() && !$this->is_review_allowed()))) return false;
        return $this->quba->check_file_access($slot, $options, $component, $filearea, $args, $forcedownload);
    }

}
