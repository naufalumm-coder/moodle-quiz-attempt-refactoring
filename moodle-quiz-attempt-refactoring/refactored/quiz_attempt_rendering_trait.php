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
trait quiz_attempt_rendering_trait {

    /**
     * If $reviewoptions->attempt is false, meaning that students can't review this
     * attempt at the moment, return an appropriate string explaining why.
     *
     * @param bool $short if true, return a shorter string.
     * @return string an appropriate message.
     */
    public function cannot_review_message($short = false) {
        return $this->quizobj->cannot_review_message($this->get_attempt_state(), $short, $this->attempt->timefinish);
    }

    /**
     * Initialise the JS etc. required all the questions on a page.
     * Optimized to lower variable mutations.
     *
     * @param int|string $page a page number, or 'all'.
     * @param bool $showall if true, forces page number to all.
     * @return string HTML to output - mostly obsolete, will probably be an empty string.
     */
    public function get_html_head_contributions($page = 'all', $showall = false) {
        $result = '';
        foreach ($this->get_slots($showall ? 'all' : $page) as $slot) {
            $result .= $this->quba->render_question_head_html($slot);
        }
        return $result . question_engine::initialise_js();
    }

    /**
     * Initialise the JS etc. required by one question.
     *
     * @param int $slot the question slot number.
     * @return string HTML to output - but this is mostly obsolete. Will probably be an empty string.
     */
    public function get_question_html_head_contributions($slot) {
        return $this->quba->render_question_head_html($slot) . question_engine::initialise_js();
    }

    /**
     * Print the HTML for the start new preview button, if the current user
     * is allowed to see one.
     *
     * @return string HTML for the button.
     */
    public function restart_preview_button() {
        global $OUTPUT;
        return ($this->is_preview() && $this->is_preview_user()) ? $OUTPUT->single_button(new moodle_url($this->start_attempt_url(), ['forcenew' => true]), get_string('startnewpreview', 'quiz')) : '';
    }

    /**
     * Generate the HTML that displays the question in its current state, with
     * the appropriate display options.
     *
     * @param int $slot identifies the question in the attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param renderer $renderer the quiz renderer.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question($slot, $reviewing, renderer $renderer, $thispageurl = null) {
        if ($this->is_blocked_by_previous_question($slot)) {
            $placeholderqa = $this->make_blocked_question_placeholder($slot);
            $do = $this->get_display_options($reviewing);
            $do->manualcomment = $do->history = $do->versioninfo = question_display_options::HIDDEN;
            $do->readonly = true;
            return html_writer::div($placeholderqa->render($do, $this->get_question_number($this->get_original_slot($slot))), 'mod_quiz-blocked_question_warning');
        }
        return $this->render_question_helper($slot, $reviewing, $thispageurl, $renderer, null);
    }

    /**
     * Helper used by {@see render_question()} and {@see render_question_at_step()}.
     *
     * @param int $slot identifies the question in the attempt.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @param renderer $renderer the quiz renderer.
     * @param int|null $seq the seq number of the past state to display.
     * @return string HTML fragment.
     */
    protected function render_question_helper($slot, $reviewing, $thispageurl, renderer $renderer, $seq) {
        $os = $this->get_original_slot($slot);
        $do = $this->get_display_options_with_edit_link($reviewing, $slot, $thispageurl);
        $originalmaxmark = $this->backup_and_adjust_max_mark($slot, $os);
        if ($this->can_question_be_redone_now($slot)) $do->extrainfocontent = $renderer->redo_question_button($slot, $do->readonly);
        if ($do->history && $do->questionreviewlink && ($links = $this->links_to_other_redos($slot, $do->questionreviewlink))) {
            $do->extrahistorycontent = html_writer::tag('p', get_string('redoesofthisquestion', 'quiz', $renderer->render($links)));
        }
        $output = ($seq === null) ? $this->quba->render_question($slot, $do, $this->get_question_number($os)) : $this->quba->render_question_at_step($slot, $seq, $do, $this->get_question_number($os));
        $this->restore_max_mark($slot, $os, $originalmaxmark);
        return $output;
    }

    /**
     * Temporarily adjust max marks for redone slots.
     * Helper for render_question_helper() to clear sequential checks.
     */
    protected function backup_and_adjust_max_mark($slot, $originalslot) {
        if ($slot == $originalslot) return null;
        $m = $this->get_question_attempt($slot)->get_max_mark();
        $this->get_question_attempt($slot)->set_max_mark($this->get_question_attempt($originalslot)->get_max_mark());
        return $m;
    }

    /**
     * Restore original max marks state.
     * Helper for render_question_helper().
     */
    protected function restore_max_mark($slot, $originalslot, $originalmaxmark) {
        if ($slot != $originalslot) $this->get_question_attempt($slot)->set_max_mark($originalmaxmark);
    }

    /**
     * Create a fake question to be displayed in place of a question that is blocked
     * until the previous question has been answered.
     *
     * @param int $slot int slot number of the question to replace.
     * @return question_attempt the placeholder question attempt.
     */
    protected function make_blocked_question_placeholder($slot) {
        $pqa = new question_attempt($this->create_placeholder_question_definition($this->get_question_attempt($slot)->get_question(false), $slot), $this->quba->get_id(), null, $this->quba->get_question_max_mark($slot));
        $pqa->set_slot($slot);
        $pqa->start($this->get_quiz()->preferredbehaviour, 1);
        $pqa->set_flagged($this->is_question_flagged($slot));
        return $pqa;
    }

