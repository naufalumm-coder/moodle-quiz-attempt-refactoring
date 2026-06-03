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
trait quiz_attempt_url_trait {

    /**
     * Get the URL of this quiz's view.php page.
     *
     * @return moodle_url quiz view url.
     */
    public function view_url() {
        return $this->quizobj->view_url();
    }

    /**
     * Get the URL to start or continue an attempt.
     *
     * @param int|null $slot which question in the attempt to go to after starting (optional).
     * @param int $page which page in the attempt to go to after starting.
     * @return moodle_url the URL of this quiz's edit page. Needs to be POSTed to with a cmid parameter.
     */
    public function start_attempt_url($slot = null, $page = -1) {
        return $this->quizobj->start_attempt_url(($page == -1 && !is_null($slot)) ? $this->get_question_page($slot) : 0);
    }

    /**
     * Generates the title of the attempt page.
     *
     * @param int $page the page number (starting with 0) in the attempt.
     * @return string attempt page title.
     */
    public function attempt_page_title(int $page): string {
        $np = $this->get_num_pages();
        if ($np > 1) {
            $a = (object)['name' => $this->get_quiz_name(), 'currentpage' => $page + 1, 'totalpages' => $np];
            return get_string('attempttitlepaged', 'quiz', $a);
        }
        return get_string('attempttitle', 'quiz', $this->get_quiz_name());
    }

    /**
     * Get the URL of a particular page within this attempt.
     *
     * @param int|null $slot if specified, the slot number of a specific question to link to.
     * @param int $page if specified, a particular page to link to. If not given deduced
     * from $slot, or goes to the first page.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return moodle_url the URL to continue this attempt.
     */
    public function attempt_url($slot = null, $page = -1, $thispage = -1) {
        return $this->page_and_question_url('attempt', $slot, $page, false, $thispage);
    }

    /**
     * Generates the title of the summary page.
     *
     * @return string summary page title.
     */
    public function summary_page_title(): string {
        return get_string('attemptsummarytitle', 'quiz', $this->get_quiz_name());
    }

    /**
     * Get the URL of the summary page of this attempt.
     *
     * @return moodle_url the URL of this quiz's summary page.
     */
    public function summary_url() {
        return new moodle_url('/mod/quiz/summary.php', ['attempt' => $this->attempt->id, 'cmid' => $this->get_cmid()]);
    }

    /**
     * Get the URL to which the attempt data should be submitted.
     *
     * @return moodle_url the URL of this quiz's summary page.
     */
    public function processattempt_url() {
        return new moodle_url('/mod/quiz/processattempt.php');
    }

    /**
     * Generates the title of the review page.
     *
     * @param int $page the page number (starting with 0) in the attempt.
     * @param bool $showall whether the review page contains the entire attempt on one page.
     * @return string title of the review page.
     */
    public function review_page_title(int $page, bool $showall = false): string {
        $np = $this->get_num_pages();
        if (!$showall && $np > 1) {
            $a = (object)['name' => $this->get_quiz_name(), 'currentpage' => $page + 1, 'totalpages' => $np];
            return get_string('attemptreviewtitlepaged', 'quiz', $a);
        }
        return get_string('attemptreviewtitle', 'quiz', $this->get_quiz_name());
    }

    /**
     * Get the URL of a particular page in the review of this attempt.
     *
     * @param int|null $slot indicates which question to link to.
     * @param int $page if specified, the URL of this particular page of the attempt, otherwise
     * the URL will go to the first page.  If -1, deduce $page from $slot.
     * @param bool|null $showall if true, the URL will be to review the entire attempt on one page,
     * and $page will be ignored. If null, a sensible default will be chosen.
     * @param int $thispage if not -1, the current page. Will cause links to other things on
     * this page to be output as only a fragment.
     * @return moodle_url the URL to review this attempt.
     */
    public function review_url($slot = null, $page = -1, $showall = null, $thispage = -1) {
        return $this->page_and_question_url('review', $slot, $page, $showall, $thispage);
    }

    /**
     * By default, should this script show all questions on one page for this attempt?
     *
     * @param string $script the script name, e.g. 'attempt', 'summary', 'review'.
     * @return bool whether show all on one page should be on by default.
     */
    public function get_default_show_all($script) {
        return $script === 'review' && count($this->questionpages) < self::MAX_SLOTS_FOR_DEFAULT_REVIEW_SHOW_ALL;
    }

    /**
     * Get a URL for a particular question on a particular page of the quiz.
     * Used by {@see attempt_url()} and {@see review_url()}.
     *
     * @param string $script e.g. 'attempt' or 'review'. Used in the URL like /mod/quiz/$script.php.
     * @param int $slot identifies the specific question on the page to jump to.
     * 0 to just use the $page parameter.
     * @param int $page -1 to look up the page number from the slot, otherwise
     * the page number to go to.
     * @param bool|null $showall if true, return a URL with showall=1, and not page number.
     * if null, then an intelligent default will be chosen.
     * @param int $thispage the page we are currently on. Links to questions on this
     * page will just be a fragment #q123. -1 to disable this.
     * @return moodle_url The requested URL.
     */
    protected function page_and_question_url($script, $slot, $page, $showall, $thispage) {
        $ds = $this->get_default_show_all($script);
        if ($showall === null && ($page == 0 || $page == -1)) $showall = $ds;
        $page = ($page == -1) ? (($slot !== null && !$showall) ? $this->get_question_page($slot) : 0) : ($showall ? 0 : $page);
        $f = ($slot === null) ? '' : (($slot == reset($this->pagelayout[$page]) && $thispage != $page) ? '#' : '#' . $this->get_question_attempt($slot)->get_outer_question_div_unique_id());
        if ($thispage == $page) return new moodle_url($f);
        $url = new moodle_url('/mod/quiz/' . $script . '.php' . $f, ['attempt' => $this->attempt->id, 'cmid' => $this->get_cmid()]);
        if ($page == 0 && $showall != $ds) $url->param('showall', (int)$showall);
        else if ($page > 0) $url->param('page', $page);
        return $url;
    }

}
