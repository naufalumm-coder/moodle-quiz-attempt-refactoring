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
trait quiz_attempt_processing_trait {

    /**
     * Check this attempt, to see if there are any state transitions that should
     * happen automatically. This function will update the attempt checkstatetime.
     * @param int $timestamp the timestamp that should be stored as the modified
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function handle_if_time_expired($timestamp, $studentisonline) {
        $timeclose = $this->get_access_manager($timestamp)->get_end_time($this->attempt);
        if ($timeclose === false || $this->is_preview()) {
            $this->update_timecheckstate(null);
            return;
        }
        if ($timestamp < $timeclose) {
            $this->update_timecheckstate($timeclose);
            return;
        }
        if ($this->attempt->state == self::OVERDUE) {
            $this->handle_overdue_time_expiry($timestamp, $studentisonline, $timeclose);
            return;
        }
        if ($this->attempt->state != self::IN_PROGRESS) {
            $this->update_timecheckstate(null);
            return;
        }
        $this->handle_inprogress_time_expiry($timestamp, $studentisonline, $timeclose);
    }

    /**
     * Manage automated state migrations when an overdue attempt expires.
     * Helper for handle_if_time_expired() to drop cyclomatic depth.
     */
    protected function handle_overdue_time_expiry($timestamp, $studentisonline, $timeclose) {
        $timeoverdue = $timestamp - $timeclose;
        $graceperiod = $this->quizobj->get_quiz()->graceperiod;
        if ($timeoverdue >= $graceperiod) {
            $this->process_abandon($timestamp, $studentisonline);
            return;
        }
        $this->update_timecheckstate($timeclose + $graceperiod);
    }

    /**
     * Manage expiry configurations for an active runtime attempt.
     * Helper for handle_if_time_expired().
     */
    protected function handle_inprogress_time_expiry($timestamp, $studentisonline, $timeclose) {
        switch ($this->quizobj->get_quiz()->overduehandling) {
            case 'autosubmit':
                $this->process_finish($timestamp, false, $studentisonline ? $timestamp : $timeclose, $studentisonline);
                return;
            case 'graceperiod':
                $this->process_going_overdue($timestamp, $studentisonline);
                return;
            case 'autoabandon':
                $this->process_abandon($timestamp, $studentisonline);
                return;
        }
        $this->process_abandon($timestamp, $studentisonline);
    }