    /**
     * Build the explicit question definition object for a blocked placeholder.
     * Helper for make_blocked_question_placeholder() to optimize Maintainability Index.
     *
     * @param object $replacedquestion The context question being hidden.
     * @param int $slot Slot index.
     * @return qtype_description_question
     */
    protected function create_placeholder_question_definition($replacedquestion, $slot) {
        question_bank::load_question_definition_classes('description');
        $q = new qtype_description_question();
        $q->id = $replacedquestion->id;
        $q->category = $q->timecreated = $q->timemodified = $q->createdby = $q->modifiedby = null;
        $q->parent = $q->penalty = 0;
        $q->qtype = question_bank::get_qtype('description');
        $q->name = $q->generalfeedback = $q->stamp = '';
        $q->questiontext = get_string('questiondependsonprevious', 'quiz');
        $q->questiontextformat = FORMAT_HTML;
        $q->defaultmark = $this->quba->get_question_max_mark($slot);
        $q->length = $replacedquestion->length;
        $q->status = \core_question\local\bank\question_version_status::QUESTION_STATUS_READY;
        return $q;
    }

    /**
     * Like {@see render_question()} but displays the question at the past step
     * indicated by $seq, rather than showing the latest step.
     *
     * @param int $slot the slot number of a question in this quiz attempt.
     * @param int $seq the seq number of the past state to display.
     * @param bool $reviewing is the being printed on an attempt or a review page.
     * @param renderer $renderer the quiz renderer.
     * @param moodle_url $thispageurl the URL of the page this question is being printed on.
     * @return string HTML for the question in its current state.
     */
    public function render_question_at_step($slot, $seq, $reviewing, renderer $renderer, $thispageurl = null) {
        return $this->render_question_helper($slot, $reviewing, $thispageurl, $renderer, $seq);
    }

    /**
     * Wrapper round print_question from lib/questionlib.php.
     *
     * @param int $slot the id of a question in this quiz attempt.
     * @return string HTML of the question.
     */
    public function render_question_for_commenting($slot) {
        $options = $this->get_display_options(true);
        $options->generalfeedback = question_display_options::HIDDEN;
        $options->manualcomment = question_display_options::EDITABLE;
        return $this->quba->render_question($slot, $options, $this->get_question_number($slot));
    }

    /**
     * Get the navigation panel object for this attempt.
     *
     * @param renderer $output the quiz renderer to use to output things.
     * @param string $panelclass The type of parameter panel.
     * @param int $page the current page number.
     * @param bool $showall whether we are showing the whole quiz on one page. (Used by review.php.)
     * @return block_contents the requested object.
     */
    public function get_navigation_panel(renderer $output, $panelclass, $page, $showall = false) {
        $bc = new block_contents();
        $bc->attributes = ['id' => 'mod_quiz_navblock', 'role' => 'navigation'];
        $bc->title = get_string('quiznavigation', 'quiz');
        $bc->content = $output->navigation_panel(new $panelclass($this, $this->get_display_options(true), $page, $showall));
        return $bc;
    }

    /**
     * Return an array of variant URLs to other attempts at this quiz.
     *
     * The $url passed in must contain an attempt parameter.
     *
     * The {@see links_to_other_attempts} object returned contains an
     * array with keys that are the attempt number, 1, 2, 3.
     * The array values are either a {@see moodle_url} with the attempt parameter
     * updated to point to the attempt id of the other attempt, or null corresponding
     * to the current attempt number.
     *
     * @param moodle_url $url a URL.
     * @return links_to_other_attempts|bool containing array int => null|moodle_url.
     * False if none.
     */
    public function links_to_other_attempts(moodle_url $url) {
        $attempts = quiz_get_user_attempts($this->get_quiz()->id, $this->attempt->userid, 'all');
        if (count($attempts) <= 1) return false;
        $links = new links_to_other_attempts();
        foreach ($attempts as $at) {
            $links->links[$at->attempt] = ($at->id == $this->attempt->id) ? null : new moodle_url($url, ['attempt' => $at->id]);
        }
        return $links;
    }

    /**
     * Return an array of variant URLs to other redos of the question in a particular slot.
     *
     * The $url passed in must contain a slot parameter.
     *
     * The {@see links_to_other_attempts} object returned contains an
     * array with keys that are the redo number, 1, 2, 3.
     * The array values are either a {@see moodle_url} with the slot parameter
     * updated to point to the slot that has that redo of this question; or null
     * corresponding to the redo identified by $slot.
     *
     * @param int $slot identifies a question in this attempt.
     * @param moodle_url $baseurl the base URL to modify to generate each link.
     * @return links_to_other_attempts|null containing array int => null|moodle_url,
     * or null if the question in this slot has not been redone.
     */
    public function links_to_other_redos($slot, moodle_url $baseurl) {
        $qas = $this->all_question_attempts_originally_in_slot($this->get_original_slot($slot));
        if (count($qas) <= 1) return null;
        $links = new links_to_other_attempts();
        $index = 1;
        foreach ($qas as $qa) {
            $qs = $qa->get_slot();
            $links->links[$index] = ($qs == $slot) ? null : new action_link($u = new moodle_url($baseurl, ['slot' => $qs]), $index, new popup_action('click', $u, 'reviewquestion', ['width' => 450, 'height' => 650]), ['title' => get_string('reviewresponse', 'question')]);
            $index++;
        }
        return $links;
    }

}
