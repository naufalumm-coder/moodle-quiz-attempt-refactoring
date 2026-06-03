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
trait quiz_attempt_question_update_trait {

    /**
     * If any questions in this attempt have changed, update the attempts.
     *
     * For now, this should only be done for previews.
     *
     * When we update the question, we keep the same question (in the case of random questions)
     * and the same variant (if this question has variants). If possible, we use regrade to
     * preserve any interaction that has been had with this question (e.g. a saved answer) but
     * if that is not possible, we put in a newly started attempt.
     */
    public function update_questions_to_new_version_if_changed(): void {
        $anychanges = false;
        foreach (qbank_helper::get_version_information_for_questions_in_attempt($this->attempt, $this->get_context()) as $si) {
            if ($this->process_single_question_version_update($si)) $anychanges = true;
        }
        if ($anychanges) $this->finalize_questions_version_update();
    }

    /**
     * Evaluation and processing of version diff state metrics on specific questions.
     * Helper for update_questions_to_new_version_if_changed().
     */
    protected function process_single_question_version_update($slotinformation) {
        if ($slotinformation->currentquestionid == $slotinformation->newquestionid) return false;
        $slot = $slotinformation->questionattemptslot;
        $nq = question_bank::load_question($slotinformation->newquestionid);
        if (empty($this->quba->validate_can_regrade_with_other_version($slot, $nq))) {
            $this->quba->regrade_question($slot, $this->get_attempt()->state == self::FINISHED, null, $nq);
        } else {
            $ov = $this->get_question_attempt($slot)->get_variant();
            $this->quba->start_question($this->quba->add_question_in_place_of_other($slot, $nq, null, false), $ov);
        }
        return true;
    }

    /**
     * Recompute overall persistent records following asset engine transformations.
     * Helper for update_questions_to_new_version_if_changed().
     */
    protected function finalize_questions_version_update() {
        global $DB;
        question_engine::save_questions_usage_by_activity($this->quba);
        if ($this->attempt->state == self::FINISHED) {
            $this->attempt->sumgrades = $this->quba->get_total_mark();
            $DB->update_record('quiz_attempts', $this->attempt);
            $this->recompute_final_grade();
        }
    }

}