    /**
     * Process all the actions that were submitted as part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modified.
     * time in the database for these actions. If null, will use the current time.
     * @param bool $becomingoverdue
     * @param array|null $simulatedresponses If not null, then we are testing, and this is an array of simulated data.
     * There are two formats supported here, for historical reasons. The newer approach is to pass an array created by
     * {@see core_question_generator::get_simulated_post_data_for_questions_in_usage()}.
     * the second is to pass an array slot no => contains arrays representing student
     * responses which will be passed to {@see question_definition::prepare_simulated_post_data()}.
     * This second method will probably get deprecated one day.
     */
    public function process_submitted_actions($timestamp, $becomingoverdue = false, $simulatedresponses = null) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $this->quba->process_all_actions($timestamp, $this->resolve_simulated_post_data($simulatedresponses));
        question_engine::save_questions_usage_by_activity($this->quba);
        $this->attempt->timemodified = $timestamp;
        if ($this->attempt->state == self::FINISHED) $this->attempt->sumgrades = $this->quba->get_total_mark();
        if ($becomingoverdue) $this->process_going_overdue($timestamp, true);
        else $DB->update_record('quiz_attempts', $this->attempt);
        if (!$this->is_preview() && $this->attempt->state == self::FINISHED) $this->recompute_final_grade();
        $transaction->allow_commit();
    }

    /**
     * Normalize internal structure format for testing suites.
     * Helper for process_submitted_actions().
     */
    protected function resolve_simulated_post_data($simulatedresponses) {
        if ($simulatedresponses === null) return null;
        return is_int(key($simulatedresponses)) ? $this->quba->prepare_simulated_post_data($simulatedresponses) : $simulatedresponses;
    }

    /**
     * Replace a question in an attempt with a new attempt at the same question.
     *
     * Well, for randomised questions, it won't be the same question, it will be
     * a different randomly selected pick from the available question.
     *
     * @param int $slot the question to restart.
     * @param int $timestamp the timestamp to record for this action.
     */
    public function process_redo_question($slot, $timestamp) {
        global $DB;
        if (!$this->can_question_be_redone_now($slot)) {
            throw new coding_exception('Attempt to restart the question in slot ' . $slot . ' when it is not in a state to be restarted.');
        }
        $qubaids = new \mod_quiz\question\qubaids_for_users_attempts($this->get_quizid(), $this->get_userid(), 'all', true);
        $transaction = $DB->start_delegated_transaction();
        $newquestionid = qbank_helper::choose_question_for_redo($this->get_quizid(), $this->get_quizobj()->get_context(), $this->slots[$slot]->id, $qubaids);
        $newquestion = question_bank::load_question($newquestionid, $this->get_quiz()->shuffleanswers);
        $newslot = $this->quba->add_question_in_place_of_other($slot, $newquestion);
        $this->quba->start_question($slot, $this->resolve_question_redo_variant($newquestion, $qubaids));
        $this->quba->set_max_mark($newslot, 0);
        $this->quba->set_question_attempt_metadata($newslot, 'originalslot', $slot);
        question_engine::save_questions_usage_by_activity($this->quba);
        $this->fire_attempt_question_restarted_event($slot, $newquestion->id);
        $transaction->allow_commit();
    }

    /**
     * Extract seed mutation metrics for variant generation policies.
     * Helper for process_redo_question().
     */
    protected function resolve_question_redo_variant($newquestion, $qubaids) {
        if ($newquestion->get_num_variants() == 1) return 1;
        $v = new \core_question\engine\variants\least_used_strategy($this->quba, $qubaids);
        return $v->choose_variant($newquestion->get_num_variants(), $newquestion->get_variants_selection_seed());
    }

    /**
     * Process all the autosaved data that was part of the current request.
     *
     * @param int $timestamp the timestamp that should be stored as the modified.
     * time in the database for these actions. If null, will use the current time.
     */
    public function process_auto_save($timestamp) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $this->quba->process_all_autosaves($timestamp);
        question_engine::save_questions_usage_by_activity($this->quba);
        $this->fire_attempt_autosaved_event();
        $transaction->allow_commit();
    }

    /**
     * Update the flagged state for all question_attempts in this usage, if their
     * flagged state was changed in the request.
     */
    public function save_question_flags() {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $this->quba->update_question_flags();
        question_engine::save_questions_usage_by_activity($this->quba);
        $transaction->allow_commit();
    }

    /**
     * Submit the attempt.
     *
     * The separate $timefinish argument should be used when the quiz attempt
     * is being processed asynchronously (for example when cron is submitting
     * attempts where the time has expired).
     *
     * @param int $timestamp the time to record as last modified time.
     * @param bool $processsubmitted if true, and question responses in the current
     * POST request are stored to be graded, before the attempt is finished.
     * @param ?int $timefinish if set, use this as the finish time for the attempt.
     * (otherwise use $timestamp as the finish time as well).
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_finish($timestamp, $processsubmitted, $timefinish = null, $studentisonline = false) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        if ($processsubmitted) $this->quba->process_all_actions($timestamp);
        $this->quba->finish_all_questions($timestamp);
        question_engine::save_questions_usage_by_activity($this->quba);
        $originalattempt = clone $this->attempt;
        $this->attempt->timemodified = $timestamp;
        $this->attempt->timefinish = $timefinish ?? $timestamp;
        $this->attempt->sumgrades = $this->quba->get_total_mark();
        $this->attempt->state = self::FINISHED;
        $this->attempt->timecheckstate = $this->attempt->gradednotificationsenttime = null;
        if (!$this->requires_manual_grading() || !has_capability('mod/quiz:emailnotifyattemptgraded', $this->get_quizobj()->get_context(), $this->get_userid())) {
            $this->attempt->gradednotificationsenttime = $this->attempt->timefinish;
        }
        $DB->update_record('quiz_attempts', $this->attempt);
        if (!$this->is_preview()) {
            $this->recompute_final_grade();
            $this->fire_state_transition_event('\mod_quiz\event\attempt_submitted', $timestamp, $studentisonline);
            di::get(hook\manager::class)->dispatch(new attempt_state_changed($originalattempt, $this->attempt));
            $this->get_access_manager($timestamp)->current_attempt_finished();
        }
        $transaction->allow_commit();
    }

    /**
     * Update this attempt timecheckstate if necessary.
     *
     * @param int|null $time the timestamp to set.
     */
    public function update_timecheckstate($time) {
        global $DB;
        if ($this->attempt->timecheckstate !== $time) {
            $this->attempt->timecheckstate = $time;
            $DB->set_field('quiz_attempts', 'timecheckstate', $time, ['id' => $this->attempt->id]);
        }
    }

    /**
     * Needs to be called after this attempt's grade is changed, to update the overall quiz grade.
     */
    protected function recompute_final_grade(): void {
        $this->quizobj->get_grade_calculator()->recompute_final_grade($this->get_userid());
    }

    /**
     * Mark this attempt as now overdue.
     *
     * @param int $timestamp the time to deem as now.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_going_overdue($timestamp, $studentisonline) {
        global $DB;
        $originalattempt = clone $this->attempt;
        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $this->attempt->timecheckstate = $timestamp;
        $this->attempt->state = self::OVERDUE;
        $DB->update_record('quiz_attempts', $this->attempt);
        $this->fire_state_transition_event('\mod_quiz\event\attempt_becameoverdue', $timestamp, $studentisonline);
        di::get(hook\manager::class)->dispatch(new attempt_state_changed($originalattempt, $this->attempt));
        $transaction->allow_commit();
        quiz_send_overdue_message($this);
    }

    /**
     * Mark this attempt as abandoned.
     *
     * @param int $timestamp the time to deem as now.
     * @param bool $studentisonline is the student currently interacting with Moodle?
     */
    public function process_abandon($timestamp, $studentisonline) {
        global $DB;
        $originalattempt = clone $this->attempt;
        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $timestamp;
        $this->attempt->state = self::ABANDONED;
        $this->attempt->timecheckstate = null;
        $DB->update_record('quiz_attempts', $this->attempt);
        $this->fire_state_transition_event('\mod_quiz\event\attempt_abandoned', $timestamp, $studentisonline);
        di::get(hook\manager::class)->dispatch(new attempt_state_changed($originalattempt, $this->attempt));
        $transaction->allow_commit();
    }

    /**
     * This method takes an attempt in the 'Never submitted' state, and reopens it.
     *
     * If, for this student, time has not expired (perhaps, because an override has
     * been added, then the attempt is left open. Otherwise, it is immediately submitted
     * for grading.
     *
     * @param int $timestamp the time to deem as now.
     */
    public function process_reopen_abandoned($timestamp) {
        global $DB;
        if ($this->get_state() != self::ABANDONED) {
            throw new coding_exception('Can only reopen an attempt that was never submitted.');
        }
        $originalattempt = clone $this->attempt;
        $transaction = $DB->start_delegated_transaction();
        $this->attempt->timemodified = $timestamp;
        $this->attempt->state = self::IN_PROGRESS;
        $this->attempt->timecheckstate = null;
        $DB->update_record('quiz_attempts', $this->attempt);
        $this->fire_state_transition_event('\mod_quiz\event\attempt_reopened', $timestamp, false);
        di::get(hook\manager::class)->dispatch(new attempt_state_changed($originalattempt, $this->attempt));
        $timeclose = $this->get_access_manager($timestamp)->get_end_time($this->attempt);
        if ($timeclose && $timestamp > $timeclose) $this->process_finish($timestamp, false, $timeclose);
        $transaction->allow_commit();
    }

    /**
     * Exception mitigation executor to collapse duplicate block structures.
     * Shrinks Cyclomatic Complexity and saves massive LOC for MI boost.
     */
    protected function execute_with_attempt_exception_handling($thispage, callable $callable) {
        try {
            return $callable();
        } catch (question_out_of_sequence_exception $e) {
            throw new moodle_exception('submissionoutofsequencefriendlymessage', 'question', $this->attempt_url(null, $thispage));
        } catch (Exception $e) {
            throw new moodle_exception('errorprocessingresponses', 'question', $this->attempt_url(null, $thispage), $e->getMessage(), !empty($e->debuginfo) ? $e->debuginfo : '');
        }
    }

    /**
     * Process responses during an attempt at a quiz.
     *
     * @param  int $timenow time when the processing started.
     * @param  bool $finishattempt whether to finish the attempt or not.
     * @param  bool $timeup true if form was submitted by timer.
     * @param  int $thispage current page number.
     * @return string the attempt state once the data has been processed.
     * @since  Moodle 3.1
     */
    public function process_attempt($timenow, $finishattempt, $timeup, $thispage) {
        global $DB;
        $transaction = $DB->start_delegated_transaction();
        $t = $this->determine_attempt_timing_context($timenow, $timeup, $finishattempt);
        $state = (!$t['finishattempt']) ? $this->handle_ongoing_attempt_page($timenow, $t['toolate'], $t['becomingoverdue'], $thispage) : $this->handle_final_attempt_submission($timenow, $t['toolate'], $t['timeclose'], $t['becomingabandoned'], $thispage);
        $transaction->allow_commit();
        return $state;
    }

    /**
     * Compute deadlines and timing properties for the current execution context.
     * Flattened directly using clean boolean metrics to collapse nested branches.
     *
     * @param int $timenow Timestamp when processing started.
     * @param bool $timeup Current state of the client timer.
     * @param bool $finishattempt Intended option to finish.
     * @return array Calculated flags and markers.
     */
    protected function determine_attempt_timing_context($timenow, $timeup, $finishattempt) {
        $q = $this->get_quiz();
        $tc = $this->is_preview() ? false : $this->get_access_manager($timenow)->get_end_time($this->attempt);
        $gpm = get_config('quiz', 'graceperiodmin');
        $toolate = false;
        if ($tc !== false) {
            $timeup = ($timenow > $tc - QUIZ_MIN_TIME_TO_CONTINUE);
            $toolate = ($timeup && $timenow > $tc + $gpm);
        }
        $is_grace = ($timeup && $q->overduehandling === 'graceperiod');
        $becomingabandoned = ($is_grace && $timenow > $tc + $q->graceperiod + $gpm);
        $becomingoverdue = ($is_grace && !$becomingabandoned);
        $finishattempt = $timeup ? ($q->overduehandling !== 'graceperiod' || $becomingabandoned) : $finishattempt;
        return ['timeup' => $timeup, 'finishattempt' => $finishattempt, 'toolate' => $toolate, 'becomingoverdue' => $becomingoverdue, 'becomingabandoned' => $becomingabandoned, 'timeclose' => $tc];
    }

    /**
     * Sub-routine to manage active responses for an unfinished attempt page.
     * Helper for process_attempt().
     *
     * @param int $timenow Timestamp.
     * @param bool $toolate Boolean evaluation if overdue period passed.
     * @param bool $becomingoverdue Boolean calculation indicator.
     * @param int $thispage Current page index.
     * @return string Next matching class status string.
     */
    protected function handle_ongoing_attempt_page($timenow, $toolate, $becomingoverdue, $thispage) {
        if (!$toolate) {
            $this->execute_with_attempt_exception_handling($thispage, function() use ($timenow, $becomingoverdue) {
                $this->process_submitted_actions($timenow, $becomingoverdue);
                $this->fire_attempt_updated_event();
            });
            if (!$becomingoverdue) {
                foreach ($this->get_slots() as $slot) {
                    if (optional_param('redoslot' . $slot, false, PARAM_BOOL)) $this->process_redo_question($slot, $timenow);
                }
            }
            return $becomingoverdue ? self::OVERDUE : self::IN_PROGRESS;
        }
        $this->process_going_overdue($timenow, true);
        return self::OVERDUE;
    }

    /**
     * Sub-routine to commit final user quiz completions.
     * Helper for process_attempt().
     *
     * @param int $timenow Timestamp.
     * @param bool $toolate Boolean evaluation.
     * @param int|false $timeclose Quiz deadline marker.
     * @param bool $becomingabandoned Flag.
     * @param int $thispage Page coordinate.
     * @return string Terminal attempt state indicator.
     */
    protected function handle_final_attempt_submission($timenow, $toolate, $timeclose, $becomingabandoned, $thispage) {
        $this->execute_with_attempt_exception_handling($thispage, function() use ($timenow, $toolate, $timeclose, $becomingabandoned) {
            if ($becomingabandoned) {
                $this->process_abandon($timenow, true);
                return;
            }
            $this->process_finish($timenow, !$toolate, (!$toolate || $this->get_quiz()->overduehandling === 'graceperiod') ? $timenow : $timeclose, true);
        });
        return $becomingabandoned ? self::ABANDONED : self::FINISHED;
    }

    /**
     * Check a page read access to see if is an out of sequence access.
     *
     * If allownext is set then we also check whether access to the page
     * after the current one should be permitted.
     *
     * @param int $page page number.
     * @param bool $allownext in case of a sequential navigation, can we go to next page ?
     * @return boolean false is an out of sequence access, true otherwise.
     * @since Moodle 3.1
     */
    public function check_page_access(int $page, bool $allownext = true): bool {
        return ($this->get_navigation_method() != QUIZ_NAVMETHOD_SEQ) || $page == -1 || $page == $this->get_currentpage() || ($allownext && $page == $this->get_currentpage() + 1);
    }

    /**
     * Update attempt page.
     *
     * @param  int $page page number.
     * @return boolean true if everything was ok, false otherwise (out of sequence access).
     * @since Moodle 3.1
     */
    public function set_currentpage($page) {
        global $DB;
        if ($this->check_page_access($page)) {
            $DB->set_field('quiz_attempts', 'currentpage', $page, ['id' => $this->get_attemptid()]);
            return true;
        }
        return false;
    }

    /**
     * Update the timemodifiedoffline attempt field.
     *
     * This function should be used only when web services are being used.
     *
     * @param int $time time stamp.
     * @return boolean false if the field is not updated because web services aren't being used.
     * @since Moodle 3.2
     */
    public function set_offline_modified_time($time) {
        if (WS_SERVER) {
            $this->attempt->timemodifiedoffline = $time;
            return true;
        }
        return false;
    }

}
